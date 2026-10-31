<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;

/**
 * ImageMagick through ext-imagick in a PHP CLI worker (Worker\ImagickWorker), for still images: the shell-free
 * fallback for formats the command-line tools do not cover, such as BMP.
 *
 * The worker runs under the restrictive policy shipped in resources/imagemagick/policy.xml (it denies every coder
 * but the raster formats of the library, every delegate, filter and indirect read, and caps the resources), found
 * through MAGICK_CONFIGURE_PATH and, in the sandbox, the only policy ImageMagick sees. It also lowers the memory and
 * map limits to the job's budget and disables the disk cache for each job. MAGICK_TEMPORARY_PATH points to the job's
 * working directory, MAGICK_THREAD_LIMIT is 1 and OpenCL is off.
 *
 * Animations are not written: an animated input is converted to its first frame only.
 */
final class ImagickBackend extends AbstractWorkerBackend
{
    public const NAME = 'imagick';

    /**
     * Fixed address space of PHP with ImageMagick, bytes per decoded pixel (16 bits per channel in the pixel cache),
     * and fixed bytes and bytes per encoded pixel by output format. Measured in the sandbox on RGBA noise (the
     * costliest content), about 15 % added: 87 and 119 MiB at 1 and 4 megapixels for JPEG, PNG, BMP and TIFF, 119 and
     * 247 MiB for WEBP (lossless: 127 and 239 MiB), 223 and 271 MiB for GIF, 159 and 438 MiB for AVIF.
     */
    private const BASE_BYTES = 134217728;

    private const BYTES_PER_INPUT_PIXEL = 12;

    private const OUTPUT_BYTES = [
        ImageFormat::WEBP => [0, 40],
        ImageFormat::GIF => [134217728, 12],
        ImageFormat::AVIF => [0, 96],
    ];

    private const BYTES_PER_OTHER_OUTPUT_PIXEL = 8;

    public function getName(): string
    {
        return self::NAME;
    }

    public function estimateMemoryBytes(BackendJob $job): int
    {
        list($extra, $perPixel) = self::OUTPUT_BYTES[$job->getOutputFormat()] ?? [0, self::BYTES_PER_OTHER_OUTPUT_PIXEL];

        return self::addressSpace(self::BASE_BYTES + $extra, self::BYTES_PER_INPUT_PIXEL, $job->getInputPixels(), $perPixel, $job->getOutputPixels());
    }

    /**
     * Directory of the shipped policy.xml.
     */
    public static function policyDirectory(): string
    {
        $directory = realpath(\dirname(__DIR__, 2) . '/resources/imagemagick');

        return is_string($directory) ? $directory : \dirname(__DIR__, 2) . '/resources/imagemagick';
    }

    protected function candidates(array $facts): array
    {
        $candidates = parent::candidates($facts);
        $candidates['icc_profile'] = true;
        $candidates['metadata'] = true;

        return $candidates;
    }

    protected function environment(WorkingDirectory $directory, array $facts): array
    {
        return [
            'MAGICK_CONFIGURE_PATH' => self::policyDirectory(),
            'MAGICK_TEMPORARY_PATH' => $directory->getPath(),
            'MAGICK_THREAD_LIMIT' => '1',
            'MAGICK_OCL_DEVICE' => 'OFF',
        ];
    }

    protected function sandboxResources(): array
    {
        return array_merge(parent::sandboxResources(), [self::policyDirectory()]);
    }

    protected function extension(): string
    {
        return 'imagick';
    }

    protected function workerName(): string
    {
        return 'imagick';
    }
}
