<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * A backend produced an output that does not match what was requested: the format sniffed from its content, its
 * dimensions or its frame count differ from the expected ones, or the file is not a valid image. The output is
 * discarded and never returned to the caller.
 *
 * Expected HTTP status in the API: 500 Internal Server Error.
 */
final class OutputVerificationException extends \UnexpectedValueException implements ExceptionInterface
{
}
