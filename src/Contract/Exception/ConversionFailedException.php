<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * The conversion could not be completed for a reason internal to the converter: no backend could produce the
 * requested output or any fallback format, a backend exited with an error or was killed by a resource limit other
 * than the wall-clock timeout, or the output could not be written.
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
