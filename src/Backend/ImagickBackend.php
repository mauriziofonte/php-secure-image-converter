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
 * through MAGICK_CONFIGURE_PATH and, in the sandbox, the only policy ImageMagick sees. It also sets the memory and
 * map limits to the memory ceiling and disables the disk cache for each job. MAGICK_TEMPORARY_PATH points to the job's
 * working directory, MAGICK_THREAD_LIMIT is 1 and OpenCL is off. MALLOC_ARENA_MAX=2 bounds the address space that the
 * threads of the AVIF encoder (libheif and libaom run four, whatever the number of processors) reserve for malloc.
 *
 * Animations are not written: an animated input is converted to its first frame only.
 */
final class ImagickBackend extends AbstractWorkerBackend
{
    public const NAME = 'imagick';

    /**
     * Address space by profile (AbstractBackend::estimate()): fixed bytes, bytes per encoded pixel, and bytes per
     * pixel more for an input of more than 8 bits per channel. The peak address space (VmPeak, MiB) of PHP with
     * ImageMagick for the profile's costliest effort on RGBA noise at 1, 4, 6 and 20 megapixels, a 16-bit input in
     * parentheses:
     *
     * | Profile                        | Fixed   | Per pixel | 16-bit | 1 MP      | 4 MP      | 6 MP | 20 MP |
     * | ------------------------------ | ------- | --------- | ------ | --------- | --------- | ---- | ----- |
     * | JPEG, PNG, TIFF, BMP           | 160 MiB | 7         | 0      | 85 (85)   | 131 (131) |      | 299   |
     * | GIF                            | 288 MiB | 19        | 0      | 231 (231) | 306 (306) | 355  |       |
     * | WEBP, effort 0 to 8            | 144 MiB | 42        | 0      | 114 (114) | 247 (247) | 334  | 935   |
     * | WEBP, effort 9 (method 6)      | 160 MiB | 60        | 0      | 134 (134) | 311 (311) |      |       |
     * | WEBP lossless                  | 160 MiB | 38        | 0      | 123 (123) | 247 (247) |      |       |
     * | AVIF, effort 0 to 2            | 320 MiB | 27        | 11     | 264 (281) | 362 (428) |      | 877   |
     * | AVIF, effort 3 to 9            | 320 MiB | 42        | 12     | 282 (299) | 415 (482) | 498  |       |
     *
     * On top come 12 bytes per decoded pixel (the pixel cache holds 16 bits per channel, whatever the input format)
     * and, for a resize, RESIZE_BYTES per encoded pixel (the resized copy: 254 MiB from 1 to 4 megapixels, against
     * 247 MiB without resize). Measured with ImageMagick 6.9.12 (Q16), libwebp 1.3, libheif 1.17 and libaom 3.8.
     */
    private const PROFILES = [
        'still' => [160 * self::MIB, 7, 0],
        'gif' => [288 * self::MIB, 19, 0],
        'webp' => [144 * self::MIB, 42, 0],
        'webp_slow' => [160 * self::MIB, 60, 0],
        'webp_lossless' => [160 * self::MIB, 38, 0],
        'avif_fast' => [320 * self::MIB, 27, 11],
        'avif' => [320 * self::MIB, 42, 12],
    ];

    private const BYTES_PER_INPUT_PIXEL = 12;

    private const RESIZE_BYTES = 12;

    private const MIB = 1048576;

    public function getName(): string
    {
        return self::NAME;
    }

    public function estimateMemoryBytes(BackendJob $job): int
    {
        list($fixed, $perPixel, $perDeepPixel) = self::PROFILES[self::profile($job)];

        return self::estimate($job, $fixed, self::BYTES_PER_INPUT_PIXEL, $perPixel, $perDeepPixel, 0, self::RESIZE_BYTES);
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
            'MALLOC_ARENA_MAX' => '2',
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
                return $job->getEffort() <= 2 ? 'avif_fast' : 'avif';
            case ImageFormat::GIF:
                return 'gif';
            default:
                return 'still';
        }
    }
}
