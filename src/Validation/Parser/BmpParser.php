<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\Limits;

/**
 * BMP header parser: reads the file header and the DIB header (core, info, V2, V3, V4 or V5), and checks that the
 * pixel data it announces fits in the file, without reading it.
 *
 * - Width from 1, height non-zero (negative means top-down rows, allowed for uncompressed and bit-field images
 *   only); the core header has unsigned 16-bit dimensions. The dimensions are checked against the limits at once.
 * - One plane; bit count and compression must form a valid pair: BI_RGB with 1, 2, 4, 8, 16, 24, 32 or 64 bits,
 *   RLE8 with 8, RLE4 with 4, BI_BITFIELDS and BI_ALPHABITFIELDS with 16 or 32. A palette may not have more colours
 *   than the bit count allows.
 * - The pixel data offset must lie after the headers and the bit masks, inside the file, and the uncompressed
 *   pixel array (or the declared size of compressed data) must fit between that offset and the end of the file:
 *   a truncated file is refused.
 * - Embedded JPEG or PNG data (BI_JPEG, BI_PNG), CMYK compressions, the OS/2 2.x headers and a colour profile linked
 *   by file name are variants this library does not process: they are refused as unsupported formats. An embedded
 *   colour profile (V5 PROFILE_EMBEDDED) must lie inside the file.
 *
 * - ImageMagick reads one more image, of any size, when the two bytes where it stops reading the image are the magic
 *   number of a BMP or OS/2 bitmap ("BA", "BM", "IC", "PI", "PT", "CI" or "CP", in any case): after the pixel data, or
 *   after the colour profile, which it reads last. Such a file is refused as unsupported. Run-length encoded data ends
 *   at its end-of-bitmap escape, its last row or its last pixel, wherever its declared size says: it is walked as
 *   ImageMagick walks it, without producing pixels, up to MAX_RUNS runs (an escape or the first of consecutive runs
 *   counting as ESCAPE_COST runs); longer data is refused as unsupported.
 *
 * Bit-field and alpha bit-field compressions, 2 and 64 bits per pixel and the V2 and V3 headers are reported as
 * variants (ImageInfo::VARIANT_BMP_*), which some decoders do not read.
 *
 * BMP carries no metadata besides the colour profile. The bit depth is 16 for 64-bit images and 8 otherwise. Decoders
 * read the headers and bit masks, the palette of an image of 8 bits or less (images of more bits have none to read,
 * whatever the colours-used field says), the pixel data (as far as its declared size for run-length encoded data) and
 * the colour profile: any other byte of the file, before, between or after them, is trailing data.
 *
 * @internal
 */
final class BmpParser implements ParserInterface
{
    /**
     * Largest number of runs walked in run-length encoded data, an escape or the first of consecutive runs counting as
     * ESCAPE_COST runs. A run covers up to 255 pixels, so real images stay far below it; ImageMagick codes a noisy
     * image in runs of one pixel, which reach it from about 390,000 pixels.
     */
    public const MAX_RUNS = 393216;

    /**
     * Runs that an escape (end of line, end of bitmap, delta or absolute run) or the first of consecutive runs counts
     * for: walking one costs about as much as walking three runs in a row.
     */
    public const ESCAPE_COST = 3;

    /**
     * Bytes of run-length encoded data read at once.
     */
    private const RUN_CHUNK_SIZE = 65536;

    private const FILE_HEADER_SIZE = 14;

    private const CORE_HEADER_SIZE = 12;

    /**
     * DIB header sizes read by this parser: info (40), V2 (52), V3 (56), V4 (108) and V5 (124).
     *
     * @var list<int>
     */
    private const INFO_HEADER_SIZES = [40, 52, 56, 108, 124];

    private const BI_RGB = 0;
    private const BI_RLE8 = 1;
    private const BI_RLE4 = 2;
    private const BI_BITFIELDS = 3;
    private const BI_JPEG = 4;
    private const BI_PNG = 5;
    private const BI_ALPHABITFIELDS = 6;

    /**
     * Bit counts allowed for each compression.
     *
     * @var array<int, list<int>>
     */
    private const BIT_COUNTS = [
        self::BI_RGB => [1, 2, 4, 8, 16, 24, 32, 64],
        self::BI_RLE8 => [8],
        self::BI_RLE4 => [4],
        self::BI_BITFIELDS => [16, 32],
        self::BI_ALPHABITFIELDS => [16, 32],
    ];

    /**
     * V5 colour space types: a profile embedded in the file ("MBED") or linked by file name ("LINK").
     */
    private const PROFILE_EMBEDDED = 0x4D424544;
    private const PROFILE_LINKED = 0x4C494E4B;

    public function parse(ByteReader $reader, Limits $limits): ImageInfo
    {
        $reader->seek(0);
        if ($reader->read(2) !== 'BM') {
            throw self::invalid('the BM signature is missing');
        }
        $reader->skip(8);
        $dataOffset = $reader->readUint32Le();
        $headerSize = $reader->readUint32Le();

        if ($headerSize === self::CORE_HEADER_SIZE) {
            $width = $reader->readUint16Le();
            $height = $reader->readUint16Le();
            $planes = $reader->readUint16Le();
            $bitCount = $reader->readUint16Le();
            $topDown = false;
            $compression = self::BI_RGB;
            $colours = 0;
            $compressedSize = 0;
            $maskSize = 0;
            $paletteEntrySize = 3;
            if (!in_array($bitCount, [1, 4, 8, 24], true)) {
                throw self::invalid('the core header declares an invalid bit count');
            }
        } elseif (in_array($headerSize, self::INFO_HEADER_SIZES, true)) {
            $width = self::signed($reader->readUint32Le());
            $height = self::signed($reader->readUint32Le());
            $planes = $reader->readUint16Le();
            $bitCount = $reader->readUint16Le();
            $compression = $reader->readUint32Le();
            $compressedSize = $reader->readUint32Le();
            $reader->skip(8);
            $colours = $reader->readUint32Le();
            $topDown = $height < 0;
            if ($height === -2147483648) {
                throw self::invalid('the info header declares an out-of-range height');
            }
            $height = abs($height);
            $maskSize = $headerSize === 40 && $compression === self::BI_BITFIELDS ? 12 : 0;
            $maskSize = $headerSize === 40 && $compression === self::BI_ALPHABITFIELDS ? 16 : $maskSize;
            $paletteEntrySize = 4;
            self::checkCompression($compression, $bitCount, $topDown);
        } else {
            throw new UnsupportedFormatException('This BMP variant (OS/2 2.x or unknown header) is not supported.');
        }

        if ($width < 1 || $height < 1) {
            throw self::invalid('the header declares a zero or negative width or height');
        }
        if ($planes !== 1) {
            throw self::invalid('the header declares a plane count other than 1');
        }
        if ($bitCount <= 8 && $colours > (1 << $bitCount)) {
            throw self::invalid('the palette has more colours than the bit count allows');
        }
        $limits->assertImage($width, $height, 1);

        $headersEnd = self::FILE_HEADER_SIZE + $headerSize + $maskSize;
        $size = $reader->getSize();
        if ($headersEnd > $size || $dataOffset > $size) {
            throw ByteReader::truncated();
        }
        if ($dataOffset < $headersEnd) {
            throw self::invalid('the pixel data offset points inside the headers');
        }
        // The palette may be shorter than the bit count allows, but it must fit before the pixel data.
        if ($bitCount <= 8 && $colours > 0 && $colours * $paletteEntrySize > $dataOffset - $headersEnd) {
            throw self::invalid('the palette overlaps the pixel data');
        }

        if ($compression === self::BI_RLE8 || $compression === self::BI_RLE4) {
            if ($compressedSize === 0 || $compressedSize > $size - $dataOffset) {
                throw ByteReader::truncated();
            }
            $end = $dataOffset + $compressedSize;
        } else {
            // Rows are padded to 4 bytes. The limits bound width and height below 2^31, so the stride fits in 64
            // bits; the product with the height is compared by division before it is computed.
            $stride = intdiv($width * $bitCount + 31, 32) * 4;
            if ($stride > intdiv($size - $dataOffset, $height)) {
                throw ByteReader::truncated();
            }
            $end = $dataOffset + $stride * $height;
        }

        list($profileStart, $profileEnd) = $headerSize === 124 ? self::embeddedProfile($reader, $size) : [0, 0];
        $stops = $compression === self::BI_RLE8 || $compression === self::BI_RLE4
            ? self::runLengthStops($reader, $dataOffset, $width, $height, $compression === self::BI_RLE4)
            : [$end];
        if ($profileEnd > 0) {
            $stops[] = $profileEnd;
        }
        foreach ($stops as $stop) {
            $reader->seek(min($stop, $size));
            if (preg_match('~^(?:B[AM]|IC|P[IT]|C[IP])$~i', $reader->read(min(2, $size - $reader->getPosition()))) === 1) {
                throw new UnsupportedFormatException('BMP files that hold more than one image are not supported.');
            }
        }
        $paletteEnd = $headersEnd;
        if ($bitCount <= 8) {
            $paletteEnd = min($dataOffset, $headersEnd + ($colours === 0 ? 1 << $bitCount : $colours) * $paletteEntrySize);
        }
        $regions = [[0, $paletteEnd], [$dataOffset, $end]];
        if ($profileEnd > 0) {
            $regions[] = [$profileStart, $profileEnd];
        }

        return (new ImageInfo(ImageFormat::BMP, $width, $height, 1, $size))
            ->withBitDepth($bitCount === 64 ? 16 : 8)
            ->withMetadata($profileEnd > 0, false, false)
            ->withTrailingData(!self::covers($regions, $size))
            ->withVariants(self::variants($headerSize, $bitCount, $compression));
    }

    /**
     * The variants (ImageInfo::VARIANT_BMP_*) of a header size, bit count and compression.
     *
     * @return list<string>
     */
    private static function variants(int $headerSize, int $bitCount, int $compression): array
    {
        $variants = [];
        if ($compression === self::BI_BITFIELDS) {
            $variants[] = ImageInfo::VARIANT_BMP_BIT_FIELDS;
        } elseif ($compression === self::BI_ALPHABITFIELDS) {
            $variants[] = ImageInfo::VARIANT_BMP_ALPHA_BIT_FIELDS;
        }
        if ($bitCount === 2) {
            $variants[] = ImageInfo::VARIANT_BMP_2_BIT;
        } elseif ($bitCount === 64) {
            $variants[] = ImageInfo::VARIANT_BMP_64_BIT;
        }
        if ($headerSize === 52) {
            $variants[] = ImageInfo::VARIANT_BMP_V2_HEADER;
        } elseif ($headerSize === 56) {
            $variants[] = ImageInfo::VARIANT_BMP_V3_HEADER;
        }

        return $variants;
    }

    /**
     * Whether the regions that decoders read cover every byte of the file.
     *
     * @param list<array{0: int, 1: int}> $regions start and end offsets
     */
    private static function covers(array $regions, int $size): bool
    {
        // Each pass extends the covered start of the file by the regions that begin inside it, until none does.
        $covered = 0;
        do {
            $previous = $covered;
            foreach ($regions as list($start, $end)) {
                if ($start <= $covered) {
                    $covered = max($covered, $end);
                }
            }
        } while ($covered > $previous && $covered < $size);

        return $covered >= $size;
    }

    /**
     * Offsets where ImageMagick may stop reading run-length encoded data: after the escape or run that ends it, and two
     * bytes further, which ImageMagick 6.9.12 and later read before they stop. The end of the file when the data runs
     * out first.
     *
     * @throws UnsupportedFormatException when walking the data takes more than MAX_RUNS runs
     *
     * @return list<int>
     */
    private static function runLengthStops(ByteReader $reader, int $dataOffset, int $width, int $height, bool $rle4): array
    {
        $size = $reader->getSize();
        $pixels = $width * $height;
        $pixel = 0;
        $column = 0;
        $row = 0;
        $position = $dataOffset;
        $buffer = '';
        $bufferStart = $dataOffset;
        $bufferLength = 0;
        $runs = 0;
        $series = false;
        while ($row < $height && $pixel < $pixels) {
            // A run or escape takes 2 bytes, a delta 4: the buffer is refilled when fewer remain.
            $at = $position - $bufferStart;
            if ($at + 4 > $bufferLength) {
                $reader->seek($position);
                $buffer = $reader->read(min(self::RUN_CHUNK_SIZE, $size - $position));
                $bufferStart = $position;
                $bufferLength = strlen($buffer);
                $at = 0;
            }
            if ($at + 2 > $bufferLength) {
                return [$size];
            }
            $count = ord($buffer[$at]);
            if ($runs + ($count > 0 && $series ? 1 : self::ESCAPE_COST) > self::MAX_RUNS) {
                throw self::tooManyRuns();
            }
            if ($count > 0) {
                // Encoded runs of $count pixels of one value, most of the data: walked in a tight loop up to the next
                // escape, the end of the buffer, the last pixel or the last run allowed. The last pixel stops a run.
                $first = $at;
                $runs += $series ? 0 : self::ESCAPE_COST - 1;
                $series = true;
                $last = min($bufferLength - 2, $at + 2 * (self::MAX_RUNS - 1 - $runs));
                $previous = $pixel;
                do {
                    $pixel += $count;
                    $at += 2;
                } while ($at <= $last && $pixel < $pixels && ($count = ord($buffer[$at])) > 0);
                $pixel = min($pixel, $pixels);
                $column += $pixel - $previous;
                $runs += intdiv($at - $first, 2);
                $position = $bufferStart + $at;
                continue;
            }
            $runs += self::ESCAPE_COST;
            $series = false;
            $value = ord($buffer[$at + 1]);
            $position += 2;
            if ($value === 0) {
                // End of line.
                $column = 0;
                ++$row;
                $pixel = $row * $width;
            } elseif ($value === 1) {
                // End of bitmap.
                break;
            } elseif ($value === 2) {
                // Delta: moves right and down by the next two bytes; ImageMagick fails when they are missing.
                if ($at + 4 > $bufferLength) {
                    return [$size];
                }
                $column += ord($buffer[$at + 2]);
                $row += ord($buffer[$at + 3]);
                $pixel = $row * $width + $column;
                $position += 2;
            } else {
                // Absolute run: $value pixels in the next bytes, padded to 2 bytes; RLE4 packs two pixels a byte.
                $count = min($value, $pixels - $pixel);
                $position += $rle4 ? (($count + 3) >> 2) << 1 : (($count + 1) >> 1) << 1;
                $pixel += $count;
                $column += $count;
                if ($position > $size) {
                    return [$size];
                }
            }
        }

        return [$position, min($size, $position + 2)];
    }

    /**
     * @throws InvalidImageException
     * @throws UnsupportedFormatException
     */
    private static function checkCompression(int $compression, int $bitCount, bool $topDown): void
    {
        if ($compression === self::BI_JPEG || $compression === self::BI_PNG) {
            throw new UnsupportedFormatException('BMP files with embedded JPEG or PNG data are not supported.');
        }
        if (!isset(self::BIT_COUNTS[$compression])) {
            throw new UnsupportedFormatException('This BMP compression method is not supported.');
        }
        if (!in_array($bitCount, self::BIT_COUNTS[$compression], true)) {
            throw self::invalid('the bit count does not match the compression method');
        }
        if ($topDown && $compression !== self::BI_RGB && $compression !== self::BI_BITFIELDS && $compression !== self::BI_ALPHABITFIELDS) {
            throw self::invalid('a compressed image cannot be stored top-down');
        }
    }

    /**
     * Reads the colour space fields of a V5 header.
     *
     * @throws InvalidImageException
     * @throws UnsupportedFormatException
     *
     * @return array{0: int, 1: int} offsets of the embedded colour profile and of the byte after it, or [0, 0] when
     *                               there is none
     */
    private static function embeddedProfile(ByteReader $reader, int $size): array
    {
        // V5 header from offset 14: colours important (at 50), masks (54-69), colour space type (70), endpoints and
        // gamma (74-121), intent (122), profile offset relative to the header (126), profile size (130).
        $reader->seek(self::FILE_HEADER_SIZE + 56);
        $colourSpace = $reader->readUint32Le();
        if ($colourSpace === self::PROFILE_LINKED) {
            throw new UnsupportedFormatException('BMP files with a colour profile linked by file name are not supported.');
        }
        if ($colourSpace !== self::PROFILE_EMBEDDED) {
            return [0, 0];
        }
        $reader->seek(self::FILE_HEADER_SIZE + 112);
        $profileOffset = $reader->readUint32Le();
        $profileSize = $reader->readUint32Le();
        $profileStart = self::FILE_HEADER_SIZE + $profileOffset;
        if ($profileSize === 0 || $profileStart > $size || $profileSize > $size - $profileStart) {
            throw self::invalid('the embedded colour profile lies outside the file');
        }

        return [$profileStart, $profileStart + $profileSize];
    }

    /**
     * Interprets an unsigned 32-bit value as a signed one.
     */
    private static function signed(int $value): int
    {
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    private static function tooManyRuns(): UnsupportedFormatException
    {
        return new UnsupportedFormatException(sprintf('Run-length encoded BMP files of more than %d runs are not supported.', self::MAX_RUNS));
    }

    private static function invalid(string $problem): InvalidImageException
    {
        return new InvalidImageException('Invalid BMP: ' . $problem . '.');
    }
}
