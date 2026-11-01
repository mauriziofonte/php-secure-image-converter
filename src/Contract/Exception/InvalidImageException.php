<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * The input's format was identified but its content cannot be processed: it is malformed or truncated, or it is
 * spoofed (its content does not match the format its header announces), or its header is valid but the decoder failed
 * or crashed on its data while it converts a valid image of the same format. Error code ErrorCode::INVALID_IMAGE.
 *
 * animationRejected() reports a valid animated input refused because the request's animation policy is
 * ConversionRequest::ANIMATION_REJECT. Error code ErrorCode::ANIMATION_REJECTED.
 *
 * Expected HTTP status in the API: 422 Unprocessable Content.
 */
final class InvalidImageException extends \UnexpectedValueException implements ExceptionInterface
{
    /**
     * @var string
     */
    private $errorCode = ErrorCode::INVALID_IMAGE;

    /**
     * Creates the exception for an animated input refused by the animation policy ConversionRequest::ANIMATION_REJECT.
     *
     * @param int $frameCount number of frames of the input, at least 2
     */
    public static function animationRejected(int $frameCount): self
    {
        $exception = new self(sprintf(
            'The input is animated (%d frames) and the request\'s animation policy is reject.',
            $frameCount
        ));
        $exception->errorCode = ErrorCode::ANIMATION_REJECTED;

        return $exception;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
