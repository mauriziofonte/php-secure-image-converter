<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * The input's format is not acceptable: its content matches no format the library can identify (for example HEIC,
 * PDF or random bytes), or the identified format is not enabled for input on this converter. Also thrown by
 * ImageFormat::fromMimeType() for a MIME type that is not a known image type.
 *
 * Error code ErrorCode::UNSUPPORTED_FORMAT; expected HTTP status in the API: 415 Unsupported Media Type.
 */
final class UnsupportedFormatException extends \UnexpectedValueException implements ExceptionInterface
{
    public function getErrorCode(): string
    {
        return ErrorCode::UNSUPPORTED_FORMAT;
    }
}
