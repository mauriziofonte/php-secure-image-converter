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
 * | orientation   | --no-rotate: pixels as stored                                                        |
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
     * Address space of the program alone with VIPS_CONCURRENCY=1: the discovery runs and the base of every job.
     */
    private const BASE_BYTES = 201326592;

    /**
     * Address space by profile (AbstractBackend::estimate()): fixed bytes, bytes per encoded pixel of one frame, and
     * bytes per pixel more for an input of more than 8 bits per channel. The peak address space (VmPeak, MiB) of the
     * profile's costliest effort on RGBA noise at 1, 4, 6 and 20 megapixels, a 16-bit input in parentheses:
     *
     * | Profile                     | Fixed   | Per pixel | 16-bit | 1 MP      | 4 MP      | 6 MP | 20 MP      |
     * | --------------------------- | ------- | --------- | ------ | --------- | --------- | ---- | ---------- |
     * | JPEG, PNG, TIFF             | 352 MiB | 3         | 0      | 268 (268) | 277 (277) |      | 364        |
     * | WEBP, effort 0 to 8         | 304 MiB | 45        | 0      | 269 (269) | 391 (395) | 461  | 1010 (952) |
     * | WEBP, effort 9 (method 6)   | 288 MiB | 69        | 12     | 277 (283) | 447 (516) | 564  |            |
     * | WEBP lossless               | 320 MiB | 44        | 0      | 272 (277) | 390 (411) |      |            |
     * | AVIF, effort 0 to 2         | 320 MiB | 39        | 2      | 278 (282) | 378 (393) |      | 870        |
     * | AVIF, effort 3 to 9         | 352 MiB | 54        | 0      | 297 (304) | 482 (460) | 526  |            |
     * | GIF                         | 320 MiB | 58        | 0      | 287 (293) | 445 (456) | 410  |            |
     *
     * Lossless AVIF counts as effort 3 to 9. On top come INPUT_BYTES per decoded pixel and, for an animation, which
     * is decoded whole and encoded frame by frame, 4 bytes per encoded pixel of every frame. Measured with libvips
     * 8.15, libwebp 1.3, libheif 1.17 and libaom 3.8; with less than the estimate, vips fails with SIGSEGV or "mux
     * error", hangs, or writes an empty AVIF with exit code 0.
     */
    private const PROFILES = [
        'still' => [352 * self::MIB, 3, 0],
        'webp' => [304 * self::MIB, 45, 0],
        'webp_slow' => [288 * self::MIB, 69, 12],
        'webp_lossless' => [320 * self::MIB, 44, 0],
        'avif_fast' => [320 * self::MIB, 39, 2],
        'avif' => [352 * self::MIB, 54, 0],
        'gif' => [320 * self::MIB, 58, 0],
    ];

    /**
     * Bytes per decoded pixel: 4, and 12 for the WEBP and AVIF decoders, which hold the whole image (WEBP at
     * 4 megapixels peaks at 425 MiB from a WEBP input and 391 from PNG).
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
        list($fixed, $perPixel, $perDeepPixel) = self::PROFILES[self::profile($job)];

        return self::estimate(
            $job,
            $fixed,
            self::INPUT_BYTES[$job->getInputFormat()] ?? self::OTHER_INPUT_BYTES,
            $perPixel,
            $perDeepPixel,
            self::BYTES_PER_ANIMATION_PIXEL
        );
    }

    protected function programs(ExecutableFinder $finder): array
    {
        return ['vips' => $finder->find('vips')];
    }

    protected function discover(array $programs, WorkingDirectory $directory): array
    {
        $list = $this->execute([$programs['vips'], '-l'], $directory, self::BASE_BYTES);
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
        $webpsave = $this->execute([$programs['vips'], 'webpsave'], $directory, self::BASE_BYTES);
        $jpegload = $this->execute([$programs['vips'], 'jpegload'], $directory, self::BASE_BYTES);

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
        if (!$job->isResized()) {
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
        $command[] = '--no-rotate';

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
            $result = $this->execute([$vips, 'thumbnail', 'untrusted.ppm', 'untrusted.png', '1', '--no-rotate'], $directory, self::BASE_BYTES);
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
