<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * Implemented by every exception thrown by this library, so that callers can catch all of them at once.
 *
 * Callers branch on getErrorCode(), never on the class hierarchy or the message: ErrorCode documents every code
 * and the HTTP status the conversion API is expected to answer with. Messages are for humans; they never contain
 * file paths or raw input that is not a short, printable token, so they can be logged as they are.
 */
interface ExceptionInterface extends \Throwable
{
    /**
     * Stable, machine-readable code of this error: one of the ErrorCode constants.
     */
    public function getErrorCode(): string;
}
