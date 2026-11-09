<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * Stable, machine-readable error codes returned by ExceptionInterface::getErrorCode().
 *
 * A code never changes meaning once released: the conversion API returns it in its error body and maps it to an
 * HTTP status, and the proxy logs it. Callers decide on the code, never on the exception message, which is for
 * humans and may change. The library itself knows nothing about HTTP; the status column is the mapping the API is
 * expected to apply:
 *
 * | Code                         | Status | Thrown as                     | Meaning                                       |
 * | ---------------------------- | ------ | ----------------------------- | --------------------------------------------- |
 * | `invalid_request`            | 400    | InvalidRequestException       | parameter unknown, malformed or out of the    |
 * |                              |        |                               | contract's static range                       |
 * | `unsupported_format`         | 415    | UnsupportedFormatException    | input format unidentifiable or not enabled,   |
 * |                              |        |                               | or a variant of it that is not accepted (see  |
 * |                              |        |                               | UnsupportedFormatException)                   |
 * | `invalid_image`              | 422    | InvalidImageException         | identified but malformed, truncated, spoofed, |
 * |                              |        |                               | or failing a decoder that converts a valid    |
 * |                              |        |                               | image of its format                           |
 * | `animation_rejected`         | 422    | InvalidImageException         | animated input with animation policy reject   |
 * | `file_too_large`             | 413    | LimitExceededException        | input byte size above max_bytes               |
 * | `image_too_large`            | 422    | LimitExceededException        | input or resolved output above a dimension,   |
 * |                              |        |                               | pixel or frame limit                          |
 * | `conversion_timeout`         | 422    | ConversionTimeoutException    | processing budget exhausted or exceeded by    |
 * |                              |        |                               | the estimate; a retry will not help           |
 * | `resource_limit`             | 422    | ResourceLimitException        | a conversion process ran out of its memory,   |
 * |                              |        |                               | CPU time or file size budget; a retry will    |
 * |                              |        |                               | not help                                      |
 * | `isolation_unavailable`      | 503    | IsolationUnavailableException | required isolation level missing on the host  |
 * | `concurrency_limit`          | 503    | ConcurrencyLimitException     | no conversion slot or key lock free in time;  |
 * |                              |        |                               | retry later (Retry-After from the exception)  |
 * | `conversion_failed`          | 500    | ConversionFailedException     | no backend could produce the output, or one   |
 * |                              |        |                               | failed on a valid image too                   |
 * | `output_verification_failed` | 500    | OutputVerificationException   | the produced output was not what was asked    |
 * | `internal_error`             | 500    | InvalidArgumentException      | programming, configuration or integration     |
 * |                              |        |                               | error                                         |
 */
final class ErrorCode
{
    public const INVALID_REQUEST = 'invalid_request';
    public const UNSUPPORTED_FORMAT = 'unsupported_format';
    public const INVALID_IMAGE = 'invalid_image';
    public const ANIMATION_REJECTED = 'animation_rejected';
    public const FILE_TOO_LARGE = 'file_too_large';
    public const IMAGE_TOO_LARGE = 'image_too_large';
    public const CONVERSION_TIMEOUT = 'conversion_timeout';
    public const RESOURCE_LIMIT = 'resource_limit';
    public const ISOLATION_UNAVAILABLE = 'isolation_unavailable';
    public const CONCURRENCY_LIMIT = 'concurrency_limit';
    public const CONVERSION_FAILED = 'conversion_failed';
    public const OUTPUT_VERIFICATION_FAILED = 'output_verification_failed';
    public const INTERNAL_ERROR = 'internal_error';

    private function __construct()
    {
    }

    /**
     * Every error code, in the order of the table above.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::INVALID_REQUEST,
            self::UNSUPPORTED_FORMAT,
            self::INVALID_IMAGE,
            self::ANIMATION_REJECTED,
            self::FILE_TOO_LARGE,
            self::IMAGE_TOO_LARGE,
            self::CONVERSION_TIMEOUT,
            self::RESOURCE_LIMIT,
            self::ISOLATION_UNAVAILABLE,
            self::CONCURRENCY_LIMIT,
            self::CONVERSION_FAILED,
            self::OUTPUT_VERIFICATION_FAILED,
            self::INTERNAL_ERROR,
        ];
    }
}
