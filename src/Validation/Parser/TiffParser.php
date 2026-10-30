<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\Limits;

/**
 * TIFF header parser: reads the first image file directory (IFD0) only; further pages are neither counted nor read,
 * and TIFF is never animated (a converter processes the first page).
 *
 * - The IFD0 offset must point inside the file after the 8-byte header; the directory has 1 to MAX_ENTRIES entries
 *   and must fit in the file.
 * - ImageWidth and ImageLength (SHORT or LONG) are required and non-zero, and are checked against the limits at once.
 *   BitsPerSample values are at most 64 and SamplesPerPixel from 1 to MAX_SAMPLES.
 * - Compression must be none, CCITT (2, 3, 4), LZW, JPEG (7), Deflate or PackBits; other schemes, old-style JPEG (6)
 *   included, are refused as unsupported.
 * - Every strip or tile (offsets and byte counts, at most MAX_ENTRIES of each, as many byte counts as offsets) must
 *   lie inside the file: a truncated file is refused.
 *
 * Metadata: tag 34675 is the colour profile; the EXIF and GPS directory pointers are EXIF; XMP, IPTC, Photoshop
 * resources and the descriptive text tags (description, make, model, software, date, artist, copyright) are other
 * metadata. The bit depth is the largest bits-per-sample value, at least 8.
 *
 * @internal
 */
final class TiffParser implements ParserInterface
{
    /**
     * Largest number of IFD0 entries, and of strips or tiles.
     */
    public const MAX_ENTRIES = 65536;

    private const MAX_SAMPLES = 16;

    private const TYPE_SHORT = 3;
    private const TYPE_LONG = 4;

    private const TAG_WIDTH = 256;
    private const TAG_HEIGHT = 257;
    private const TAG_BITS_PER_SAMPLE = 258;
    private const TAG_COMPRESSION = 259;
    private const TAG_STRIP_OFFSETS = 273;
    private const TAG_SAMPLES_PER_PIXEL = 277;
    private const TAG_STRIP_BYTE_COUNTS = 279;
    private const TAG_TILE_OFFSETS = 324;
    private const TAG_TILE_BYTE_COUNTS = 325;
    private const TAG_ICC_PROFILE = 34675;

    /**
     * @var list<int>
     */
    private const COMPRESSIONS = [1, 2, 3, 4, 5, 7, 8, 32773, 32946];

    /**
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
        $reader->seek($directory);
        $count = $this->readUint16($reader->read(2), 0);
        if ($count === 0 || $count > self::MAX_ENTRIES) {
            throw self::invalid('the first directory has no entry or too many entries');
        }
        $entries = $reader->read(12 * $count);

        $tags = [];
        for ($index = 0; $index < $count; ++$index) {
            $entry = substr($entries, 12 * $index, 12);
            $tags[$this->readUint16($entry, 0)] = $entry;
        }

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
        if ($samples < 1 || $samples > self::MAX_SAMPLES) {
            throw self::invalid('the first directory declares an invalid number of samples per pixel');
        }
        $bitDepth = 8;
        foreach ($this->readValues($reader, $tags, self::TAG_BITS_PER_SAMPLE, self::MAX_SAMPLES) as $bits) {
            if ($bits < 1 || $bits > 64) {
                throw self::invalid('the first directory declares an invalid number of bits per sample');
            }
            $bitDepth = max($bitDepth, $bits);
        }

        $tiled = isset($tags[self::TAG_TILE_OFFSETS]);
        $this->checkDataBlocks(
            $reader,
            $tags,
            $tiled ? self::TAG_TILE_OFFSETS : self::TAG_STRIP_OFFSETS,
            $tiled ? self::TAG_TILE_BYTE_COUNTS : self::TAG_STRIP_BYTE_COUNTS
        );

        $exif = array_intersect(self::EXIF_TAGS, array_keys($tags)) !== [];
        $otherMetadata = array_intersect(self::METADATA_TAGS, array_keys($tags)) !== [];

        return (new ImageInfo(ImageFormat::TIFF, $width, $height, 1, $reader->getSize()))
            ->withBitDepth($bitDepth)
            ->withMetadata(isset($tags[self::TAG_ICC_PROFILE]), $exif, $otherMetadata);
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
        $data = substr($entry, 8, 4);
        if ($count * $width > 4) {
            $offset = $this->readUint32($entry, 8);
            if ($offset > $reader->getSize()) {
                throw ByteReader::truncated();
            }
            $reader->seek($offset);
            $data = $reader->read($count * $width);
        }

        $values = [];
        for ($index = 0; $index < $count; ++$index) {
            $values[] = $width === 2 ? $this->readUint16($data, 2 * $index) : $this->readUint32($data, 4 * $index);
        }

        return $values;
    }

    /**
     * Checks that every strip or tile lies inside the file.
     *
     * @param array<int, string> $tags raw 12-byte entries by tag
     *
     * @throws InvalidImageException
     */
    private function checkDataBlocks(ByteReader $reader, array $tags, int $offsetsTag, int $countsTag): void
    {
        $offsets = $this->readValues($reader, $tags, $offsetsTag, self::MAX_ENTRIES);
        $counts = $this->readValues($reader, $tags, $countsTag, self::MAX_ENTRIES);
        if ($offsets === [] || count($offsets) !== count($counts)) {
            throw self::invalid('the strip or tile offsets and byte counts are missing or inconsistent');
        }
        $size = $reader->getSize();
        foreach ($offsets as $index => $offset) {
            if ($offset < 8 || $offset > $size || $counts[$index] > $size - $offset) {
                throw ByteReader::truncated();
            }
        }
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
