<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * The conversion did not complete within its wall-clock timeout and its process was killed.
 *
 * Expected HTTP status in the API: 504 Gateway Timeout.
 */
final class ConversionTimeoutException extends ConversionFailedException
{
}
