<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * The conversion could not be completed for a reason internal to the converter: no backend could produce the
 * requested output or any fallback format, a backend exited with an error or crashed without a sign of memory
 * exhaustion, or the output could not be written. A backend stopped by its wall-clock timeout throws
 * ConversionTimeoutException instead, and one stopped by its memory, CPU time or file size limit
 * ResourceLimitException.
 *
 * Error code ErrorCode::CONVERSION_FAILED; expected HTTP status in the API: 500 Internal Server Error.
 */
final class ConversionFailedException extends \RuntimeException implements ExceptionInterface
{
    public function getErrorCode(): string
    {
        return ErrorCode::CONVERSION_FAILED;
    }
}
