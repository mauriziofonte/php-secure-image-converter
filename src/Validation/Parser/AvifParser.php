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
 * AVIF header parser: a bounded walk of the ISO-BMFF boxes that describe the image, plus the sequence header at the
 * start of each AV1 image, without decoding any AV1 frame.
 *
 * - Boxes must nest inside their parent and the top-level boxes must tile the file up to its end: a truncated file is
 *   refused. At most MAX_BOXES boxes are visited and MAX_ENTRIES entries read from any table; the ftyp box is at most
 *   4 KiB and must list an "avif" or "avis" brand. A box that a decoder reads once (ftyp, meta and moov at the top;
 *   hdlr, pitm, iloc, iinf, iprp, iref, idat and dinf in meta; ipco and ipma in iprp; the boxes of a track's media
 *   and sample tables) may not appear twice, an item may not be described or associated twice, and an image item may
 *   not have two ispe, av1C, irot or imir properties: decoders disagree on which copy wins.
 * - Still image (meta box): the handler must be "pict" and the primary item (pitm) an AV1 image ("av01"); grids and
 *   other derived or coded items are refused as unsupported. Every AV1 image item (the primary image, its alpha plane,
 *   thumbnails; at most MAX_IMAGES) needs one "ispe" property whose size is checked against the limits, and the AV1
 *   sequence header of its data (located through iloc, in the file or in idat) may not allow frames larger than that
 *   ispe. The primary item gives the dimensions; its "av1C" (or else "pixi") property the bit depth, a "colr"
 *   property of type "prof" or "rICC" the colour profile, its "irot" then "imir" properties the orientation. "Exif"
 *   items are EXIF, "mime" items (XMP) other metadata.
 * - Image sequence (moov box): every track whose sample description is AV1 ("av01", whatever its handler; at most
 *   MAX_IMAGES) is checked: its sample entry size and sample count (the header of stsz or stz2, never its entries)
 *   against the limits, and the sequence header of its first sample against the sample entry size. The first such
 *   track gives the dimensions and the frame count; the still primary item, when present, is checked too.
 * - Top-level boxes other than ftyp, meta, moov and mdat are trailing data.
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
     * Largest number of entries read from an item, property, association or location table.
     */
    public const MAX_ENTRIES = 16384;

    /**
     * Largest number of AV1 image items, and of AV1 tracks, in a file.
     */
    public const MAX_IMAGES = 16;

    private const FTYP_SIZE_MAX = 4096;

    /**
     * @var list<string>
     */
    private const BRANDS = ['avif', 'avis'];

    /**
     * Top-level boxes that make up an AVIF image.
     *
     * @var list<string>
     */
    private const IMAGE_BOXES = ['ftyp', 'meta', 'moov', 'mdat'];

    /**
     * Boxes that the meta box may hold once at most.
     *
     * @var list<string>
     */
    private const META_BOXES = ['hdlr', 'pitm', 'iloc', 'iinf', 'iprp', 'iref', 'idat', 'dinf'];

    /**
     * EXIF orientation for a transform that first mirrors left to right (index 1) or not (index 0), then rotates
     * clockwise by the key in quarter turns.
     *
     * @var array<int, array{0: int, 1: int}>
     */
    private const ORIENTATIONS = [0 => [1, 2], 1 => [6, 7], 2 => [3, 4], 3 => [8, 5]];

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
        $seen = [];
        $trailingData = false;
        while ($reader->getPosition() < $size) {
            list($type, $start, $end) = $this->readBoxHeader($reader, $size);
            if ($type === 'ftyp' || (($type === 'meta' || $type === 'moov') && isset($seen[$type]))) {
                throw self::invalid('the file holds a top-level box twice');
            }
            $seen[$type] = true;
            if ($type === 'meta') {
                $still = $this->parseMeta($reader, $start, $end, $limits);
            } elseif ($type === 'moov') {
                $sequence = $this->parseMovie($reader, $start, $end, $limits);
            } elseif (!in_array($type, self::IMAGE_BOXES, true)) {
                $trailingData = true;
            }
            $reader->seek($end);
        }

        if ($sequence !== null) {
            $info = new ImageInfo(ImageFormat::AVIF, $sequence[0], $sequence[1], $sequence[2], $size);
        } elseif ($still !== null) {
            $info = new ImageInfo(ImageFormat::AVIF, $still['width'], $still['height'], 1, $size);
        } else {
            throw self::invalid('the file has neither a primary image item nor an image sequence track');
        }
        if ($still !== null) {
            $info = $info->withBitDepth($still['bitDepth'])
                ->withMetadata($still['icc'], $still['exif'], $still['metadata'])
                ->withOrientation($still['orientation']);
        }

        return $info->withTrailingData($trailingData);
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
     * Reads the meta box of a still image, checks every AV1 image item and describes the primary one.
     *
     * @throws InvalidImageException
     * @throws LimitExceededException
     * @throws UnsupportedFormatException
     *
     * @return array{width: int, height: int, bitDepth: int, icc: bool, exif: bool, metadata: bool, orientation: int}
     */
    private function parseMeta(ByteReader $reader, int $start, int $end, Limits $limits): array
    {
        $reader->seek($start);
        self::readFullBoxHeader($reader, $end, 0);
        $boxes = $this->findChildren($reader, $reader->getPosition(), $end, self::META_BOXES);
        if (!isset($boxes['hdlr']) || $this->readHandler($reader, $boxes['hdlr']) !== 'pict') {
            throw self::invalid('the meta box has no "pict" handler');
        }
        if (!isset($boxes['pitm'], $boxes['iinf'], $boxes['iprp'], $boxes['iloc'])) {
            throw self::invalid('the meta box lacks the primary item, item information, locations or properties');
        }

        $reader->seek($boxes['pitm'][0]);
        $version = self::readFullBoxHeader($reader, $boxes['pitm'][1], 1);
        $primary = $version === 0 ? self::readUint16($reader, $boxes['pitm'][1]) : self::readUint32($reader, $boxes['pitm'][1]);
        $items = $this->readItemTypes($reader, $boxes['iinf'][0], $boxes['iinf'][1]);
        if (!isset($items[$primary])) {
            throw self::invalid('the primary item is not described');
        }
        if ($items[$primary] !== 'av01') {
            throw new UnsupportedFormatException('AVIF images whose primary item is a grid or not an AV1 image are not supported.');
        }
        $images = array_keys($items, 'av01', true);
        if (count($images) > self::MAX_IMAGES) {
            throw new UnsupportedFormatException(sprintf('AVIF files with more than %d AV1 image items are not supported.', self::MAX_IMAGES));
        }

        $propertyBoxes = $this->findChildren($reader, $boxes['iprp'][0], $boxes['iprp'][1], ['ipco', 'ipma']);
        if (!isset($propertyBoxes['ipco'], $propertyBoxes['ipma'])) {
            throw self::invalid('the iprp box lacks ipco or ipma');
        }
        $properties = $this->readPropertyList($reader, $propertyBoxes['ipco'][0], $propertyBoxes['ipco'][1]);
        $associations = $this->readAssociations($reader, $propertyBoxes['ipma'][0], $propertyBoxes['ipma'][1]);

        $description = ['width' => 0, 'height' => 0, 'bitDepth' => 8, 'icc' => false, 'exif' => false, 'metadata' => false, 'orientation' => ImageInfo::ORIENTATION_NORMAL];
        $sizes = [];
        foreach ($images as $item) {
            $image = $this->readImageProperties($reader, $properties, $associations[$item] ?? []);
            $limits->assertImage($image['width'], $image['height'], 1);
            $sizes[$item] = [$image['width'], $image['height']];
            if ($item === $primary) {
                $description = ['exif' => false, 'metadata' => false] + $image;
            }
        }

        // The coded sizes are read once every declared size has passed the limits.
        $locations = $this->readItemLocations($reader, $boxes['iloc'], $boxes['idat'] ?? null, $images);
        foreach ($sizes as $item => $size) {
            if (!isset($locations[$item])) {
                throw self::invalid('an image item has no data location');
            }
            list($codedWidth, $codedHeight) = Av1SequenceHeader::maxFrameSize($reader, $locations[$item][0], $locations[$item][1]);
            if ($codedWidth > $size[0] || $codedHeight > $size[1]) {
                throw self::invalid('the AV1 data of an image item allows frames larger than its ispe property');
            }
        }

        $description['exif'] = in_array('Exif', $items, true);
        $description['metadata'] = in_array('mime', $items, true);

        return $description;
    }

    /**
     * Reads the properties associated with one AV1 image item.
     *
     * @param array<int, array{0: string, 1: int, 2: int}> $properties property boxes by 1-based index
     * @param list<int>                                    $indices    the indices associated with the item, in order
     *
     * @throws InvalidImageException
     *
     * @return array{width: int, height: int, bitDepth: int, icc: bool, orientation: int}
     */
    private function readImageProperties(ByteReader $reader, array $properties, array $indices): array
    {
        $size = null;
        $configurations = 0;
        $codecBits = 0;
        $pixelBits = 0;
        $icc = false;
        $angle = null;
        $mirror = null;
        foreach ($indices as $index) {
            if (!isset($properties[$index])) {
                throw self::invalid('an item is associated with a missing property');
            }
            list($type, $start, $end) = $properties[$index];
            $reader->seek($start);
            switch ($type) {
                case 'ispe':
                    if ($size !== null) {
                        throw self::invalid('an image item has more than one ispe property');
                    }
                    self::readFullBoxHeader($reader, $end, 0);
                    $size = [self::readUint32($reader, $end), self::readUint32($reader, $end)];
                    break;
                case 'av1C':
                    if (++$configurations > 1) {
                        throw self::invalid('an image item has more than one av1C property');
                    }
                    $flags = ord(self::readBytes($reader, $end, 3)[2]);
                    $codecBits = ($flags & 0x40) === 0 ? 8 : (($flags & 0x20) === 0 ? 10 : 12);
                    break;
                case 'pixi':
                    self::readFullBoxHeader($reader, $end, 0);
                    $channels = ord(self::readBytes($reader, $end, 1));
                    foreach (str_split(self::readBytes($reader, $end, max(1, $channels))) as $bits) {
                        $pixelBits = max($pixelBits, ord($bits));
                    }
                    break;
                case 'colr':
                    $colourType = self::readBytes($reader, $end, 4);
                    $icc = $icc || $colourType === 'prof' || $colourType === 'rICC';
                    break;
                case 'irot':
                case 'imir':
                    if (($type === 'irot' ? $angle : $mirror) !== null) {
                        throw self::invalid('an image item has more than one ' . $type . ' property');
                    }
                    $value = ord(self::readBytes($reader, $end, 1));
                    if ($type === 'irot') {
                        $angle = $value & 0x03;
                    } else {
                        $mirror = $value & 0x01;
                    }
                    break;
            }
        }
        if ($size === null || $size[0] === 0 || $size[1] === 0) {
            throw self::invalid('an image item has no ispe property or a zero width or height');
        }

        return [
            'width' => $size[0],
            'height' => $size[1],
            // The codec configuration is authoritative; pixi describes the reconstructed image of a derived item.
            'bitDepth' => $codecBits > 0 ? $codecBits : min(64, max(8, $pixelBits)),
            'icc' => $icc,
            'orientation' => self::orientation($angle, $mirror),
        ];
    }

    /**
     * EXIF orientation of an item rotated by irot, then mirrored by imir: the order HEIF requires and libavif applies
     * whatever the order of the associations.
     *
     * @param int|null $angle  irot angle: anticlockwise quarter turns
     * @param int|null $mirror imir mode: 0 exchanges top and bottom, 1 left and right (ISO/IEC 23008-12:2022)
     */
    private static function orientation(?int $angle, ?int $mirror): int
    {
        $rotation = (4 - ($angle === null ? 0 : $angle)) % 4;
        if ($mirror === null) {
            return self::ORIENTATIONS[$rotation][0];
        }
        // Mirroring left to right after a clockwise rotation r equals mirroring first, then rotating by -r; top to
        // bottom adds a half turn.
        $rotation = ($mirror === 1 ? 4 - $rotation : 6 - $rotation) % 4;

        return self::ORIENTATIONS[$rotation][1];
    }

    /**
     * Payload bounds of the children of the wanted types, each of which may appear once at most.
     *
     * @param list<string> $wanted
     *
     * @throws InvalidImageException
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function findChildren(ByteReader $reader, int $start, int $end, array $wanted): array
    {
        $found = [];
        $reader->seek($start);
        while ($reader->getPosition() < $end) {
            list($type, $childStart, $childEnd) = $this->readBoxHeader($reader, $end);
            if (in_array($type, $wanted, true)) {
                if (isset($found[$type])) {
                    throw self::invalid('a box appears twice where only one is allowed');
                }
                $found[$type] = [$childStart, $childEnd];
            }
            $reader->seek($childEnd);
        }

        return $found;
    }

    /**
     * Handler type of an hdlr box.
     *
     * @param array{0: int, 1: int} $box payload bounds
     *
     * @throws InvalidImageException
     */
    private function readHandler(ByteReader $reader, array $box): string
    {
        $reader->seek($box[0]);
        self::readFullBoxHeader($reader, $box[1], 0);
        $reader->skip(min(4, $box[1] - $reader->getPosition()));

        return self::readBytes($reader, $box[1], 4);
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
                if (isset($items[$id])) {
                    throw self::invalid('the iinf box describes an item twice');
                }
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
     * Property indices (1-based, in order) associated with each item by ipma.
     *
     * @throws InvalidImageException
     *
     * @return array<int, list<int>>
     */
    private function readAssociations(ByteReader $reader, int $start, int $end): array
    {
        $reader->seek($start);
        $version = self::readFullBoxHeader($reader, $end, 1, $flags);
        $count = self::readUint32($reader, $end);
        if ($count > self::MAX_ENTRIES) {
            throw self::invalid('the ipma box has too many entries');
        }
        $wide = ($flags & 0x01) !== 0;

        $associations = [];
        for ($entry = 0; $entry < $count; ++$entry) {
            $id = $version === 0 ? self::readUint16($reader, $end) : self::readUint32($reader, $end);
            if (isset($associations[$id])) {
                throw self::invalid('the ipma box associates properties with an item twice');
            }
            $associationCount = ord(self::readBytes($reader, $end, 1));
            $data = self::readBytes($reader, $end, $associationCount * ($wide ? 2 : 1));
            $indices = [];
            for ($association = 0; $association < $associationCount; ++$association) {
                $index = $wide
                    ? ((ord($data[2 * $association]) << 8) | ord($data[2 * $association + 1])) & 0x7FFF
                    : ord($data[$association]) & 0x7F;
                if ($index !== 0) {
                    $indices[] = $index;
                }
            }
            $associations[$id] = $indices;
        }

        return $associations;
    }

    /**
     * Offset and length of the first extent of each wanted item, from iloc.
     *
     * @param array{0: int, 1: int}      $iloc   payload bounds of iloc
     * @param array{0: int, 1: int}|null $idat   payload bounds of idat, if any
     * @param list<int>                  $wanted item IDs to locate
     *
     * @throws InvalidImageException
     * @throws UnsupportedFormatException
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function readItemLocations(ByteReader $reader, array $iloc, ?array $idat, array $wanted): array
    {
        list($start, $end) = $iloc;
        $reader->seek($start);
        $version = self::readFullBoxHeader($reader, $end, 2);
        $sizes = self::readBytes($reader, $end, 2);
        $offsetSize = ord($sizes[0]) >> 4;
        $lengthSize = ord($sizes[0]) & 0x0F;
        $baseOffsetSize = ord($sizes[1]) >> 4;
        $indexSize = $version > 0 ? ord($sizes[1]) & 0x0F : 0;
        foreach ([$offsetSize, $lengthSize, $baseOffsetSize, $indexSize] as $fieldSize) {
            if ($fieldSize !== 0 && $fieldSize !== 4 && $fieldSize !== 8) {
                throw self::invalid('the iloc box declares an invalid field size');
            }
        }
        $count = $version < 2 ? self::readUint16($reader, $end) : self::readUint32($reader, $end);
        if ($count > self::MAX_ENTRIES) {
            throw self::invalid('the iloc box has too many entries');
        }

        $locations = [];
        $described = [];
        $extents = 0;
        for ($index = 0; $index < $count; ++$index) {
            $id = $version < 2 ? self::readUint16($reader, $end) : self::readUint32($reader, $end);
            $method = $version > 0 ? self::readUint16($reader, $end) & 0x0F : 0;
            $dataReference = self::readUint16($reader, $end);
            $baseOffset = self::readSized($reader, $end, $baseOffsetSize);
            $extentCount = self::readUint16($reader, $end);
            $extents += $extentCount;
            if ($extents > self::MAX_ENTRIES) {
                throw self::invalid('the iloc box has too many extents');
            }
            $first = null;
            for ($extent = 0; $extent < $extentCount; ++$extent) {
                self::readSized($reader, $end, $indexSize);
                $extentOffset = self::readSized($reader, $end, $offsetSize);
                $extentLength = self::readSized($reader, $end, $lengthSize);
                $first = $first === null ? [$extentOffset, $extentLength] : $first;
            }
            if (isset($described[$id])) {
                throw self::invalid('the iloc box locates an item twice');
            }
            $described[$id] = true;
            if (!in_array($id, $wanted, true)) {
                continue;
            }
            if ($dataReference !== 0 || $method === 2) {
                throw new UnsupportedFormatException('AVIF items stored in another file or by reference are not supported.');
            }
            if ($method > 2 || $first === null || ($method === 1 && $idat === null)) {
                throw self::invalid('an image item has no valid data location');
            }
            list($regionStart, $regionEnd) = $method === 0 ? [0, $reader->getSize()] : $idat;
            if ($baseOffset > $regionEnd - $regionStart || $first[0] > $regionEnd - $regionStart - $baseOffset) {
                throw $method === 0 ? ByteReader::truncated() : self::invalid('an image item lies outside the idat box');
            }
            $dataStart = $regionStart + $baseOffset + $first[0];
            $dataLength = $first[1] === 0 ? $regionEnd - $dataStart : $first[1];
            if ($dataLength > $regionEnd - $dataStart) {
                throw $method === 0 ? ByteReader::truncated() : self::invalid('an image item lies outside the idat box');
            }
            $locations[$id] = [$dataStart, $dataLength];
        }

        return $locations;
    }

    /**
     * Reads the image sequence of a moov box: checks every AV1 track and describes the first one.
     *
     * @throws InvalidImageException
     * @throws LimitExceededException
     * @throws UnsupportedFormatException
     *
     * @return array{0: int, 1: int, 2: int}|null width, height and frame count
     */
    private function parseMovie(ByteReader $reader, int $start, int $end, Limits $limits): ?array
    {
        $first = null;
        $tracks = 0;
        $reader->seek($start);
        while ($reader->getPosition() < $end) {
            list($type, $trackStart, $trackEnd) = $this->readBoxHeader($reader, $end);
            if ($type === 'trak') {
                $track = $this->parseTrack($reader, $trackStart, $trackEnd, $limits);
                if ($track !== null && ++$tracks > self::MAX_IMAGES) {
                    throw new UnsupportedFormatException(sprintf('AVIF files with more than %d AV1 tracks are not supported.', self::MAX_IMAGES));
                }
                $first = $first === null ? $track : $first;
            }
            $reader->seek($trackEnd);
        }

        return $first;
    }

    /**
     * Reads a track; null when it holds no AV1 samples.
     *
     * @throws InvalidImageException
     * @throws LimitExceededException
     * @throws UnsupportedFormatException
     *
     * @return array{0: int, 1: int, 2: int}|null width, height and frame count
     */
    private function parseTrack(ByteReader $reader, int $start, int $end, Limits $limits): ?array
    {
        $track = $this->findChildren($reader, $start, $end, ['mdia']);
        $media = isset($track['mdia']) ? $this->findChildren($reader, $track['mdia'][0], $track['mdia'][1], ['mdhd', 'hdlr', 'minf']) : [];
        $information = isset($media['minf']) ? $this->findChildren($reader, $media['minf'][0], $media['minf'][1], ['stbl']) : [];
        $table = isset($information['stbl'])
            ? $this->findChildren($reader, $information['stbl'][0], $information['stbl'][1], ['stsd', 'stts', 'stsc', 'stsz', 'stz2', 'stco', 'co64', 'stss'])
            : [];
        if (!isset($table['stsd'])) {
            return null;
        }

        list($description, $descriptionEnd) = $table['stsd'];
        $reader->seek($description);
        self::readFullBoxHeader($reader, $descriptionEnd, 0);
        $entries = self::readUint32($reader, $descriptionEnd);
        if ($entries === 0) {
            return null;
        }
        list($entryType, $entryStart, $entryEnd) = $this->readBoxHeader($reader, $descriptionEnd);
        if ($entryType !== 'av01') {
            return null;
        }
        if ($entries > 1) {
            throw new UnsupportedFormatException('AVIF tracks with several sample descriptions are not supported.');
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

        if (isset($table['stsz']) === isset($table['stz2'])) {
            throw self::invalid('an image sequence track needs exactly one sample size table');
        }
        $sizes = $table['stsz'] ?? $table['stz2'];
        $reader->seek($sizes[0]);
        self::readFullBoxHeader($reader, $sizes[1], 0);
        $reader->skip(min(4, $sizes[1] - $reader->getPosition()));
        $frames = self::readUint32($reader, $sizes[1]);
        if ($frames === 0) {
            throw self::invalid('an image sequence track has no sample');
        }
        $limits->assertImage($width, $height, $frames);

        if (isset($table['stco']) === isset($table['co64'])) {
            throw self::invalid('an image sequence track needs exactly one chunk offset table');
        }
        $chunks = $table['stco'] ?? $table['co64'];
        $reader->seek($chunks[0]);
        self::readFullBoxHeader($reader, $chunks[1], 0);
        if (self::readUint32($reader, $chunks[1]) === 0) {
            throw self::invalid('an image sequence track has no chunk');
        }
        // The first sample starts its first chunk.
        $sample = self::readSized($reader, $chunks[1], isset($table['stco']) ? 4 : 8);
        if ($sample > $reader->getSize()) {
            throw ByteReader::truncated();
        }
        list($codedWidth, $codedHeight) = Av1SequenceHeader::maxFrameSize($reader, $sample, $reader->getSize() - $sample);
        if ($codedWidth > $width || $codedHeight > $height) {
            throw self::invalid('the AV1 data of a track allows frames larger than its sample entry');
        }

        return [$width, $height, $frames];
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
     * Reads an unsigned field of 0, 4 or 8 bytes; a 64-bit value above PHP_INT_MAX reads as PHP_INT_MAX.
     *
     * @throws InvalidImageException
     */
    private static function readSized(ByteReader $reader, int $end, int $size): int
    {
        if ($size === 0) {
            return 0;
        }
        $value = self::readUint32($reader, $end);
        if ($size === 4) {
            return $value;
        }
        $low = self::readUint32($reader, $end);

        return $value > 0x7FFFFFFF ? PHP_INT_MAX : ($value << 32) | $low;
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
