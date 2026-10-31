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
     * pixel more for an input of more than 8 bits per channel, on top of BYTES_PER_INPUT_PIXEL per decoded pixel (the
     * pixel cache holds 16 bits per channel, whatever the input format) and, for a resize, RESIZE_BYTES per encoded
     * pixel (the resized copy). The largest peak address space (VmPeak, MiB) of tests/Fixtures/backend/
     * memory-peaks.csv at each size of a still image, over every content, input format, bit depth and effort of the
     * profile:
     *
     * | Profile                   | Fixed   | Per pixel | 16-bit | 1 MP | 2.1 MP | 4 MP | 8.3 MP | 20 MP |
     * | ------------------------- | ------- | --------- | ------ | ---- | ------ | ---- | ------ | ----- |
     * | JPEG, PNG, TIFF, BMP      | 144 MiB | 24        | 4      | 98   | 126    | 177  | 292    | 604   |
     * | GIF                       | 288 MiB | 24        | 0      | 235  | 263    | 314  | 413    | 694   |
     * | WEBP, effort 0 to 8       | 128 MiB | 48        | 0      | 117  | 174    | 252  | 435    | 935   |
     * | WEBP, effort 9 (method 6) | 128 MiB | 72        | 0      | 137  | 204    | 314  | 585    | 1202  |
     * | WEBP lossless             | 176 MiB | 48        | 0      | 134  | 207    | 286  | 436    | 760   |
     * | AVIF, effort 0 to 2       | 336 MiB | 120       | 24     | 375  | 497    | 711  | 1169   | 2241  |
     * | AVIF, effort 3 to 9       | 336 MiB | 156       | 24     | 401  | 546    | 800  | 1169   |       |
     *
     * AV1 needs the most for screenshots (1084 MiB for one of 8.3 megapixels on a transparent page at effort 4, 498
     * for noise), and up to 93 MiB more for a 16-bit input of 4 megapixels.
     */
    private const PROFILES = [
        'still' => [144 * self::MIB, 24, 4],
        'gif' => [288 * self::MIB, 24, 0],
        'webp' => [128 * self::MIB, 48, 0],
        'webp_slow' => [128 * self::MIB, 72, 0],
        'webp_lossless' => [176 * self::MIB, 48, 0],
        'avif_fast' => [336 * self::MIB, 120, 24],
        'avif' => [336 * self::MIB, 156, 24],
    ];

    private const BYTES_PER_INPUT_PIXEL = 12;

    private const RESIZE_BYTES = 24;

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
