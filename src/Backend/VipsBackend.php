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
 * (ImageMagick, PDF, SVG and the other "untrusted" loaders are blocked; BMP, which libvips reads only through
 * ImageMagick, is therefore not supported). The output saver follows from the output file name.
 *
 * | Setting       | Mapping                                                                              |
 * | ------------- | ------------------------------------------------------------------------------------ |
 * | quality       | Q (JPEG, WEBP, AVIF)                                                                 |
 * | effort        | WEBP effort 0 to 6 (scaled), AVIF effort 0 to 9, PNG zlib level (at least 1)         |
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
     * Fixed address space of the program with VIPS_CONCURRENCY=1, and bytes per decoded and encoded pixel, measured
     * with prlimit (about 170 MiB, 9 and 6) and rounded up.
     */
    private const BASE_BYTES = 234881024;

    private const BYTES_PER_INPUT_PIXEL = 10;

    private const BYTES_PER_OUTPUT_PIXEL = 8;

    public function getName(): string
    {
        return self::NAME;
    }

    public function estimateMemoryBytes(BackendJob $job): int
    {
        return self::BASE_BYTES
            + (int) min(PHP_INT_MAX / 4, self::BYTES_PER_INPUT_PIXEL * (float) $job->getInputPixels())
            + (int) min(PHP_INT_MAX / 4, self::BYTES_PER_OUTPUT_PIXEL * (float) $job->getOutputPixels());
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
        $candidates['fits'] = ConversionRequest::fitModes();
        $candidates['animated_fits'] = ConversionRequest::fitModes();
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
                $options = ['compression=av1', 'Q=' . $quality, 'effort=' . $job->getEffort(), 'bitdepth=8'];
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
