<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\Limits;

/**
 * TIFF header parser: reads the first image file directory (IFD0) only. Later pages are neither counted nor checked
 * against the limits, so a backend must decode page 0 only (see ImageInfo); TIFF is never animated.
 *
 * - The IFD0 offset must point inside the file after the 8-byte header; the directory has 1 to MAX_ENTRIES entries,
 *   must fit in the file, and may not hold a tag twice (decoders disagree on which copy wins).
 * - ImageWidth and ImageLength (SHORT or LONG) are required and non-zero, and are checked against the limits at once.
 *   BitsPerSample values are at most 64, SamplesPerPixel from 1 to MAX_SAMPLES (MAX_SAMPLES + OUTPUT_EXTRA_SAMPLES
 *   for an output), PlanarConfiguration 1 or 2.
 * - Compression must be none, CCITT (2, 3, 4), LZW, JPEG (7), Deflate or PackBits; other schemes, old-style JPEG (6)
 *   included, are refused as unsupported.
 * - The image is stored either in strips or in tiles, never both. Strips: RowsPerStrip from 1 (all rows by default),
 *   and exactly one strip per RowsPerStrip rows (per sample plane when planar). Tiles: TileWidth and TileLength are
 *   required, multiples of 16, at most the image size rounded up to 16 (or 256, the usual default tile size, for
 *   smaller images) and within the limits, and there is exactly one tile per tile-sized area (per sample plane when
 *   planar). So a decoder never allocates a strip or a tile much larger than the image. At most MAX_DATA_BLOCKS
 *   strips or tiles, each of which must lie inside the file.
 *
 * A tiled image, and one of 2 samples per pixel (grey with alpha) or more than 4, are reported as variants
 * (ImageInfo::VARIANT_TIFF_*), which some decoders do not read.
 *
 * Metadata: tag 34675 is the colour profile; the EXIF and GPS directory pointers are EXIF; XMP, IPTC, Photoshop
 * resources and the descriptive text tags (description, make, model, software, date, artist, copyright) are other
 * metadata. The orientation comes from tag 274. The bit depth is the largest bits-per-sample value, at least 8. A
 * second page, or bytes after every structure IFD0 and its EXIF and GPS directories point to, are trailing data.
 *
 * @internal
 */
final class TiffParser implements ParserInterface
{
    /**
     * Largest number of entries in a directory, as libtiff allows.
     */
    public const MAX_ENTRIES = 4096;

    /**
     * Largest number of strips or tiles. An 8192-row image in strips of one row has 8192, or 32768 with four separate
     * sample planes; with tiles of 64 x 64 it has 16384.
     */
    public const MAX_DATA_BLOCKS = 32768;

    /**
     * Largest number of samples per pixel of an input.
     */
    public const MAX_SAMPLES = 16;

    /**
     * Samples an encoder may add to those of its input: vips resizes a grey image with extra samples in sRGB and
     * writes two more colour samples, so a 16-sample input becomes an 18-sample output.
     */
    public const OUTPUT_EXTRA_SAMPLES = 2;

    /**
     * @var int
     */
    private $maxSamples;

    /**
     * Tiles are multiples of this size in both directions.
     */
    private const TILE_UNIT = 16;

    /**
     * Tile side allowed whatever the image size: the default tile size of libtiff and GDAL, which they also use for
     * images smaller than one tile.
     */
    private const TILE_SIZE_DEFAULT = 256;

    private const ROWS_PER_STRIP_DEFAULT = 0xFFFFFFFF;

    private const TYPE_SHORT = 3;
    private const TYPE_LONG = 4;

    /**
     * Size in bytes of one value of each field type.
     *
     * @var array<int, int>
     */
    private const TYPE_SIZES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8, 13 => 4];

    private const TAG_WIDTH = 256;
    private const TAG_HEIGHT = 257;
    private const TAG_BITS_PER_SAMPLE = 258;
    private const TAG_COMPRESSION = 259;
    private const TAG_STRIP_OFFSETS = 273;
    private const TAG_ORIENTATION = 274;
    private const TAG_SAMPLES_PER_PIXEL = 277;
    private const TAG_ROWS_PER_STRIP = 278;
    private const TAG_STRIP_BYTE_COUNTS = 279;
    private const TAG_PLANAR_CONFIGURATION = 284;
    private const TAG_TILE_WIDTH = 322;
    private const TAG_TILE_LENGTH = 323;
    private const TAG_TILE_OFFSETS = 324;
    private const TAG_TILE_BYTE_COUNTS = 325;
    private const TAG_ICC_PROFILE = 34675;

    /**
     * @var list<int>
     */
    private const COMPRESSIONS = [1, 2, 3, 4, 5, 7, 8, 32773, 32946];

    /**
     * EXIF and GPS directory pointers.
     *
     * @var list<int>
     */
    private const EXIF_TAGS = [34665, 34853];

    /**
     * XMP, IPTC, Photoshop resources, description, make, model, software, date and time, artist, copyright.
     *
     * @var list<int>
     */
    private const METADATA_TAGS = [700, 33723, 34377, 270, 271, 272, 305, 306, 315, 33432];

    /**
     * @var bool
     */
    private $bigEndian = false;

    /**
     * Offset after the last byte of every structure read so far.
     *
     * @var int
     */
    private $end = 0;

    /**
     * @param int $maxSamples largest number of samples per pixel: MAX_SAMPLES for untrusted input;
     *                        ImageInspector::forOutput() adds OUTPUT_EXTRA_SAMPLES
     */
    public function __construct(int $maxSamples = self::MAX_SAMPLES)
    {
        $this->maxSamples = max(self::MAX_SAMPLES, min(self::MAX_SAMPLES + self::OUTPUT_EXTRA_SAMPLES, $maxSamples));
    }

    public function parse(ByteReader $reader, Limits $limits): ImageInfo
    {
        $reader->seek(0);
        $header = $reader->read(4);
        if ($header !== "II*\0" && $header !== "MM\0*") {
            throw self::invalid('the TIFF header is missing');
        }
        $this->bigEndian = $header[0] === 'M';
        $directory = $this->readUint32($reader->read(4), 0);
        if ($directory < 8 || $directory > $reader->getSize() - 2) {
            throw $directory < 8 ? self::invalid('the first directory offset points into the header') : ByteReader::truncated();
        }
        $this->end = 8;
        list($tags, $nextDirectory) = $this->readDirectory($reader, $directory, true);

        $width = $this->readScalar($tags, self::TAG_WIDTH, null);
        $height = $this->readScalar($tags, self::TAG_HEIGHT, null);
        if ($width === 0 || $height === 0) {
            throw self::invalid('the first directory declares a zero width or height');
        }
        $limits->assertImage($width, $height, 1);

        $compression = $this->readScalar($tags, self::TAG_COMPRESSION, 1);
        if (!in_array($compression, self::COMPRESSIONS, true)) {
            throw new UnsupportedFormatException('This TIFF compression scheme is not supported.');
        }
        $samples = $this->readScalar($tags, self::TAG_SAMPLES_PER_PIXEL, 1);
        if ($samples < 1 || $samples > $this->maxSamples) {
            throw self::invalid('the first directory declares an invalid number of samples per pixel');
        }
        $planar = $this->readScalar($tags, self::TAG_PLANAR_CONFIGURATION, 1);
        if ($planar !== 1 && $planar !== 2) {
            throw self::invalid('the first directory declares an invalid planar configuration');
        }
        $bitDepth = 8;
        foreach ($this->readValues($reader, $tags, self::TAG_BITS_PER_SAMPLE, $this->maxSamples) as $bits) {
            if ($bits < 1 || $bits > 64) {
                throw self::invalid('the first directory declares an invalid number of bits per sample');
            }
            $bitDepth = max($bitDepth, $bits);
        }

        $planes = $planar === 2 ? $samples : 1;
        $tiled = isset($tags[self::TAG_TILE_OFFSETS]) || isset($tags[self::TAG_TILE_WIDTH]) || isset($tags[self::TAG_TILE_LENGTH]);
        if ($tiled) {
            if (isset($tags[self::TAG_STRIP_OFFSETS])) {
                throw self::invalid('the first directory declares both strips and tiles');
            }
            $blocks = $planes * $this->tilesPerPlane($tags, $width, $height, $limits);
            $this->checkDataBlocks($reader, $tags, self::TAG_TILE_OFFSETS, self::TAG_TILE_BYTE_COUNTS, $blocks);
        } else {
            $rowsPerStrip = $this->readScalar($tags, self::TAG_ROWS_PER_STRIP, self::ROWS_PER_STRIP_DEFAULT);
            if ($rowsPerStrip === 0) {
                throw self::invalid('the first directory declares zero rows per strip');
            }
            $blocks = $planes * intdiv($height + min($rowsPerStrip, $height) - 1, min($rowsPerStrip, $height));
            $this->checkDataBlocks($reader, $tags, self::TAG_STRIP_OFFSETS, self::TAG_STRIP_BYTE_COUNTS, $blocks);
        }

        $orientation = isset($tags[self::TAG_ORIENTATION]) ? $this->readOrientation($tags[self::TAG_ORIENTATION]) : ImageInfo::ORIENTATION_NORMAL;
        foreach (self::EXIF_TAGS as $tag) {
            if (isset($tags[$tag])) {
                $this->readSubDirectory($reader, $tags[$tag]);
            }
        }
        $exif = array_intersect(self::EXIF_TAGS, array_keys($tags)) !== [];
        $otherMetadata = array_intersect(self::METADATA_TAGS, array_keys($tags)) !== [];

        return (new ImageInfo(ImageFormat::TIFF, $width, $height, 1, $reader->getSize()))
            ->withBitDepth($bitDepth)
            ->withMetadata(isset($tags[self::TAG_ICC_PROFILE]), $exif, $otherMetadata)
            ->withOrientation($orientation)
            ->withVariants(self::variants($tiled, $samples))
            // One pad byte may follow the last structure, as values start on word boundaries.
            ->withTrailingData($nextDirectory !== 0 || $reader->getSize() > $this->end + 1);
    }

    /**
     * The variants (ImageInfo::VARIANT_TIFF_*) of a layout and a number of samples per pixel.
     *
     * @return list<string>
     */
    private static function variants(bool $tiled, int $samples): array
    {
        $variants = [];
        if ($tiled) {
            $variants[] = ImageInfo::VARIANT_TIFF_TILED;
        }
        if ($samples === 2 || $samples > 4) {
            $variants[] = ImageInfo::VARIANT_TIFF_UNCOMMON_SAMPLES;
        }

        return $variants;
    }

    /**
     * Reads a directory and records the end of the directory and of every value it points to.
     *
     * @param bool $strict whether a malformed directory is refused (IFD0) or ignored (an EXIF or GPS directory)
     *
     * @throws InvalidImageException
     *
     * @return array{0: array<int, string>, 1: int} the raw 12-byte entries by tag, and the offset of the next
     *                                              directory (0 when there is none)
     */
    private function readDirectory(ByteReader $reader, int $offset, bool $strict): array
    {
        $reader->seek($offset);
        $count = $this->readUint16($reader->read(2), 0);
        if ($count === 0 || $count > self::MAX_ENTRIES) {
            if (!$strict) {
                return [[], 0];
            }

            throw self::invalid(sprintf('the first directory has no entry or more than %d entries', self::MAX_ENTRIES));
        }
        if (12 * $count + 4 > $reader->getRemaining()) {
            if (!$strict) {
                return [[], 0];
            }

            throw ByteReader::truncated();
        }
        $entries = $reader->read(12 * $count);
        $next = $this->readUint32($reader->read(4), 0);
        $this->end = max($this->end, $offset + 2 + 12 * $count + 4);

        $tags = [];
        for ($index = 0; $index < $count; ++$index) {
            $entry = (string) substr($entries, 12 * $index, 12);
            $tag = $this->readUint16($entry, 0);
            if (isset($tags[$tag])) {
                if (!$strict) {
                    return [[], 0];
                }

                throw self::invalid('the first directory holds a tag twice');
            }
            $tags[$tag] = $entry;

            $typeSize = self::TYPE_SIZES[$this->readUint16($entry, 2)] ?? 0;
            $length = $typeSize * $this->readUint32($entry, 4);
            $valueOffset = $this->readUint32($entry, 8);
            if ($length > 4 && $valueOffset <= $reader->getSize() && $length <= $reader->getSize() - $valueOffset) {
                $this->end = max($this->end, $valueOffset + $length);
            }
        }

        return [$tags, $next];
    }

    /**
     * Records the extent of an EXIF or GPS directory, so that it does not count as trailing data. A pointer that
     * cannot be followed is ignored: decoders ignore it too.
     *
     * @throws InvalidImageException
     */
    private function readSubDirectory(ByteReader $reader, string $entry): void
    {
        $type = $this->readUint16($entry, 2);
        $offset = $this->readUint32($entry, 8);
        if (($type === self::TYPE_LONG || $type === 13) && $this->readUint32($entry, 4) === 1 && $offset >= 8 && $offset <= $reader->getSize() - 2) {
            $this->readDirectory($reader, $offset, false);
        }
    }

    /**
     * Number of tiles of one sample plane, after checking the tile size.
     *
     * @param array<int, string> $tags raw 12-byte entries by tag
     *
     * @throws InvalidImageException
     * @throws LimitExceededException
     */
    private function tilesPerPlane(array $tags, int $width, int $height, Limits $limits): int
    {
        $tileWidth = $this->readScalar($tags, self::TAG_TILE_WIDTH, null);
        $tileHeight = $this->readScalar($tags, self::TAG_TILE_LENGTH, null);
        $maxWidth = max(self::TILE_SIZE_DEFAULT, self::TILE_UNIT * intdiv($width + self::TILE_UNIT - 1, self::TILE_UNIT));
        $maxHeight = max(self::TILE_SIZE_DEFAULT, self::TILE_UNIT * intdiv($height + self::TILE_UNIT - 1, self::TILE_UNIT));
        if ($tileWidth === 0 || $tileHeight === 0 || $tileWidth % self::TILE_UNIT !== 0 || $tileHeight % self::TILE_UNIT !== 0) {
            throw self::invalid('the tile size is zero or not a multiple of 16');
        }
        if ($tileWidth > $maxWidth || $tileHeight > $maxHeight) {
            throw self::invalid('a tile is larger than the image');
        }
        $limits->assertImage($tileWidth, $tileHeight, 1);

        return intdiv($width + $tileWidth - 1, $tileWidth) * intdiv($height + $tileHeight - 1, $tileHeight);
    }

    /**
     * Checks that there is one offset and one byte count per strip or tile, and that each lies inside the file.
     *
     * @param array<int, string> $tags raw 12-byte entries by tag
     *
     * @throws InvalidImageException
     */
    private function checkDataBlocks(ByteReader $reader, array $tags, int $offsetsTag, int $countsTag, int $blocks): void
    {
        if ($blocks > self::MAX_DATA_BLOCKS) {
            throw self::invalid(sprintf('the image is stored in more than %d strips or tiles', self::MAX_DATA_BLOCKS));
        }
        $offsets = $this->readValues($reader, $tags, $offsetsTag, $blocks);
        $counts = $this->readValues($reader, $tags, $countsTag, $blocks);
        if (count($offsets) !== $blocks || count($counts) !== $blocks) {
            throw self::invalid('the strip or tile offsets and byte counts do not match the image geometry');
        }
        // This loop runs up to MAX_DATA_BLOCKS times: no function call in it. Values are below 2^32, so no sum
        // overflows.
        $size = $reader->getSize();
        $end = $this->end;
        for ($index = 0; $index < $blocks; ++$index) {
            $offset = $offsets[$index];
            $blockEnd = $offset + $counts[$index];
            if ($offset < 8 || $blockEnd > $size) {
                throw ByteReader::truncated();
            }
            if ($blockEnd > $end) {
                $end = $blockEnd;
            }
        }
        $this->end = $end;
    }

    /**
     * Reads a SHORT or LONG tag holding one value.
     *
     * @param array<int, string> $tags    raw 12-byte entries by tag
     * @param int|null           $default value of a missing tag, or null when the tag is required
     *
     * @throws InvalidImageException
     */
    private function readScalar(array $tags, int $tag, ?int $default): int
    {
        if (!isset($tags[$tag])) {
            if ($default === null) {
                throw self::invalid('a required tag of the first directory is missing');
            }

            return $default;
        }
        $entry = $tags[$tag];
        $type = $this->readUint16($entry, 2);
        if (($type !== self::TYPE_SHORT && $type !== self::TYPE_LONG) || $this->readUint32($entry, 4) !== 1) {
            throw self::invalid('a tag of the first directory has an unexpected type or count');
        }

        return $type === self::TYPE_SHORT ? $this->readUint16($entry, 8) : $this->readUint32($entry, 8);
    }

    /**
     * Reads the SHORT or LONG values of a tag, inline or at its value offset; an empty list when the tag is missing.
     *
     * @param array<int, string> $tags raw 12-byte entries by tag
     *
     * @throws InvalidImageException
     *
     * @return list<int>
     */
    private function readValues(ByteReader $reader, array $tags, int $tag, int $maxCount): array
    {
        if (!isset($tags[$tag])) {
            return [];
        }
        $entry = $tags[$tag];
        $type = $this->readUint16($entry, 2);
        $count = $this->readUint32($entry, 4);
        if (($type !== self::TYPE_SHORT && $type !== self::TYPE_LONG) || $count < 1 || $count > $maxCount) {
            throw self::invalid('a tag of the first directory has an unexpected type or count');
        }

        $width = $type === self::TYPE_SHORT ? 2 : 4;
        $data = (string) substr($entry, 8, 4);
        if ($count * $width > 4) {
            $offset = $this->readUint32($entry, 8);
            if ($offset > $reader->getSize()) {
                throw ByteReader::truncated();
            }
            $reader->seek($offset);
            $data = $reader->read($count * $width);
        }

        // One unpack() call for every value: $count calls would cost far more.
        $format = $width === 2 ? ($this->bigEndian ? 'n' : 'v') : ($this->bigEndian ? 'N' : 'V');
        $unpacked = unpack($format . $count, $data);
        $values = [];
        foreach (is_array($unpacked) ? $unpacked : [] as $value) {
            $values[] = is_int($value) ? $value : 0;
        }
        if (count($values) !== $count) {
            throw ByteReader::truncated();
        }

        return $values;
    }

    /**
     * The orientation tag value, or the normal orientation when it is malformed (decoders ignore it then).
     */
    private function readOrientation(string $entry): int
    {
        $value = $this->readUint16($entry, 8);
        $valid = $this->readUint16($entry, 2) === self::TYPE_SHORT && $this->readUint32($entry, 4) === 1
            && $value >= ImageInfo::ORIENTATION_NORMAL && $value <= ImageInfo::ORIENTATION_MAX;

        return $valid ? $value : ImageInfo::ORIENTATION_NORMAL;
    }

    private function readUint16(string $bytes, int $offset): int
    {
        $value = unpack($this->bigEndian ? 'n' : 'v', $bytes, $offset);

        return is_array($value) && is_int($value[1]) ? $value[1] : 0;
    }

    private function readUint32(string $bytes, int $offset): int
    {
        $value = unpack($this->bigEndian ? 'N' : 'V', $bytes, $offset);

        return is_array($value) && is_int($value[1]) ? $value[1] : 0;
    }

    private static function invalid(string $problem): InvalidImageException
    {
        return new InvalidImageException('Invalid TIFF: ' . $problem . '.');
    }
}
