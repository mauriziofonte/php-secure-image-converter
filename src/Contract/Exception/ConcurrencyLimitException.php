<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * Every conversion slot is busy and no slot became free within the configured wait time. The request can be retried
 * later.
 *
 * Expected HTTP status in the API: 503 Service Unavailable (with a Retry-After header).
 */
final class ConcurrencyLimitException extends \OverflowException implements ExceptionInterface
{
}
