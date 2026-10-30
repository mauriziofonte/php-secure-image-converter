<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\Limits;

/**
 * GIF header parser: reads the logical screen, then walks every block up to the trailer, counting the images
 * (frames) and skipping the LZW data sub-blocks without decoding them.
 *
 * - The logical screen must have a width and height from 1, checked against the limits at once. Every image must be
 *   non-empty and lie inside the logical screen, and declare an LZW minimum code size from 1 to 11 (the range LZW
 *   decoders accept).
 * - The frame count is checked against the limits each time it exceeds Limits::maxFramesFor(), so a GIF with
 *   thousands of tiny frames is refused as soon as the limit is passed, without reading the rest of the file.
 * - Only extension, image and trailer blocks may appear; a missing trailer means a truncated file. At most
 *   MAX_BLOCKS length bytes (sub-block lengths and block introducers) are consumed, which bounds the work on a file
 *   made of tiny sub-blocks.
 *
 * Metadata: an ICCRGBG1 application extension is the colour profile; comments, XMP and unknown application
 * extensions are other metadata. NETSCAPE2.0 and ANIMEXTS1.0 loop extensions, graphic control and plain text
 * extensions are not metadata. Bytes after the trailer (the archive of a GIFAR) are trailing data.
 *
 * @internal
 */
final class GifParser implements ParserInterface
{
    /**
     * Largest number of blocks and sub-blocks walked. Encoders write image data in sub-blocks of 255 bytes, so a real
     * file reaches this only beyond 64 MB.
     */
    public const MAX_BLOCKS = 262144;

    /**
     * Application identifiers (8 bytes) and authentication codes (3 bytes) of the extensions that are not metadata.
     *
     * @var list<string>
     */
    private const ANIMATION_APPLICATIONS = ['NETSCAPE2.0', 'ANIMEXTS1.0'];

    private const ICC_APPLICATION = 'ICCRGBG1012';

    private const INTRODUCER_EXTENSION = 0x21;
    private const INTRODUCER_IMAGE = 0x2C;
    private const TRAILER = 0x3B;

    private const LABEL_COMMENT = 0xFE;
    private const LABEL_APPLICATION = 0xFF;

    public function parse(ByteReader $reader, Limits $limits): ImageInfo
    {
        $reader->seek(6);
        $width = $reader->readUint16Le();
        $height = $reader->readUint16Le();
        $flags = $reader->readUint8();
        $reader->skip(2);
        if ($width === 0 || $height === 0) {
            throw self::invalid('the logical screen has a zero width or height');
        }
        $limits->assertImage($width, $height, 1);
        $frameBudget = $limits->maxFramesFor($width, $height);
        self::skipColourTable($reader, $flags);

        $frames = 0;
        $blocks = 0;
        $iccProfile = false;
        $otherMetadata = false;

        while (true) {
            if (++$blocks > self::MAX_BLOCKS) {
                throw self::tooManyBlocks();
            }
            $introducer = $reader->readUint8();
            if ($introducer === self::TRAILER) {
                break;
            }

            if ($introducer === self::INTRODUCER_EXTENSION) {
                $label = $reader->readUint8();
                if ($label === self::LABEL_APPLICATION) {
                    $length = $reader->readUint8();
                    ++$blocks;
                    if ($length === 0) {
                        continue;
                    }
                    $identifier = $reader->read($length);
                    if ($identifier === self::ICC_APPLICATION) {
                        $iccProfile = true;
                    } elseif (!in_array($identifier, self::ANIMATION_APPLICATIONS, true)) {
                        $otherMetadata = true;
                    }
                } elseif ($label === self::LABEL_COMMENT) {
                    $otherMetadata = true;
                }
            } elseif ($introducer === self::INTRODUCER_IMAGE) {
                self::readImageDescriptor($reader, $width, $height);
                if (++$frames > $frameBudget) {
                    $limits->assertImage($width, $height, $frames);
                }
            } else {
                throw self::invalid('the file has an unknown block');
            }

            $consumed = $reader->skipSubBlocks(self::MAX_BLOCKS - $blocks);
            if ($consumed < 0) {
                throw self::tooManyBlocks();
            }
            $blocks += $consumed;
        }

        if ($frames === 0) {
            throw self::invalid('the file has no image');
        }
        $limits->assertImage($width, $height, $frames);

        return (new ImageInfo(ImageFormat::GIF, $width, $height, $frames, $reader->getSize()))
            ->withMetadata($iccProfile, false, $otherMetadata)
            ->withTrailingData($reader->getRemaining() > 0);
    }

    /**
     * Reads an image descriptor, its local colour table and its LZW minimum code size, leaving the reader on its
     * first data sub-block.
     *
     * @throws InvalidImageException
     */
    private static function readImageDescriptor(ByteReader $reader, int $screenWidth, int $screenHeight): void
    {
        $left = $reader->readUint16Le();
        $top = $reader->readUint16Le();
        $width = $reader->readUint16Le();
        $height = $reader->readUint16Le();
        $flags = $reader->readUint8();
        if ($width === 0 || $height === 0) {
            throw self::invalid('an image has a zero width or height');
        }
        if ($left + $width > $screenWidth || $top + $height > $screenHeight) {
            throw self::invalid('an image extends beyond the logical screen');
        }
        self::skipColourTable($reader, $flags);

        $codeSize = $reader->readUint8();
        if ($codeSize < 1 || $codeSize > 11) {
            throw self::invalid('an image has an invalid LZW minimum code size');
        }
    }

    /**
     * Skips the colour table announced by the flags of a logical screen or image descriptor, if any.
     *
     * @throws InvalidImageException
     */
    private static function skipColourTable(ByteReader $reader, int $flags): void
    {
        if (($flags & 0x80) !== 0) {
            $reader->skip(3 << (($flags & 0x07) + 1));
        }
    }

    private static function tooManyBlocks(): InvalidImageException
    {
        return self::invalid(sprintf('the file has more than %d blocks', self::MAX_BLOCKS));
    }

    private static function invalid(string $problem): InvalidImageException
    {
        return new InvalidImageException('Invalid GIF: ' . $problem . '.');
    }
}
