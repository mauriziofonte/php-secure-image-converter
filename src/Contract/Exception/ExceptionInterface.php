<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * Marker implemented by every exception thrown by this library, so that callers can catch all of them at once.
 *
 * Each concrete exception documents the HTTP status the conversion API is expected to answer with when it reaches
 * the HTTP layer; the library itself knows nothing about HTTP. Summary:
 *
 * | Exception                     | Status |
 * | ----------------------------- | ------ |
 * | InvalidRequestException       | 400    |
 * | UnsupportedFormatException    | 415    |
 * | InvalidImageException         | 422    |
 * | LimitExceededException        | 413    |
 * | IsolationUnavailableException | 503    |
 * | ConcurrencyLimitException     | 503    |
 * | ConversionFailedException     | 500    |
 * | ConversionTimeoutException    | 504    |
 * | OutputVerificationException   | 500    |
 * | InvalidArgumentException      | 500    |
 *
 * ConversionTimeoutException extends ConversionFailedException, so a mapper must test it first.
 */
interface ExceptionInterface extends \Throwable
{
}
