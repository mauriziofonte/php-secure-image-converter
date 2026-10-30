<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\Limits;

/**
 * AVIF header parser: a bounded walk of the ISO-BMFF boxes that describe the image, without touching the coded data
 * (mdat) or decoding any AV1 bitstream.
 *
 * - Boxes must nest inside their parent and the top-level boxes must tile the file up to its end: a truncated file is
 *   refused. At most MAX_BOXES boxes are visited and MAX_ENTRIES entries read from any table; the ftyp box is at most
 *   4 KiB and must list an "avif" or "avis" brand.
 * - Still image (meta box): the handler must be "pict" and the primary item (pitm) an AV1 image ("av01") or a grid
 *   of them ("grid"); other primary item types are refused as unsupported. Its "ispe" property gives the
 *   dimensions, which must be non-zero and are checked against the limits; its "av1C" (or else "pixi") property gives
 *   the bit depth, a "colr" property of type "prof" or "rICC" the colour profile. "Exif" items are EXIF, "mime" items
 *   (XMP) other metadata.
 * - Image sequence (moov box): the first track with a "pict" handler and an "av01" sample entry gives the frame
 *   count (its stsz or stz2 sample count, at least 1) and the dimensions (its sample entry), which then describe the
 *   image. Only the header of the size table is read, never its entries. A still primary item, when also present,
 *   is checked against the limits too.
 * - The limits are checked once the bounded walk ends: no box is ever expanded, so its cost does not depend on the
 *   declared dimensions or frame count.
 *
 * @internal
 */
final class AvifParser implements ParserInterface
{
    /**
     * Largest number of boxes visited in a file.
     */
    public const MAX_BOXES = 16384;

    /**
     * Largest number of entries read from an item, property or association table.
     */
    public const MAX_ENTRIES = 16384;

    private const FTYP_SIZE_MAX = 4096;

    /**
     * @var list<string>
     */
    private const BRANDS = ['avif', 'avis'];

    /**
     * Primary item types this library processes.
     *
     * @var list<string>
     */
    private const IMAGE_ITEM_TYPES = ['av01', 'grid'];

    /**
     * @var int
     */
    private $boxes = 0;

    public function parse(ByteReader $reader, Limits $limits): ImageInfo
    {
        $this->boxes = 0;
        $size = $reader->getSize();

        $reader->seek(0);
        $ftyp = $this->readBoxHeader($reader, $size);
        if ($ftyp[0] !== 'ftyp' || $ftyp[2] - $ftyp[1] < 8 || $ftyp[2] - $ftyp[1] > self::FTYP_SIZE_MAX) {
            throw self::invalid('the ftyp box is missing or has an invalid size');
        }
        self::checkBrands($reader->read($ftyp[2] - $ftyp[1]));
        $reader->seek($ftyp[2]);

        $still = null;
        $sequence = null;
        while ($reader->getPosition() < $size) {
            list($type, $start, $end) = $this->readBoxHeader($reader, $size);
            if ($type === 'meta') {
                if ($still !== null) {
                    throw self::invalid('the file has more than one meta box');
                }
                $still = $this->parseMeta($reader, $start, $end);
            } elseif ($type === 'moov' && $sequence === null) {
                $sequence = $this->parseMovie($reader, $start, $end);
            }
            $reader->seek($end);
        }

        if ($still !== null) {
            $limits->assertImage($still['width'], $still['height'], 1);
        }
        if ($sequence !== null) {
            $limits->assertImage($sequence['width'], $sequence['height'], $sequence['frames']);
            $info = new ImageInfo(ImageFormat::AVIF, $sequence['width'], $sequence['height'], $sequence['frames'], $size);
        } elseif ($still !== null) {
            $info = new ImageInfo(ImageFormat::AVIF, $still['width'], $still['height'], 1, $size);
        } else {
            throw self::invalid('the file has neither a primary image item nor an image sequence track');
        }

        if ($still === null) {
            return $info;
        }

        return $info->withBitDepth($still['bitDepth'])->withMetadata($still['icc'], $still['exif'], $still['metadata']);
    }

    /**
     * @throws UnsupportedFormatException
     */
    private static function checkBrands(string $payload): void
    {
        $brands = [substr($payload, 0, 4)];
        for ($offset = 8; $offset + 4 <= strlen($payload); $offset += 4) {
            $brands[] = substr($payload, $offset, 4);
        }
        if (array_intersect(self::BRANDS, $brands) === []) {
            throw new UnsupportedFormatException('The ISO-BMFF file is not an AVIF image (no avif or avis brand).');
        }
    }

    /**
     * Reads the meta box of a still image and returns the description of its primary item.
     *
     * @throws InvalidImageException
     * @throws UnsupportedFormatException
     *
     * @return array{width: int, height: int, bitDepth: int, icc: bool, exif: bool, metadata: bool}
     */
    private function parseMeta(ByteReader $reader, int $start, int $end): array
    {
        $reader->seek($start);
        self::readFullBoxHeader($reader, $end, 0);

        $handler = null;
        $primary = null;
        $itemInfo = null;
        $properties = null;
        $associations = null;
        while ($reader->getPosition() < $end) {
            list($type, $childStart, $childEnd) = $this->readBoxHeader($reader, $end);
            switch ($type) {
                case 'hdlr':
                    self::readFullBoxHeader($reader, $childEnd, 0);
                    $reader->skip(min(4, $childEnd - $reader->getPosition()));
                    $handler = self::readBytes($reader, $childEnd, 4);
                    break;
                case 'pitm':
                    $version = self::readFullBoxHeader($reader, $childEnd, 1);
                    $primary = $version === 0 ? self::readUint16($reader, $childEnd) : self::readUint32($reader, $childEnd);
                    break;
                case 'iinf':
                    $itemInfo = [$childStart, $childEnd];
                    break;
                case 'iprp':
                    list($properties, $associations) = $this->findPropertyBoxes($reader, $childStart, $childEnd);
                    break;
            }
            $reader->seek($childEnd);
        }

        if ($handler !== 'pict') {
            throw self::invalid('the meta box has no "pict" handler');
        }
        if ($primary === null || $itemInfo === null || $properties === null || $associations === null) {
            throw self::invalid('the meta box lacks the primary item, item information or item properties');
        }

        $items = $this->readItemTypes($reader, $itemInfo[0], $itemInfo[1]);
        if (!isset($items[$primary])) {
            throw self::invalid('the primary item is not described');
        }
        if (!in_array($items[$primary], self::IMAGE_ITEM_TYPES, true)) {
            throw new UnsupportedFormatException('The primary item of this AVIF file is not an AV1 image or grid.');
        }

        $propertyBoxes = $this->readPropertyList($reader, $properties[0], $properties[1]);
        $description = ['width' => 0, 'height' => 0, 'bitDepth' => 8, 'icc' => false, 'exif' => false, 'metadata' => false];
        $codecBits = 0;
        $pixelBits = 0;
        foreach ($this->readAssociations($reader, $associations[0], $associations[1], $primary) as $index) {
            if (!isset($propertyBoxes[$index])) {
                throw self::invalid('the primary item is associated with a missing property');
            }
            list($type, $propertyStart, $propertyEnd) = $propertyBoxes[$index];
            $reader->seek($propertyStart);
            if ($type === 'ispe') {
                self::readFullBoxHeader($reader, $propertyEnd, 0);
                $description['width'] = self::readUint32($reader, $propertyEnd);
                $description['height'] = self::readUint32($reader, $propertyEnd);
            } elseif ($type === 'av1C') {
                $configuration = self::readBytes($reader, $propertyEnd, 3);
                $flags = ord($configuration[2]);
                $codecBits = ($flags & 0x40) === 0 ? 8 : (($flags & 0x20) === 0 ? 10 : 12);
            } elseif ($type === 'pixi') {
                self::readFullBoxHeader($reader, $propertyEnd, 0);
                $channels = ord(self::readBytes($reader, $propertyEnd, 1));
                foreach (str_split(self::readBytes($reader, $propertyEnd, max(1, $channels))) as $bits) {
                    $pixelBits = max($pixelBits, ord($bits));
                }
            } elseif ($type === 'colr') {
                $colourType = self::readBytes($reader, $propertyEnd, 4);
                $description['icc'] = $description['icc'] || $colourType === 'prof' || $colourType === 'rICC';
            }
        }
        if ($description['width'] === 0 || $description['height'] === 0) {
            throw self::invalid('the primary item has no ispe property or a zero width or height');
        }
        // The codec configuration is authoritative; pixi describes the reconstructed image of a derived item.
        $description['bitDepth'] = $codecBits > 0 ? $codecBits : min(64, max(8, $pixelBits));
        $description['exif'] = in_array('Exif', $items, true);
        $description['metadata'] = in_array('mime', $items, true);

        return $description;
    }

    /**
     * Finds the ipco and ipma boxes inside iprp.
     *
     * @throws InvalidImageException
     *
     * @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}
     */
    private function findPropertyBoxes(ByteReader $reader, int $start, int $end): array
    {
        $container = null;
        $associations = null;
        $reader->seek($start);
        while ($reader->getPosition() < $end) {
            list($type, $childStart, $childEnd) = $this->readBoxHeader($reader, $end);
            if ($type === 'ipco' && $container === null) {
                $container = [$childStart, $childEnd];
            } elseif ($type === 'ipma' && $associations === null) {
                $associations = [$childStart, $childEnd];
            }
            $reader->seek($childEnd);
        }
        if ($container === null || $associations === null) {
            throw self::invalid('the iprp box lacks ipco or ipma');
        }

        return [$container, $associations];
    }

    /**
     * Item types by item ID, from the infe boxes of iinf (versions 2 and 3; older entries have no type).
     *
     * @throws InvalidImageException
     *
     * @return array<int, string>
     */
    private function readItemTypes(ByteReader $reader, int $start, int $end): array
    {
        $reader->seek($start);
        $version = self::readFullBoxHeader($reader, $end, 1);
        $count = $version === 0 ? self::readUint16($reader, $end) : self::readUint32($reader, $end);
        if ($count > self::MAX_ENTRIES) {
            throw self::invalid('the iinf box has too many entries');
        }

        $items = [];
        for ($index = 0; $index < $count; ++$index) {
            list($type, $entryStart, $entryEnd) = $this->readBoxHeader($reader, $end);
            if ($type !== 'infe') {
                throw self::invalid('the iinf box holds a box other than infe');
            }
            $entryVersion = self::readFullBoxHeader($reader, $entryEnd, 3);
            if ($entryVersion >= 2) {
                $id = $entryVersion === 2 ? self::readUint16($reader, $entryEnd) : self::readUint32($reader, $entryEnd);
                $reader->skip(min(2, $entryEnd - $reader->getPosition()));
                $items[$id] = self::readBytes($reader, $entryEnd, 4);
            }
            $reader->seek($entryEnd);
        }

        return $items;
    }

    /**
     * Property boxes of ipco by 1-based index, as [type, payload start, payload end].
     *
     * @throws InvalidImageException
     *
     * @return array<int, array{0: string, 1: int, 2: int}>
     */
    private function readPropertyList(ByteReader $reader, int $start, int $end): array
    {
        $properties = [];
        $reader->seek($start);
        for ($index = 1; $reader->getPosition() < $end; ++$index) {
            if ($index > self::MAX_ENTRIES) {
                throw self::invalid('the ipco box has too many properties');
            }
            $box = $this->readBoxHeader($reader, $end);
            $properties[$index] = $box;
            $reader->seek($box[2]);
        }

        return $properties;
    }

    /**
     * Property indices (1-based) associated with one item in ipma.
     *
     * @throws InvalidImageException
     *
     * @return list<int>
     */
    private function readAssociations(ByteReader $reader, int $start, int $end, int $itemId): array
    {
        $reader->seek($start);
        $version = self::readFullBoxHeader($reader, $end, 1, $flags);
        $count = self::readUint32($reader, $end);
        if ($count > self::MAX_ENTRIES) {
            throw self::invalid('the ipma box has too many entries');
        }

        for ($entry = 0; $entry < $count; ++$entry) {
            $id = $version === 0 ? self::readUint16($reader, $end) : self::readUint32($reader, $end);
            $associationCount = ord(self::readBytes($reader, $end, 1));
            $wide = ($flags & 0x01) !== 0;
            $data = self::readBytes($reader, $end, $associationCount * ($wide ? 2 : 1));
            if ($id !== $itemId) {
                continue;
            }

            $indices = [];
            for ($association = 0; $association < $associationCount; ++$association) {
                $index = $wide
                    ? ((ord($data[2 * $association]) << 8) | ord($data[2 * $association + 1])) & 0x7FFF
                    : ord($data[$association]) & 0x7F;
                if ($index !== 0) {
                    $indices[] = $index;
                }
            }

            return $indices;
        }

        return [];
    }

    /**
     * Reads the image sequence of a moov box: the first track with a "pict" handler and an "av01" sample entry.
     *
     * @throws InvalidImageException
     *
     * @return array{width: int, height: int, frames: int}|null
     */
    private function parseMovie(ByteReader $reader, int $start, int $end): ?array
    {
        $reader->seek($start);
        while ($reader->getPosition() < $end) {
            list($type, $trackStart, $trackEnd) = $this->readBoxHeader($reader, $end);
            if ($type === 'trak') {
                $media = $this->findChild($reader, $trackStart, $trackEnd, 'mdia');
                $track = $media === null ? null : $this->parseMedia($reader, $media[0], $media[1]);
                if ($track !== null) {
                    return $track;
                }
            }
            $reader->seek($trackEnd);
        }

        return null;
    }

    /**
     * @throws InvalidImageException
     *
     * @return array{width: int, height: int, frames: int}|null
     */
    private function parseMedia(ByteReader $reader, int $start, int $end): ?array
    {
        $handler = $this->findChild($reader, $start, $end, 'hdlr');
        if ($handler === null) {
            return null;
        }
        $reader->seek($handler[0]);
        self::readFullBoxHeader($reader, $handler[1], 0);
        $reader->skip(min(4, $handler[1] - $reader->getPosition()));
        if (self::readBytes($reader, $handler[1], 4) !== 'pict') {
            return null;
        }

        $mediaInformation = $this->findChild($reader, $start, $end, 'minf');
        $sampleTable = $mediaInformation === null ? null : $this->findChild($reader, $mediaInformation[0], $mediaInformation[1], 'stbl');
        if ($sampleTable === null) {
            throw self::invalid('an image sequence track has no sample table');
        }

        $description = $this->findChild($reader, $sampleTable[0], $sampleTable[1], 'stsd');
        if ($description === null) {
            throw self::invalid('an image sequence track has no sample description');
        }
        $reader->seek($description[0]);
        self::readFullBoxHeader($reader, $description[1], 0);
        $reader->skip(min(4, $description[1] - $reader->getPosition()));
        list($entryType, $entryStart, $entryEnd) = $this->readBoxHeader($reader, $description[1]);
        if ($entryType !== 'av01') {
            return null;
        }
        // VisualSampleEntry: 6 reserved bytes, data reference index, 16 bytes of pre-defined and reserved fields,
        // then width and height.
        $reader->seek($entryStart);
        $reader->skip(min(24, $entryEnd - $entryStart));
        $width = self::readUint16($reader, $entryEnd);
        $height = self::readUint16($reader, $entryEnd);
        if ($width === 0 || $height === 0) {
            throw self::invalid('an image sequence track declares a zero width or height');
        }

        $sizes = $this->findChild($reader, $sampleTable[0], $sampleTable[1], 'stsz');
        $compactSizes = $sizes === null ? $this->findChild($reader, $sampleTable[0], $sampleTable[1], 'stz2') : null;
        if ($sizes === null && $compactSizes === null) {
            throw self::invalid('an image sequence track has no sample size table');
        }
        $table = $sizes !== null ? $sizes : $compactSizes;
        $reader->seek($table[0]);
        self::readFullBoxHeader($reader, $table[1], 0);
        $reader->skip(min(4, $table[1] - $reader->getPosition()));
        $frames = self::readUint32($reader, $table[1]);
        if ($frames === 0) {
            throw self::invalid('an image sequence track has no sample');
        }

        return ['width' => $width, 'height' => $height, 'frames' => $frames];
    }

    /**
     * Payload bounds of the first child box of a type, or null.
     *
     * @throws InvalidImageException
     *
     * @return array{0: int, 1: int}|null
     */
    private function findChild(ByteReader $reader, int $start, int $end, string $wanted): ?array
    {
        $reader->seek($start);
        while ($reader->getPosition() < $end) {
            list($type, $childStart, $childEnd) = $this->readBoxHeader($reader, $end);
            if ($type === $wanted) {
                return [$childStart, $childEnd];
            }
            $reader->seek($childEnd);
        }

        return null;
    }

    /**
     * Reads a box header at the position and checks that the box fits before $end (the end of its parent).
     *
     * @throws InvalidImageException
     *
     * @return array{0: string, 1: int, 2: int} type, payload start, box end
     */
    private function readBoxHeader(ByteReader $reader, int $end): array
    {
        if (++$this->boxes > self::MAX_BOXES) {
            throw self::invalid(sprintf('the file has more than %d boxes', self::MAX_BOXES));
        }
        $start = $reader->getPosition();
        if ($end - $start < 8) {
            throw self::boxOverflow($reader, $end);
        }
        $size = $reader->readUint32Be();
        $type = $reader->read(4);
        if ($size === 1) {
            if ($end - $start < 16) {
                throw self::boxOverflow($reader, $end);
            }
            $high = $reader->readUint32Be();
            $low = $reader->readUint32Be();
            $size = $high > 0x7FFFFFFF ? PHP_INT_MAX : ($high << 32) | $low;
        } elseif ($size === 0) {
            $size = $end - $start;
        }
        if ($type === 'uuid') {
            if ($end - $reader->getPosition() < 16) {
                throw self::boxOverflow($reader, $end);
            }
            $reader->skip(16);
        }
        if ($size < $reader->getPosition() - $start || $size > $end - $start) {
            throw self::boxOverflow($reader, $end);
        }

        return [$type, $reader->getPosition(), $start + $size];
    }

    /**
     * Reads the version and flags of a full box.
     *
     * @param int|null $flags set to the 24-bit flags
     *
     * @param-out int $flags
     *
     * @throws InvalidImageException when the version is above $maxVersion
     */
    private static function readFullBoxHeader(ByteReader $reader, int $end, int $maxVersion, ?int &$flags = null): int
    {
        $header = self::readBytes($reader, $end, 4);
        $version = ord($header[0]);
        $flags = (ord($header[1]) << 16) | (ord($header[2]) << 8) | ord($header[3]);
        if ($version > $maxVersion) {
            throw self::invalid('a box has an unknown version');
        }

        return $version;
    }

    /**
     * Reads bytes that must lie before $end, the end of the current box.
     *
     * @throws InvalidImageException
     */
    private static function readBytes(ByteReader $reader, int $end, int $length): string
    {
        if ($length > $end - $reader->getPosition()) {
            throw self::invalid('a box is too short for its fields');
        }

        return $reader->read($length);
    }

    /**
     * @throws InvalidImageException
     */
    private static function readUint16(ByteReader $reader, int $end): int
    {
        $bytes = self::readBytes($reader, $end, 2);

        return (ord($bytes[0]) << 8) | ord($bytes[1]);
    }

    /**
     * @throws InvalidImageException
     */
    private static function readUint32(ByteReader $reader, int $end): int
    {
        $bytes = self::readBytes($reader, $end, 4);

        return (ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]);
    }

    /**
     * A box that does not fit in its parent: truncated when it runs past the end of the file, invalid otherwise.
     */
    private static function boxOverflow(ByteReader $reader, int $end): InvalidImageException
    {
        return $end === $reader->getSize() ? ByteReader::truncated() : self::invalid('a box overflows its parent box');
    }

    private static function invalid(string $problem): InvalidImageException
    {
        return new InvalidImageException('Invalid AVIF: ' . $problem . '.');
    }
}
