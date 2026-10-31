<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Worker;

/**
 * The Imagick worker (see AbstractWorker): ImageMagick through ext-imagick, under the restrictive policy shipped in
 * resources/imagemagick/policy.xml (MAGICK_CONFIGURE_PATH) and per-job resource limits.
 *
 * Every read names its coder and its first image ("TIFF:input.tiff[0]"), so that ImageMagick never guesses the
 * format from the content and decodes page 0 of a TIFF and the first frame of an animation only; animations are
 * never written. Before reading, the memory and map limits are set to the job's memory_bytes (the memory ceiling, the
 * address space limit of the process), the disk limit to 0 (the pixel cache never spills to disk), one thread. A
 * resource limit error is reported as EXIT_RESOURCE_LIMIT.
 * Stripping removes every profile and comment; the ICC profile is put back when it is kept.
 *
 * @internal
 */
final class ImagickWorker extends AbstractWorker
{
    /**
     * ImageMagick coder of each format.
     */
    private const CODERS = [
        'jpeg' => 'JPEG',
        'png' => 'PNG',
        'gif' => 'GIF',
        'bmp' => 'BMP',
        'webp' => 'WEBP',
        'avif' => 'AVIF',
        'tiff' => 'TIFF',
    ];

    /**
     * Entry point of resources/workers/imagick.php.
     *
     * @param list<string> $arguments
     */
    public static function main(array $arguments): int
    {
        return (new self())->run($arguments);
    }

    protected function probe(): array
    {
        $available = class_exists('Imagick') ? \Imagick::queryFormats() : [];
        $formats = [];
        foreach (self::CODERS as $format => $coder) {
            if (in_array($coder, $available, true)) {
                $formats[] = $format;
            }
        }

        return [
            'inputs' => $formats,
            'outputs' => $formats,
            'lossless' => in_array('webp', $formats, true) ? ['webp'] : [],
        ];
    }

    protected function convert(array $job): void
    {
        if (!class_exists('Imagick')) {
            throw new \RuntimeException('Imagick is not loaded.', self::EXIT_FAILED);
        }
        self::limit($job['memory_bytes']);

        try {
            $image = new \Imagick();
            $image->readImage(self::CODERS[$job['input_format']] . ':' . $job['input'] . '[0]');
            $image->setIteratorIndex(0);
            $profile = null;
            if ($job['strip'] && $job['keep_icc']) {
                $profiles = $image->getImageProfiles('icc', true);
                $profile = isset($profiles['icc']) && is_string($profiles['icc']) ? $profiles['icc'] : null;
            }
            if ($job['strip']) {
                $image->stripImage();
                if ($profile !== null) {
                    $image->profileImage('icc', $profile);
                }
            }
            if ($image->getImageWidth() !== $job['width'] || $image->getImageHeight() !== $job['height']) {
                self::resize($image, $job);
            }
            self::configure($image, $job);
            $image->writeImage(self::CODERS[$job['output_format']] . ':' . $job['output']);
            $image->clear();
        } catch (\ImagickException $exception) {
            throw new \RuntimeException(
                'ImageMagick could not convert the image.',
                self::isResourceLimit($exception) ? self::EXIT_RESOURCE_LIMIT : self::EXIT_FAILED
            );
        }
        self::assertWritten($job['output']);
    }

    /**
     * Lowers ImageMagick's resource limits (the policy sets the ceiling) for one job.
     */
    private static function limit(int $memoryBytes): void
    {
        $limits = [
            'RESOURCETYPE_MEMORY' => $memoryBytes,
            'RESOURCETYPE_MAP' => $memoryBytes,
            'RESOURCETYPE_DISK' => 0,
            'RESOURCETYPE_THREAD' => 1,
        ];
        foreach ($limits as $name => $value) {
            if (defined('Imagick::' . $name)) {
                \Imagick::setResourceLimit((int) constant('Imagick::' . $name), $value);
            }
        }
    }

    /**
     * @param array{fit: string, width: int, height: int} $job
     */
    private static function resize(\Imagick $image, array $job): void
    {
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        if ($job['fit'] !== 'cover') {
            $image->resizeImage($job['width'], $job['height'], \Imagick::FILTER_LANCZOS, 1.0);

            return;
        }
        $region = self::coverRegion($width, $height, $job['width'], $job['height']);
        $image->cropImage($region[2], $region[3], $region[0], $region[1]);
        $image->setImagePage($region[2], $region[3], 0, 0);
        $image->resizeImage($job['width'], $job['height'], \Imagick::FILTER_LANCZOS, 1.0);
        $image->setImagePage($job['width'], $job['height'], 0, 0);
    }

    /**
     * @param array{output_format: string, quality: int, effort: int, lossless: bool} $job
     */
    private static function configure(\Imagick $image, array $job): void
    {
        $image->setImageFormat(self::CODERS[$job['output_format']]);
        switch ($job['output_format']) {
            case 'webp':
                $image->setOption('webp:lossless', $job['lossless'] ? 'true' : 'false');
                $image->setOption('webp:method', (string) (int) round($job['effort'] * 6 / 9));
                $image->setImageCompressionQuality($job['quality']);
                break;
            case 'avif':
                if ($job['lossless']) {
                    throw new \RuntimeException('Imagick does not encode a lossless AVIF.', self::EXIT_FAILED);
                }
                $image->setImageCompressionQuality($job['quality']);
                // Speed 0 takes over six times as long as speed 1 for a negligible gain.
                $image->setOption('heic:speed', (string) max(1, 9 - $job['effort']));
                break;
            case 'jpeg':
                $image->setImageCompressionQuality($job['quality']);
                break;
            case 'png':
                $image->setOption('png:compression-level', (string) min(9, max(1, $job['effort'])));
                break;
            case 'tiff':
                $image->setImageCompression(\Imagick::COMPRESSION_ZIP);
                break;
            case 'bmp':
                // ImageMagick codes an indexed image with RLE8 otherwise, in runs of one pixel where neighbours
                // differ, and BmpParser refuses an output of more than BmpParser::MAX_RUNS runs.
                $image->setCompression(\Imagick::COMPRESSION_NO);
                break;
        }
    }

    private static function isResourceLimit(\ImagickException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        foreach (['resource', 'memory allocation failed', 'exceeds limit', 'too many'] as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }

        return in_array($exception->getCode(), [400, 700], true);
    }
}
