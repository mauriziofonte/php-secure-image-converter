<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * The input cannot be processed as an image: it is malformed or truncated, it is spoofed (its content does not
 * match the format it claims or that its header announces), or it is animated while the request's animation policy
 * is ConversionRequest::ANIMATION_REJECT.
 *
 * Expected HTTP status in the API: 422 Unprocessable Content.
 */
final class InvalidImageException extends \UnexpectedValueException implements ExceptionInterface
{
}
