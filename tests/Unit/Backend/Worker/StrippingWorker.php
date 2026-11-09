<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Worker;

use Mfonte\SecureImageConverter\Backend\Worker\AbstractWorker;

/**
 * A worker that only exposes AbstractWorker::dropEncoderMetadata() to the tests.
 */
final class StrippingWorker extends AbstractWorker
{
    public static function drop(string $path, string $format, bool $keepIcc): void
    {
        self::dropEncoderMetadata($path, $format, $keepIcc);
    }

    protected function probe(): array
    {
        return ['inputs' => [], 'outputs' => [], 'lossless' => []];
    }

    protected function convert(array $job): void
    {
    }
}
