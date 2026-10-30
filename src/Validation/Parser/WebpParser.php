<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\Limits;

/**
 * WEBP header parser: reads the RIFF container and walks its chunks, reading only the few bytes of the VP8, VP8L and
 * VP8X headers that hold dimensions and flags, and the 16-byte header of each ANMF frame. Bitstreams are never
 * decoded.
 *
 * - The RIFF size must cover at least one chunk and fit in the file (bytes after it are ignored, as decoders ignore
 *   them); every chunk must fit in the RIFF payload. At most MAX_CHUNKS chunks are walked.
 * - Simple lossy (VP8): a key frame that is shown, a first partition inside the chunk, the start code 9D 01 2A and
 *   a non-zero 14-bit width and height. Simple lossless (VP8L): the signature 2F, version 0, 14-bit dimensions.
 * - Extended (VP8X): a 24-bit canvas width and height whose product fits in 32 bits, checked against the limits at
 *   once. With the animation flag, ANIM must be present and every ANMF frame must lie inside the canvas; their number
 *   is the frame count, checked against the limits as soon as it exceeds Limits::maxFramesFor(). Without it, exactly
 *   one VP8 or VP8L bitstream must be present, with the canvas dimensions, and no ANMF chunk.
 *
 * Metadata: ICCP is the colour profile, EXIF is EXIF; XMP and unknown chunks are other metadata.
 *
 * @internal
 */
final class WebpParser implements ParserInterface
{
    /**
     * Largest number of chunks walked in an extended file. Frames are bounded earlier by the frame limits.
     */
    public const MAX_CHUNKS = 16384;

    /**
     * Product of the canvas width and height allowed by the specification.
     */
    private const CANVAS_AREA_MAX = 4294967295;

    private const FLAG_ANIMATION = 0x02;

    /**
     * Chunks of an extended file that are neither image data nor metadata.
     *
     * @var list<string>
     */
    private const STRUCTURE_CHUNKS = ['ANIM', 'ALPH'];

    public function parse(ByteReader $reader, Limits $limits): ImageInfo
    {
        $reader->seek(0);
        $header = $reader->read(12);
        if (strncmp($header, 'RIFF', 4) !== 0 || substr($header, 8, 4) !== 'WEBP') {
            throw self::invalid('the RIFF WEBP header is missing');
        }
        $riffSize = self::unpackUint32Le($header, 4);
        if ($riffSize < 12) {
            throw self::invalid('the RIFF size is too small to hold an image');
        }
        if ($riffSize > $reader->getSize() - 8) {
            throw ByteReader::truncated();
        }
        $end = 8 + $riffSize;

        list($type, $length) = self::readChunkHeader($reader, $end);
        if ($type === 'VP8 ' || $type === 'VP8L') {
            list($width, $height) = self::readBitstreamSize($reader, $type, $length);
            $limits->assertImage($width, $height, 1);

            return (new ImageInfo(ImageFormat::WEBP, $width, $height, 1, $reader->getSize()));
        }
        if ($type !== 'VP8X') {
            throw self::invalid('the first chunk is not VP8, VP8L or VP8X');
        }

        return self::parseExtended($reader, $limits, $length, $end);
    }

    /**
     * @throws InvalidImageException
     * @throws \Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException
     */
    private static function parseExtended(ByteReader $reader, Limits $limits, int $length, int $end): ImageInfo
    {
        if ($length < 10) {
            throw self::invalid('the VP8X chunk is too short');
        }
        $flags = $reader->readUint8();
        $reader->skip(3);
        $width = $reader->readUint24Le() + 1;
        $height = $reader->readUint24Le() + 1;
        if ($width * $height > self::CANVAS_AREA_MAX) {
            throw self::invalid('the canvas area exceeds 2^32 - 1 pixels');
        }
        $limits->assertImage($width, $height, 1);
        $frameBudget = $limits->maxFramesFor($width, $height);
        $animated = ($flags & self::FLAG_ANIMATION) !== 0;
        $reader->seek(self::chunkEnd(12 + 8, $length));

        $frames = 0;
        $bitstreams = 0;
        $animationHeader = false;
        $iccProfile = false;
        $exif = false;
        $otherMetadata = false;

        for ($chunks = 2; $reader->getPosition() < $end; ++$chunks) {
            if ($chunks > self::MAX_CHUNKS) {
                throw self::invalid(sprintf('the file has more than %d chunks', self::MAX_CHUNKS));
            }
            $start = $reader->getPosition();
            list($type, $length) = self::readChunkHeader($reader, $end);

            switch ($type) {
                case 'ANMF':
                    if (!$animated || !$animationHeader) {
                        throw self::invalid('an ANMF frame appears outside an animation or before ANIM');
                    }
                    self::checkFrame($reader, $length, $width, $height);
                    if (++$frames > $frameBudget) {
                        $limits->assertImage($width, $height, $frames);
                    }
                    break;
                case 'VP8 ':
                case 'VP8L':
                    if ($animated || ++$bitstreams > 1) {
                        throw self::invalid('a still image must hold exactly one bitstream outside ANMF frames');
                    }
                    if (self::readBitstreamSize($reader, $type, $length) !== [$width, $height]) {
                        throw self::invalid('the bitstream dimensions differ from the canvas');
                    }
                    break;
                case 'ANIM':
                    $animationHeader = true;
                    break;
                case 'ICCP':
                    $iccProfile = true;
                    break;
                case 'EXIF':
                    $exif = true;
                    break;
                default:
                    if (!in_array($type, self::STRUCTURE_CHUNKS, true)) {
                        $otherMetadata = true;
                    }
            }
            $reader->seek(self::chunkEnd($start + 8, $length));
        }

        if ($animated) {
            if ($frames === 0) {
                throw self::invalid('the animation has no ANMF frame');
            }
        } elseif ($bitstreams === 0) {
            throw self::invalid('the file has no VP8 or VP8L bitstream');
        }
        $frameCount = max(1, $frames);
        $limits->assertImage($width, $height, $frameCount);

        return (new ImageInfo(ImageFormat::WEBP, $width, $height, $frameCount, $reader->getSize()))
            ->withMetadata($iccProfile, $exif, $otherMetadata);
    }

    /**
     * Reads a chunk FourCC and size, and checks that the chunk fits before the end of the RIFF payload.
     *
     * @throws InvalidImageException
     *
     * @return array{0: string, 1: int}
     */
    private static function readChunkHeader(ByteReader $reader, int $end): array
    {
        if ($end - $reader->getPosition() < 8) {
            throw self::invalid('a chunk header is cut by the end of the RIFF payload');
        }
        $header = $reader->read(8);
        $length = self::unpackUint32Le($header, 4);
        if ($length > $end - $reader->getPosition()) {
            throw $end === $reader->getSize() ? ByteReader::truncated() : self::invalid('a chunk overflows the RIFF payload');
        }

        return [substr($header, 0, 4), $length];
    }

    /**
     * Offset after a chunk payload, which is padded to an even length.
     */
    private static function chunkEnd(int $payloadStart, int $length): int
    {
        return $payloadStart + $length + ($length & 1);
    }

    /**
     * Reads the dimensions of a VP8 or VP8L bitstream.
     *
     * @throws InvalidImageException
     *
     * @return array{0: int, 1: int}
     */
    private static function readBitstreamSize(ByteReader $reader, string $type, int $length): array
    {
        if ($type === 'VP8L') {
            if ($length < 5) {
                throw self::invalid('the VP8L bitstream is too short');
            }
            $header = $reader->read(5);
            $bits = self::unpackUint32Le($header, 1);
            if ($header[0] !== "\x2F" || ($bits >> 29) !== 0) {
                throw self::invalid('the VP8L bitstream has a wrong signature or version');
            }

            return [($bits & 0x3FFF) + 1, (($bits >> 14) & 0x3FFF) + 1];
        }

        if ($length < 10) {
            throw self::invalid('the VP8 bitstream is too short');
        }
        $header = $reader->read(10);
        $tag = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
        $keyFrame = ($tag & 0x01) === 0;
        $version = ($tag >> 1) & 0x07;
        $shown = ($tag >> 4) & 0x01;
        $firstPartitionSize = $tag >> 5;
        if (!$keyFrame || $version > 3 || $shown !== 1 || $firstPartitionSize > $length - 10) {
            throw self::invalid('the VP8 frame header is not a shown key frame of a valid size');
        }
        if (substr($header, 3, 3) !== "\x9D\x01\x2A") {
            throw self::invalid('the VP8 start code is missing');
        }
        $width = (ord($header[6]) | (ord($header[7]) << 8)) & 0x3FFF;
        $height = (ord($header[8]) | (ord($header[9]) << 8)) & 0x3FFF;
        if ($width === 0 || $height === 0) {
            throw self::invalid('the VP8 bitstream declares a zero width or height');
        }

        return [$width, $height];
    }

    /**
     * Checks the 16-byte header of an ANMF frame: its rectangle must lie inside the canvas.
     *
     * @throws InvalidImageException
     */
    private static function checkFrame(ByteReader $reader, int $length, int $canvasWidth, int $canvasHeight): void
    {
        if ($length < 16 + 8) {
            throw self::invalid('an ANMF frame is too short');
        }
        $left = 2 * $reader->readUint24Le();
        $top = 2 * $reader->readUint24Le();
        $width = $reader->readUint24Le() + 1;
        $height = $reader->readUint24Le() + 1;
        if ($left + $width > $canvasWidth || $top + $height > $canvasHeight) {
            throw self::invalid('an ANMF frame extends beyond the canvas');
        }
    }

    private static function unpackUint32Le(string $bytes, int $offset): int
    {
        $value = unpack('V', $bytes, $offset);

        return is_array($value) && is_int($value[1]) ? $value[1] : 0;
    }

    private static function invalid(string $problem): InvalidImageException
    {
        return new InvalidImageException('Invalid WEBP: ' . $problem . '.');
    }
}
