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
     * Address space by output (AbstractBackend::estimate()): fixed bytes and bytes per encoded pixel, on top of 8
     * bytes per decoded pixel (a truecolor image takes 4). The peak address space (VmPeak, MiB) of PHP with GD on RGBA
     * noise at 1, 4 and 20 megapixels, a 16-bit input in parentheses (GD reads it as 8 bits); the effort changes
     * neither (GD has no WEBP method, and the PNG level does not change the peak):
     *
     * | Output                | Fixed   | Per pixel | 1 MP      | 4 MP      | 20 MP |
     * | --------------------- | ------- | --------- | --------- | --------- | ----- |
     * | JPEG, PNG, GIF, BMP   | 96 MiB  | 2         | 72 (72)   | 95 (95)   | 217   |
     * | WEBP                  | 112 MiB | 40        | 111 (111) | 224 (224) | 853   |
     * | WEBP lossless         | 128 MiB | 36        | 123 (123) | 232 (232) |       |
     */
    private const OUTPUT_BYTES = [
        'still' => [96 * self::MIB, 2],
        'webp' => [112 * self::MIB, 40],
        'webp_lossless' => [128 * self::MIB, 36],
    ];

    private const BYTES_PER_INPUT_PIXEL = 8;

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

        return self::estimate($job, $fixed, self::BYTES_PER_INPUT_PIXEL, $perPixel);
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
