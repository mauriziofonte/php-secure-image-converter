<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * The input exceeds one of the converter's limits (Capabilities::LIMIT_* : bytes, width, height, megapixels or
 * frames). Limits are checked on the file size and on header data, before any decoder runs.
 *
 * Expected HTTP status in the API: 413 Content Too Large.
 */
final class LimitExceededException extends \RangeException implements ExceptionInterface
{
}
