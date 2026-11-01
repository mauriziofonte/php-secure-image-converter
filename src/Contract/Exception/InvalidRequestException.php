<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * A conversion request is invalid: a parameter is unknown, malformed, or outside the static range documented by
 * ConversionRequest (for example a width above ConversionRequest::DIMENSION_MAX). A valid request whose resolved
 * output exceeds the converter's limits is reported with LimitExceededException instead.
 *
 * Thrown by ConversionRequest (constructor, with*() methods and fromParameters()). The message never contains raw
 * user input that is not a short, printable token, so it can be returned to the client as is.
 *
 * Error code ErrorCode::INVALID_REQUEST; expected HTTP status in the API: 400 Bad Request.
 */
final class InvalidRequestException extends \InvalidArgumentException implements ExceptionInterface
{
    /**
     * @var string|null
     */
    private $parameter;

    /**
     * Creates the exception for one parameter.
     *
     * @param string|null $parameter canonical parameter name (see ConversionRequest::parameterNames()), or null when
     *                               the offending key is not a known parameter
     * @param string      $problem   what is wrong, as a sentence fragment without final full stop
     */
    public static function forParameter(?string $parameter, string $problem): self
    {
        $exception = new self(
            $parameter === null
                ? sprintf('Invalid request: %s.', $problem)
                : sprintf('Invalid parameter "%s": %s.', $parameter, $problem)
        );
        $exception->parameter = $parameter;

        return $exception;
    }

    public function getErrorCode(): string
    {
        return ErrorCode::INVALID_REQUEST;
    }

    /**
     * Canonical name of the offending parameter, or null when the problem is not tied to a known parameter.
     */
    public function getParameter(): ?string
    {
        return $this->parameter;
    }
}
