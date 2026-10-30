<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\Limits;

/**
 * PNG and APNG header parser: reads IHDR, then walks the chunk headers up to IEND, skipping every chunk payload by
 * seeking, without inflating any image data.
 *
 * - IHDR must be the first chunk, 13 bytes long, with a correct CRC, a width and height from 1 to 2^31 - 1, a valid
 *   colour type and bit depth pair, compression 0, filter 0 and interlace 0 or 1. The dimensions are checked against
 *   the limits right after it, so a decompression bomb (a huge image of compressible pixels) is refused before
 *   anything else is read.
 * - Chunk types must be four ASCII letters, lengths at most 2^31 - 1, and every chunk must fit in the file; an
 *   unknown critical chunk is refused, as decoders refuse it. PLTE must precede IDAT (and is required for colour type
 *   3, forbidden for grey types), IDAT chunks must be consecutive, IEND must be present and empty: a file cut anywhere
 *   is refused as truncated. At most MAX_CHUNKS chunks are walked.
 * - APNG: an acTL chunk before IDAT declares the frame count, checked against the limits at once; every fcTL (whose
 *   CRC is checked) must describe a non-empty region inside the canvas, and their number must equal the declared
 *   frame count.
 *
 * Metadata: iCCP is the colour profile, eXIf is EXIF (and gives the orientation); tEXt, zTXt, iTXt, tIME, sPLT and
 * hIST (a suggested palette of any length and a histogram, which decoders do not render), animation chunks outside an
 * animated PNG and every unknown ancillary chunk are other metadata. Colour, transparency and animation chunks are
 * not metadata, unless a colour or transparency chunk is longer than its fields for the colour type of the image (and,
 * for the transparency of a palette image, its number of palette entries), which decoders do not read beyond. Bytes
 * after IEND are trailing data.
 *
 * @internal
 */
final class PngParser implements ParserInterface
{
    /**
     * Largest number of chunks in a file, IHDR and IEND included. Encoders split the image data into IDAT chunks of
     * 8 KiB or more, so a real file reaches this only beyond 128 MB, or with more than 8000 animation frames.
     */
    public const MAX_CHUNKS = 16384;

    private const SIGNATURE = "\x89PNG\r\n\x1A\n";

    private const LENGTH_MAX = 0x7FFFFFFF;

    /**
     * Bit depths allowed for each colour type.
     *
     * @var array<int, list<int>>
     */
    private const BIT_DEPTHS = [
        0 => [1, 2, 4, 8, 16],
        2 => [8, 16],
        3 => [1, 2, 4, 8],
        4 => [8, 16],
        6 => [8, 16],
    ];

    /**
     * Ancillary chunks that describe how to render the image rather than metadata about it, with the length of their
     * fields: the animation chunks are checked apart, and the chunks of COLOUR_TYPE_CHUNKS are not listed.
     *
     * @var array<string, int>
     */
    private const RENDERING_CHUNKS = [
        'cHRM' => 32, 'cICP' => 4, 'cLLI' => 8, 'cLLi' => 8, 'gAMA' => 4, 'mDCV' => 24, 'mDCv' => 24, 'pHYs' => 9,
        'sRGB' => 1,
    ];

    /**
     * Rendering chunks whose fields depend on the colour type, with their length for each colour type that allows
     * them; the transparency of a palette image (colour type 3) has one byte per palette entry.
     *
     * @var array<string, array<int, int>>
     */
    private const COLOUR_TYPE_CHUNKS = [
        'bKGD' => [0 => 2, 2 => 6, 3 => 1, 4 => 2, 6 => 6],
        'sBIT' => [0 => 1, 2 => 3, 3 => 3, 4 => 2, 6 => 4],
        'tRNS' => [0 => 2, 2 => 6],
    ];

    private const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function parse(ByteReader $reader, Limits $limits): ImageInfo
    {
        $reader->seek(0);
        if ($reader->read(8) !== self::SIGNATURE) {
            throw self::invalid('the PNG signature is missing');
        }

        list($width, $height, $bitDepth, $colourType) = self::readHeader($reader);
        $limits->assertImage($width, $height, 1);
        $frameBudget = $limits->maxFramesFor($width, $height);

        $declaredFrames = null;
        $frameControls = 0;
        $palette = false;
        $paletteEntries = 0;
        $imageData = 0;
        $imageDataEnded = false;
        $iccProfile = false;
        $exif = false;
        $otherMetadata = false;
        $orientation = ImageInfo::ORIENTATION_NORMAL;

        for ($chunks = 2; ; ++$chunks) {
            if ($chunks > self::MAX_CHUNKS) {
                throw self::invalid(sprintf('the file has more than %d chunks', self::MAX_CHUNKS));
            }
            list($length, $type) = self::readChunkHeader($reader);
            if ($imageData > 0 && $type !== 'IDAT') {
                $imageDataEnded = true;
            }

            switch ($type) {
                case 'IEND':
                    if ($length !== 0) {
                        throw self::invalid('the IEND chunk is not empty');
                    }
                    $reader->skip(4);
                    break 2;
                case 'IDAT':
                    if ($imageDataEnded) {
                        throw self::invalid('the IDAT chunks are not consecutive');
                    }
                    ++$imageData;
                    break;
                case 'IHDR':
                    throw self::invalid('the file has more than one IHDR chunk');
                case 'PLTE':
                    if ($palette || $imageData > 0 || $colourType === 0 || $colourType === 4) {
                        throw self::invalid('the PLTE chunk is misplaced or not allowed for this colour type');
                    }
                    if ($length === 0 || $length > 768 || $length % 3 !== 0) {
                        throw self::invalid('the PLTE chunk has an invalid length');
                    }
                    $palette = true;
                    $paletteEntries = intdiv($length, 3);
                    break;
                case 'acTL':
                    if ($declaredFrames !== null || $imageData > 0 || $length !== 8) {
                        throw self::invalid('the acTL chunk is misplaced, repeated or of a wrong length');
                    }
                    $declaredFrames = self::unpackUint32(self::readCheckedPayload($reader, $type, $length), 0);
                    if ($declaredFrames === 0) {
                        throw self::invalid('the acTL chunk declares no frame');
                    }
                    if ($declaredFrames > $frameBudget) {
                        $limits->assertImage($width, $height, $declaredFrames);
                    }

                    continue 2;
                case 'fcTL':
                    if ($declaredFrames === null) {
                        // Frame control outside an animated PNG: libpng and ImageMagick ignore it (Pillow refuses the
                        // file), an unknown ancillary chunk here.
                        $otherMetadata = true;
                        break;
                    }
                    if ($length !== 26) {
                        throw self::invalid('an fcTL chunk has a wrong length');
                    }
                    if (++$frameControls > $declaredFrames) {
                        throw self::invalid('the file has more frames than its acTL chunk declares');
                    }
                    self::checkFrameControl(self::readCheckedPayload($reader, $type, $length), $width, $height);

                    continue 2;
                case 'fdAT':
                    // Frame data outside an animated PNG: libpng and ImageMagick ignore it (Pillow refuses the file).
                    $otherMetadata = $otherMetadata || $declaredFrames === null;
                    break;
                case 'iCCP':
                    $iccProfile = true;
                    break;
                case 'eXIf':
                    if (!$exif) {
                        $payloadStart = $reader->getPosition();
                        $orientation = ExifOrientation::read($reader, $payloadStart, $length);
                        $reader->seek($payloadStart);
                    }
                    $exif = true;
                    break;
                default:
                    if ((ord($type[0]) & 0x20) === 0) {
                        throw self::invalid('the file has an unknown critical chunk');
                    }
                    if ($length > self::fieldsLength($type, $colourType, $paletteEntries)) {
                        $otherMetadata = true;
                    }
            }
            $reader->skip($length + 4);
        }

        if ($imageData === 0) {
            throw self::invalid('the file has no IDAT chunk');
        }
        if ($colourType === 3 && !$palette) {
            throw self::invalid('the palette image has no PLTE chunk');
        }
        $frameCount = 1;
        if ($declaredFrames !== null) {
            if ($frameControls !== $declaredFrames) {
                throw self::invalid('the number of fcTL chunks differs from the frame count of the acTL chunk');
            }
            $frameCount = $declaredFrames;
        }
        $limits->assertImage($width, $height, $frameCount);

        return (new ImageInfo(ImageFormat::PNG, $width, $height, $frameCount, $reader->getSize()))
            ->withBitDepth($bitDepth === 16 ? 16 : 8)
            ->withMetadata($iccProfile, $exif, $otherMetadata)
            ->withOrientation($orientation)
            ->withTrailingData($reader->getRemaining() > 0);
    }

    /**
     * @throws InvalidImageException
     *
     * @return array{0: int, 1: int, 2: int, 3: int} width, height, bit depth and colour type
     */
    private static function readHeader(ByteReader $reader): array
    {
        if ($reader->read(8) !== "\x00\x00\x00\x0DIHDR") {
            throw self::invalid('the first chunk is not a 13-byte IHDR chunk');
        }
        $data = self::readCheckedPayload($reader, 'IHDR', 13);

        $width = self::unpackUint32($data, 0);
        $height = self::unpackUint32($data, 4);
        $bitDepth = ord($data[8]);
        $colourType = ord($data[9]);
        if ($width === 0 || $height === 0 || $width > self::LENGTH_MAX || $height > self::LENGTH_MAX) {
            throw self::invalid('the IHDR chunk declares a zero or out-of-range width or height');
        }
        if (!isset(self::BIT_DEPTHS[$colourType]) || !in_array($bitDepth, self::BIT_DEPTHS[$colourType], true)) {
            throw self::invalid('the IHDR chunk declares an invalid colour type and bit depth pair');
        }
        if ($data[10] !== "\0" || $data[11] !== "\0" || ord($data[12]) > 1) {
            throw self::invalid('the IHDR chunk declares an unknown compression, filter or interlace method');
        }

        return [$width, $height, $bitDepth, $colourType];
    }

    /**
     * Reads a chunk length and type, and checks that the payload and CRC fit in the file.
     *
     * @throws InvalidImageException
     *
     * @return array{0: int, 1: string}
     */
    private static function readChunkHeader(ByteReader $reader): array
    {
        $header = $reader->read(8);
        $length = self::unpackUint32($header, 0);
        $type = substr($header, 4, 4);
        if ($length > self::LENGTH_MAX || strspn($type, self::LETTERS) !== 4) {
            throw self::invalid('a chunk has an invalid length or type');
        }
        if ($length + 4 > $reader->getRemaining()) {
            throw ByteReader::truncated();
        }

        return [$length, $type];
    }

    /**
     * Reads a chunk payload and its CRC, which must match.
     *
     * @throws InvalidImageException
     */
    private static function readCheckedPayload(ByteReader $reader, string $type, int $length): string
    {
        $data = $reader->read($length);
        if (self::unpackUint32($reader->read(4), 0) !== crc32($type . $data)) {
            throw self::invalid(sprintf('the %s chunk has a wrong CRC', $type));
        }

        return $data;
    }

    /**
     * Length of the fields of a rendering chunk in an image of this colour type and number of palette entries (0 when
     * the colour type does not allow the chunk), or -1 for a chunk that is not a rendering chunk.
     */
    private static function fieldsLength(string $type, int $colourType, int $paletteEntries): int
    {
        if ($type === 'tRNS' && $colourType === 3) {
            return $paletteEntries;
        }
        if (isset(self::COLOUR_TYPE_CHUNKS[$type])) {
            return self::COLOUR_TYPE_CHUNKS[$type][$colourType] ?? 0;
        }

        return self::RENDERING_CHUNKS[$type] ?? -1;
    }

    /**
     * @throws InvalidImageException
     */
    private static function checkFrameControl(string $data, int $width, int $height): void
    {
        $frameWidth = self::unpackUint32($data, 4);
        $frameHeight = self::unpackUint32($data, 8);
        $left = self::unpackUint32($data, 12);
        $top = self::unpackUint32($data, 16);
        if ($frameWidth === 0 || $frameHeight === 0 || $frameWidth > $width || $frameHeight > $height
            || $left > $width - $frameWidth || $top > $height - $frameHeight) {
            throw self::invalid('an fcTL chunk describes a frame outside the canvas');
        }
        if (ord($data[24]) > 2 || ord($data[25]) > 1) {
            throw self::invalid('an fcTL chunk has an unknown dispose or blend operation');
        }
    }

    private static function unpackUint32(string $bytes, int $offset): int
    {
        $value = unpack('N', $bytes, $offset);

        return is_array($value) && is_int($value[1]) ? $value[1] : 0;
    }

    private static function invalid(string $problem): InvalidImageException
    {
        return new InvalidImageException('Invalid PNG: ' . $problem . '.');
    }
}
