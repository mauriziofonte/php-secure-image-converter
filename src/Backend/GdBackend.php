<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

/**
 * GD through ext-gd in a PHP CLI worker (Worker\GdWorker): the shell-free fallback for still images. It keeps no
 * metadata (every output is stripped, ICC profile included) and does not read TIFF or animated WEBP; it converts
 * the first frame of a GIF and the default image of an APNG.
 */
final class GdBackend extends AbstractWorkerBackend
{
    public const NAME = 'gd';

    /**
     * Fixed address space of PHP with GD, and bytes per decoded and encoded pixel (truecolor images take 4 bytes per
     * pixel; the encoders need a few more), rounded up.
     */
    private const BASE_BYTES = 134217728;

    private const BYTES_PER_INPUT_PIXEL = 8;

    private const BYTES_PER_OUTPUT_PIXEL = 16;

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

    protected function extension(): string
    {
        return 'gd';
    }

    protected function workerName(): string
    {
        return 'gd';
    }
}
