<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * A caller passed a value outside the documented domain of a method that does not deal with request parameters: an
 * unknown isolation level, inconsistent ConversionResult values, a malformed Capabilities array, a missing input
 * file. This is a programming, configuration or integration error, never the end user's fault; request parameters
 * are reported with InvalidRequestException instead.
 *
 * Expected HTTP status in the API: 500 Internal Server Error.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements ExceptionInterface
{
}
