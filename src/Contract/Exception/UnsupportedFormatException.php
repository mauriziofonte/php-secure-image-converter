<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * An image format is not supported: the format detected from the input's content is not enabled for input, or a
 * format name or MIME type is not one of ImageFormat's values.
 *
 * Expected HTTP status in the API: 415 Unsupported Media Type.
 */
final class UnsupportedFormatException extends \UnexpectedValueException implements ExceptionInterface
{
}
