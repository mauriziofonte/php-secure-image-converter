<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * A caller passed a value outside the documented domain of a method that does not deal with request parameters: an
 * unknown format name or isolation level, inconsistent ConversionResult values, a malformed Capabilities array, a
 * tampered serialized object, a missing input file. This is a programming, configuration or integration error,
 * never the end user's fault; request parameters are reported with InvalidRequestException and input content with
 * UnsupportedFormatException or InvalidImageException instead.
 *
 * Error code ErrorCode::INTERNAL_ERROR; expected HTTP status in the API: 500 Internal Server Error.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements ExceptionInterface
{
    public function getErrorCode(): string
    {
        return ErrorCode::INTERNAL_ERROR;
    }
}
