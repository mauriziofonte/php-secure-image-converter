<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;

/**
 * libvips through its command-line program vips: the preferred backend.
 *
 * Without resize, the input is read by the loader of its sniffed format, named on the command line ("vips jpegload
 * input.jpg output.webp[...]"), so that vips never picks a loader itself. With resize, "vips thumbnail" scales on
 * load to the exact size (--size force for contain and fill, --crop centre for cover); thumbnail picks the loader
 * by content, which VIPS_BLOCK_UNTRUSTED=1 limits to the loaders libvips considers safe for untrusted input
 * (ImageMagick, OpenSlide, PDF, SVG and the other "untrusted" loaders are blocked; BMP, which libvips reads only
 * through ImageMagick, is therefore not supported). libvips before 8.13 ignores the variable, so discovery checks
 * that it works: thumbnail must refuse a file that only an untrusted loader reads (a one-pixel PPM). Where it does
 * not, nothing would stop OpenSlide (priority 100) from reading a validated TIFF, and the backend declares no fit:
 * it converts without resize only, through the named loaders. The output saver follows from the output file name.
 *
 * | Setting       | Mapping                                                                              |
 * | ------------- | ------------------------------------------------------------------------------------ |
 * | quality       | Q (JPEG, WEBP, AVIF)                                                                 |
 * | effort        | WEBP effort 0 to 6 (scaled), AVIF effort 0 to 8, PNG zlib level (at least 1)         |
 * | lossless      | lossless=true (WEBP, AVIF)                                                           |
 * | metadata      | keep=none, keep=icc, or everything; strip=true where keep is unknown (vips < 8.15)   |
 * | pages         | TIFF page=0 n=1; GIF and WEBP n=1, or n=-1 for an animation; AVIF its primary image  |
 * | orientation   | --no-rotate: pixels as stored; a JPEG job that applies its orientation goes through  |
 * |               | thumbnail without --no-rotate, which turns the image upright as the EXIF says and    |
 * |               | drops the orientation tag (only with thumbnail, so only where it is allowed, above)  |
 * | damaged input | fail-on=error where supported                                                        |
 *
 * AVIF is written with compression=av1 and bitdepth=8. VIPS_CONCURRENCY=1 and MALLOC_ARENA_MAX=2 keep the address
 * space of the process predictable.
 */
final class VipsBackend extends AbstractBackend
{
    public const NAME = 'vips';

    /**
     * Loader and saver operations of each format.
     */
    private const OPERATIONS = [
        ImageFormat::JPEG => ['jpegload', 'jpegsave'],
        ImageFormat::PNG => ['pngload', 'pngsave'],
        ImageFormat::GIF => ['gifload', 'gifsave'],
        ImageFormat::WEBP => ['webpload', 'webpsave'],
        ImageFormat::TIFF => ['tiffload', 'tiffsave'],
        ImageFormat::AVIF => ['heifload', 'heifsave'],
    ];

    /**
     * Address space by profile (AbstractBackend::estimate()): fixed bytes and bytes per encoded pixel of one frame,
     * on top of INPUT_BYTES per decoded pixel and, for an animation, which is decoded whole and encoded frame by
     * frame, BYTES_PER_ANIMATION_PIXEL per encoded pixel of every frame. The largest peak address space (VmPeak, MiB)
     * of tests/Fixtures/backend/memory-peaks.csv at each size of a still image, over every content, input format, bit
     * depth and effort of the profile:
     *
     * | Profile                       | Fixed   | Per pixel | 1 MP | 2.1 MP | 4 MP | 8.3 MP | 20 MP |
     * | ----------------------------- | ------- | --------- | ---- | ------ | ---- | ------ | ----- |
     * | JPEG, PNG, TIFF               | 368 MiB | 16        | 286  | 293    | 314  | 324    | 462   |
     * | GIF                           | 320 MiB | 68        | 296  | 354    | 460  | 401    | 652   |
     * | WEBP, effort 0 to 8           | 336 MiB | 72        | 288  | 390    | 427  | 552    | 1010  |
     * | WEBP, effort 9 (method 6)     | 320 MiB | 104       | 297  | 408    | 566  | 727    | 1337  |
     * | WEBP lossless                 | 352 MiB | 72        | 295  | 401    | 435  | 569    | 971   |
     * | AVIF, effort 0 to 2           | 464 MiB | 124       | 451  | 557    | 714  | 1149   | 2299  |
     * | AVIF, effort 3 to 9, lossless | 416 MiB | 172       | 451  | 597    | 818  | 1208   |       |
     *
     * Lossless AVIF counts as effort 3 to 9, and effort 9 runs as 8. AV1 needs the most for screenshots and
     * 256-colour images (1208 MiB for a screenshot of 8.3 megapixels on a transparent page at effort 4, 568 for
     * noise); WEBP and GIF for noise and palette noise. Inputs of 16 bits per channel are among the rows and need at
     * most 47 MiB more than the same image with 8 bits. Short of address space, vips can write a truncated AVIF and
     * exit with code 0: the output check rejects it (conversion_failed), so the estimate has to refuse what would not
     * fit under the ceiling.
     */
    private const PROFILES = [
        'still' => [368 * self::MIB, 16],
        'gif' => [320 * self::MIB, 68],
        'webp' => [336 * self::MIB, 72],
        'webp_slow' => [320 * self::MIB, 104],
        'webp_lossless' => [352 * self::MIB, 72],
        'avif_fast' => [464 * self::MIB, 124],
        'avif' => [416 * self::MIB, 172],
    ];

    /**
     * Bytes per decoded pixel: 4, and 12 for the WEBP and AVIF decoders, which hold the whole image.
     */
    private const INPUT_BYTES = [ImageFormat::WEBP => 12, ImageFormat::AVIF => 12];

    private const OTHER_INPUT_BYTES = 4;

    private const BYTES_PER_ANIMATION_PIXEL = 4;

    private const MIB = 1048576;

    /**
     * A one-pixel PPM, which only ppmload, an untrusted loader, reads: the probe of VIPS_BLOCK_UNTRUSTED.
     */
    private const UNTRUSTED_PROBE = "P6\n1 1\n255\n\0\0\0";

    public function getName(): string
    {
        return self::NAME;
    }

    public function estimateMemoryBytes(BackendJob $job): int
    {
        list($fixed, $perPixel) = self::PROFILES[self::profile($job)];

        return self::estimate(
            $job,
            $fixed,
            self::INPUT_BYTES[$job->getInputFormat()] ?? self::OTHER_INPUT_BYTES,
            $perPixel,
            0,
            self::BYTES_PER_ANIMATION_PIXEL
        );
    }

    protected function programs(ExecutableFinder $finder): array
    {
        return ['vips' => $finder->find('vips')];
    }

    protected function discover(array $programs, WorkingDirectory $directory): array
    {
        $list = $this->execute([$programs['vips'], '-l'], $directory);
        if (!$list->isSuccessful()) {
            return [self::FACT_UNAVAILABLE => 'vips -l failed'];
        }
        preg_match_all('/\(([a-z0-9_]+)\)/', $list->getStdout(), $matches);
        $operations = array_flip($matches[1]);
        $loaders = [];
        $savers = [];
        foreach (self::OPERATIONS as $format => $pair) {
            if (isset($operations[$pair[0]])) {
                $loaders[] = $format;
            }
            if (isset($operations[$pair[1]])) {
                $savers[] = $format;
            }
        }
        $webpsave = $this->execute([$programs['vips'], 'webpsave'], $directory);
        $jpegload = $this->execute([$programs['vips'], 'jpegload'], $directory);

        return [
            'loaders' => implode(',', $loaders),
            'savers' => implode(',', $savers),
            'keep' => preg_match('/^\s+keep\s/m', $webpsave->getStdout() . $webpsave->getStderr()) === 1 ? '1' : '0',
            'fail_on' => preg_match('/^\s+fail-on\s/m', $jpegload->getStdout() . $jpegload->getStderr()) === 1 ? '1' : '0',
            'untrusted_blocked' => $this->blocksUntrustedLoaders($programs['vips'], $directory) ? '1' : '0',
        ];
    }

    protected function candidates(array $facts): array
    {
        $inputs = self::formats($facts['loaders'] ?? '');
        $outputs = self::formats($facts['savers'] ?? '');
        $candidates = self::noCandidates();
        $candidates['inputs'] = $inputs;
        $candidates['outputs'] = $outputs;
        $candidates['first_frame'] = [ImageFormat::PNG, ImageFormat::GIF, ImageFormat::WEBP];
        $candidates['animated_inputs'] = [ImageFormat::GIF, ImageFormat::WEBP];
        $candidates['animated_outputs'] = [ImageFormat::WEBP, ImageFormat::GIF];
        if (($facts['untrusted_blocked'] ?? '0') === '1') {
            $candidates['fits'] = ConversionRequest::fitModes();
            $candidates['animated_fits'] = ConversionRequest::fitModes();
            // libvips reads the EXIF orientation itself; the probe proves that it agrees with ImageInspector on a JPEG.
            $candidates['oriented'] = [ImageFormat::JPEG];
        }
        $candidates['lossless'] = [ImageFormat::WEBP, ImageFormat::AVIF];
        $candidates['icc_profile'] = ($facts['keep'] ?? '0') === '1';
        $candidates['metadata'] = true;

        return $candidates;
    }

    protected function command(BackendJob $job, string $input, string $output, array $programs, array $facts): array
    {
        $failOn = ($facts['fail_on'] ?? '0') === '1';
        $save = $this->saveOptions($job, $facts);
        if ($save !== []) {
            $output .= '[' . implode(',', $save) . ']';
        }
        if (!$job->isResized() && !$job->needsOrientation()) {
            $command = [$programs['vips'], self::OPERATIONS[$job->getInputFormat()][0], $input, $output];
            foreach ($this->loadOptions($job) as $name => $value) {
                array_push($command, '--' . $name, $value);
            }
            if ($failOn) {
                array_push($command, '--fail-on', 'error');
            }

            return $command;
        }
        $options = [];
        foreach ($this->loadOptions($job) as $name => $value) {
            $options[] = $name . '=' . $value;
        }
        if ($failOn) {
            $options[] = 'fail-on=error';
        }
        $command = [
            $programs['vips'],
            'thumbnail',
            $options === [] ? $input : $input . '[' . implode(',', $options) . ']',
            $output,
            (string) $job->getWidth(),
            '--height',
            (string) $job->getHeight(),
        ];
        if ($job->getFit() === ConversionRequest::FIT_COVER) {
            array_push($command, '--crop', 'centre');
        } else {
            array_push($command, '--size', 'force');
        }
        if (!$job->needsOrientation()) {
            $command[] = '--no-rotate';
        }

        return $command;
    }

    protected function environment(WorkingDirectory $directory, array $facts): array
    {
        return [
            'VIPS_CONCURRENCY' => '1',
            'VIPS_BLOCK_UNTRUSTED' => '1',
            'MALLOC_ARENA_MAX' => '2',
        ];
    }

    /**
     * Whether vips thumbnail, with this backend's environment, refuses a file that only an untrusted loader reads:
     * VIPS_BLOCK_UNTRUSTED works. Any other outcome (it converts the file, or fails for another reason) counts as no.
     */
    private function blocksUntrustedLoaders(string $vips, WorkingDirectory $directory): bool
    {
        $probe = $directory->pathOf('untrusted.ppm');
        if (@file_put_contents($probe, self::UNTRUSTED_PROBE) !== strlen(self::UNTRUSTED_PROBE)) {
            return false;
        }

        try {
            $result = $this->execute([$vips, 'thumbnail', 'untrusted.ppm', 'untrusted.png', '1', '--no-rotate'], $directory);
        } finally {
            @unlink($probe);
            @unlink($directory->pathOf('untrusted.png'));
        }

        return !$result->isSuccessful() && strpos($result->getStderr(), 'is not a known file format') !== false;
    }

    /**
     * @return array<string, string>
     */
    private function loadOptions(BackendJob $job): array
    {
        switch ($job->getInputFormat()) {
            case ImageFormat::TIFF:
                return ['page' => '0', 'n' => '1'];
            case ImageFormat::GIF:
            case ImageFormat::WEBP:
                return ['n' => $job->isAnimated() ? '-1' : '1'];
            default:
                return [];
        }
    }

    /**
     * The row of PROFILES for a job.
     */
    private static function profile(BackendJob $job): string
    {
        switch ($job->getOutputFormat()) {
            case ImageFormat::WEBP:
                if ($job->needsLosslessMode()) {
                    return 'webp_lossless';
                }

                return self::webpMethod($job) === 6 ? 'webp_slow' : 'webp';
            case ImageFormat::AVIF:
                return $job->getEffort() <= 2 && !$job->needsLosslessMode() ? 'avif_fast' : 'avif';
            case ImageFormat::GIF:
                return 'gif';
            default:
                return 'still';
        }
    }

    /**
     * @param array<string, string> $facts
     *
     * @return list<string>
     */
    private function saveOptions(BackendJob $job, array $facts): array
    {
        $quality = (string) $job->getQuality();
        switch ($job->getOutputFormat()) {
            case ImageFormat::WEBP:
                $options = ['Q=' . $quality, 'effort=' . self::webpMethod($job)];
                break;
            case ImageFormat::AVIF:
                // AV1 effort 9 (speed 0) takes over six times as long as 8 for a negligible gain.
                $options = ['compression=av1', 'Q=' . $quality, 'effort=' . min(8, $job->getEffort()), 'bitdepth=8'];
                break;
            case ImageFormat::JPEG:
                $options = ['Q=' . $quality, 'optimize-coding=true'];
                break;
            case ImageFormat::PNG:
                $options = ['compression=' . max(1, $job->getEffort())];
                break;
            case ImageFormat::TIFF:
                $options = ['compression=deflate'];
                break;
            default:
                $options = [];
        }
        if ($job->needsLosslessMode()) {
            $options[] = 'lossless=true';
        }
        if (($facts['keep'] ?? '0') === '1') {
            if ($job->shouldStripMetadata()) {
                $options[] = $job->shouldKeepIccProfile() ? 'keep=icc' : 'keep=none';
            }
        } elseif ($job->shouldStripMetadata()) {
            $options[] = 'strip=true';
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private static function formats(string $list): array
    {
        return array_values(array_intersect(ImageFormat::all(), explode(',', $list)));
    }
}
