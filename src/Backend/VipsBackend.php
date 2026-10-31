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
     * Address space per output format: fixed bytes and bytes per encoded pixel of one frame. Measured in the sandbox
     * on RGBA noise (the costliest content) at 1 and 4 megapixels, with 8 bytes per decoded pixel on top, and about
     * 15 % added; the minimum that converted twice in a row is in parentheses.
     *
     * | Output          | Fixed   | Per pixel | Measured at 1 and 4 megapixels                     |
     * | --------------- | ------- | --------- | -------------------------------------------------- |
     * | JPEG, PNG, TIFF | 192 MiB | 4         | 167 to 191 MiB                                     |
     * | WEBP            | 192 MiB | 40        | 199 and 326 MiB                                    |
     * | WEBP lossless   | 192 MiB | 64        | 215 and 390 MiB                                    |
     * | GIF             | 320 MiB | 24        | 294 and 366 MiB (libimagequant)                    |
     * | AVIF            | 288 MiB | 32        | 278 and 382 MiB (lossless: 302 and 390 MiB); below |
     * |                 |         |           | 256 MiB, 1 run in 12 of a 40 x 30 image wrote an   |
     * |                 |         |           | empty AVIF with exit code 0                        |
     *
     * An animation is decoded whole and encoded frame by frame, keeping 4 bytes per encoded pixel of every frame.
     */
    private const OUTPUT_BYTES = [
        ImageFormat::JPEG => [201326592, 4],
        ImageFormat::PNG => [201326592, 4],
        ImageFormat::TIFF => [201326592, 4],
        ImageFormat::WEBP => [201326592, 40],
        ImageFormat::GIF => [335544320, 24],
        ImageFormat::AVIF => [301989888, 32],
    ];

    private const LOSSLESS_WEBP_BYTES_PER_PIXEL = 64;

    private const BYTES_PER_INPUT_PIXEL = 8;

    private const BYTES_PER_ANIMATION_PIXEL = 4;

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
        list($base, $perPixel) = self::OUTPUT_BYTES[$job->getOutputFormat()] ?? [self::BASE_BYTES, 40];
        if ($job->needsLosslessMode() && $job->getOutputFormat() === ImageFormat::WEBP) {
            $perPixel = self::LOSSLESS_WEBP_BYTES_PER_PIXEL;
        }
        $estimate = self::addressSpace($base, self::BYTES_PER_INPUT_PIXEL, $job->getInputPixels(), $perPixel, $job->getWidth() * $job->getHeight());

        return $job->isAnimated()
            ? self::addressSpace($estimate, 0, 0, self::BYTES_PER_ANIMATION_PIXEL, $job->getOutputPixels())
            : $estimate;
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
     * @param array<string, string> $facts
     *
     * @return list<string>
     */
    private function saveOptions(BackendJob $job, array $facts): array
    {
        $quality = (string) $job->getQuality();
        switch ($job->getOutputFormat()) {
            case ImageFormat::WEBP:
                $options = ['Q=' . $quality, 'effort=' . (int) round($job->getEffort() * 6 / 9)];
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
