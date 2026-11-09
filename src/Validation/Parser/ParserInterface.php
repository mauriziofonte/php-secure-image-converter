<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\Limits;

/**
 * Reads the header of one image format and describes the image, without decoding any pixel data.
 *
 * Implementations read only the structures that hold dimensions, frames and metadata markers, skip everything else
 * by seeking, and bound every loop by a constant or by the size of the file. They check the dimensions against the
 * limits as soon as the header declares them and, where frames are counted one by one, the frame count as soon as it
 * exceeds Limits::maxFramesFor(), so that a bomb is refused before the rest of the file is looked at.
 *
 * @internal
 */
interface ParserInterface
{
    /**
     * Describes the image held by the reader, whose format has been identified by FormatSniffer.
     *
     * @throws InvalidImageException      the header is malformed, truncated, inconsistent or spoofed
     * @throws LimitExceededException     a dimension, pixel or frame limit is exceeded
     * @throws UnsupportedFormatException the image uses a variant of the format this library does not process
     */
    public function parse(ByteReader $reader, Limits $limits): ImageInfo;
}
