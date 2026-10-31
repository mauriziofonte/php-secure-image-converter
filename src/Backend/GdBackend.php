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
     * Fixed address space of PHP with GD, and bytes per decoded pixel (a truecolor image takes 4) and per encoded
     * pixel by output format. Measured in the sandbox on RGBA noise (the costliest content), about 15 % added:
     * 79 and 103 MiB at 1 and 4 megapixels for JPEG, PNG and GIF, 79 and 111 MiB for BMP, 119 and 231 MiB for WEBP,
     * 119 and 239 MiB for lossless WEBP.
     */
    private const BASE_BYTES = 100663296;

    private const BYTES_PER_INPUT_PIXEL = 8;

    private const BYTES_PER_OUTPUT_PIXEL = [ImageFormat::WEBP => 36];

    private const LOSSLESS_WEBP_BYTES_PER_PIXEL = 44;

    private const BYTES_PER_OTHER_OUTPUT_PIXEL = 8;

    public function getName(): string
    {
        return self::NAME;
    }

    public function estimateMemoryBytes(BackendJob $job): int
    {
        return self::addressSpace(
            self::BASE_BYTES,
            self::BYTES_PER_INPUT_PIXEL,
            $job->getInputPixels(),
            $job->needsLosslessMode()
                ? self::LOSSLESS_WEBP_BYTES_PER_PIXEL
                : self::BYTES_PER_OUTPUT_PIXEL[$job->getOutputFormat()] ?? self::BYTES_PER_OTHER_OUTPUT_PIXEL,
            $job->getOutputPixels()
        );
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
