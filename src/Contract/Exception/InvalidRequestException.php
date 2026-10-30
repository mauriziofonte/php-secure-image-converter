<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * A conversion request is invalid: a parameter is unknown, malformed or out of range, or the request asks for
 * something the converter's limits do not allow (for example a target width above max_width).
 *
 * Thrown by ConversionRequest (constructor, with*() methods and fromParameters()) and by
 * ConverterInterface::convert(). The message never contains raw user input that is not a short, printable token, so
 * it can be returned to the client as is.
 *
 * Expected HTTP status in the API: 400 Bad Request.
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

    /**
     * Canonical name of the offending parameter, or null when the problem is not tied to a known parameter.
     */
    public function getParameter(): ?string
    {
        return $this->parameter;
    }
}
