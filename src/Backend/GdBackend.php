<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Contract\ImageFormat;

/**
 * GD through ext-gd in a PHP CLI worker (Worker\GdWorker): the shell-free fallback for still images. It keeps no
 * metadata (every output is stripped, ICC profile included) and does not read TIFF or animated WEBP; it converts
 * the first frame of a GIF and the default image of an APNG.
 */
final class GdBackend extends AbstractWorkerBackend
{
    public const NAME = 'gd';

    /**
     * Address space by output (AbstractBackend::estimate()): fixed bytes and bytes per encoded pixel, on top of
     * BYTES_PER_INPUT_PIXEL per decoded pixel (a truecolor image takes 4) and, for a resize, RESIZE_BYTES per encoded
     * pixel (the resized truecolor copy). GD reads a 16-bit PNG as 8 bits, and the effort changes neither (GD has no
     * WEBP method, and the PNG level does not change the peak). The largest peak address space (VmPeak, MiB) of
     * tests/Fixtures/backend/memory-peaks.csv at each size of a still image, over every content and input format:
     *
     * | Profile             | Fixed   | Per pixel | 1 MP | 2.1 MP | 4 MP | 8.3 MP | 20 MP |
     * | ------------------- | ------- | --------- | ---- | ------ | ---- | ------ | ----- |
     * | JPEG, PNG, GIF, BMP | 112 MiB | 8         | 76   | 88     | 109  | 128    | 218   |
     * | WEBP                | 128 MiB | 44        | 111  | 160    | 225  | 396    | 853   |
     * | WEBP lossless       | 144 MiB | 48        | 123  | 185    | 248  | 414    | 715   |
     *
     * An enlargement of 1000 x 1000 pixels to 6000 x 6000 peaks at 343 MiB to JPEG, PNG, GIF and BMP, 948 MiB to
     * WEBP and 1064 MiB to lossless WEBP.
     */
    private const OUTPUT_BYTES = [
        'still' => [112 * self::MIB, 8],
        'webp' => [128 * self::MIB, 44],
        'webp_lossless' => [144 * self::MIB, 48],
    ];

    private const BYTES_PER_INPUT_PIXEL = 8;

    private const RESIZE_BYTES = 4;

    private const MIB = 1048576;

    public function getName(): string
    {
        return self::NAME;
    }

    public function estimateMemoryBytes(BackendJob $job): int
    {
        if ($job->getOutputFormat() !== ImageFormat::WEBP) {
            $profile = 'still';
        } else {
            $profile = $job->needsLosslessMode() ? 'webp_lossless' : 'webp';
        }
        list($fixed, $perPixel) = self::OUTPUT_BYTES[$profile];

        return self::estimate($job, $fixed, self::BYTES_PER_INPUT_PIXEL, $perPixel, 0, 0, self::RESIZE_BYTES);
    }

    protected function extension(): string
    {
        return 'gd';
    }

    protected function workerName(): string
    {
        return 'gd';
    }
}
