<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * The conversion did not complete within its wall-clock timeout and its process was killed, or its estimated
 * processing time is above the ceiling of the processing budget, so that it was refused before any process ran.
 * Either way the input exceeds the processing budget of this converter, so a retry of the same request will not help.
 *
 * Error code ErrorCode::CONVERSION_TIMEOUT; expected HTTP status in the API: 422 Unprocessable Content.
 */
final class ConversionTimeoutException extends \RuntimeException implements ExceptionInterface
{
    public function getErrorCode(): string
    {
        return ErrorCode::CONVERSION_TIMEOUT;
    }
}
