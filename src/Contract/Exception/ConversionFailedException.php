<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * The conversion could not be completed for a reason internal to the converter: no backend could handle the
 * request, a backend exited with an error or was killed by a resource limit, or the output could not be written.
 *
 * Expected HTTP status in the API: 500 Internal Server Error. ConversionTimeoutException is a subtype with its own
 * status.
 */
class ConversionFailedException extends \RuntimeException implements ExceptionInterface
{
}
