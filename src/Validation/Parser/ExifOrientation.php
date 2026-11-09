<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Validation\ImageInfo;

/**
 * Reads the Orientation tag (0x0112) from the first directory (IFD0) of the TIFF structure of an EXIF block.
 *
 * EXIF is metadata, not image structure: a block that cannot be read (wrong byte order mark, directory outside the
 * block, more than MAX_ENTRIES entries, a tag of the wrong type or count, a value outside 1 to 8) gives the normal
 * orientation instead of refusing the image. Only IFD0 is read, at most MAX_ENTRIES entries of it.
 *
 * @internal
 */
final class ExifOrientation
{
    /**
     * Largest number of IFD0 entries read. Real EXIF blocks have a few dozen.
     */
    public const MAX_ENTRIES = 1024;

    private const TAG_ORIENTATION = 0x0112;

    private const TYPE_SHORT = 3;

    /**
     * @param int $offset offset in the image of the TIFF header ("II*\0" or "MM\0*") of the EXIF block
     * @param int $length bytes of the EXIF block from that offset
     *
     * @throws InvalidImageException when the stream returns fewer bytes than the image size announces
     *
     * @return int the orientation, from 1 to 8
     */
    public static function read(ByteReader $reader, int $offset, int $length): int
    {
        if ($offset < 0 || $length < 8 || $offset > $reader->getSize() || $length > $reader->getSize() - $offset) {
            return ImageInfo::ORIENTATION_NORMAL;
        }
        $reader->seek($offset);
        $header = $reader->read(8);
        $byteOrder = substr($header, 0, 4);
        if ($byteOrder !== "II*\0" && $byteOrder !== "MM\0*") {
            return ImageInfo::ORIENTATION_NORMAL;
        }
        $littleEndian = $header[0] === 'I';
        $directory = self::unpack($littleEndian ? 'V' : 'N', $header, 4);
        if ($directory < 8 || $directory > $length - 2) {
            return ImageInfo::ORIENTATION_NORMAL;
        }

        $reader->seek($offset + $directory);
        $count = self::unpack($littleEndian ? 'v' : 'n', $reader->read(2), 0);
        if ($count > self::MAX_ENTRIES || 12 * $count > $length - $directory - 2) {
            return ImageInfo::ORIENTATION_NORMAL;
        }
        $entries = $reader->read(12 * $count);
        for ($index = 0; $index < $count; ++$index) {
            $entry = (string) substr($entries, 12 * $index, 12);
            if (self::unpack($littleEndian ? 'v' : 'n', $entry, 0) !== self::TAG_ORIENTATION) {
                continue;
            }
            $value = self::unpack($littleEndian ? 'v' : 'n', $entry, 8);
            $valid = self::unpack($littleEndian ? 'v' : 'n', $entry, 2) === self::TYPE_SHORT
                && self::unpack($littleEndian ? 'V' : 'N', $entry, 4) === 1
                && $value >= ImageInfo::ORIENTATION_NORMAL && $value <= ImageInfo::ORIENTATION_MAX;

            return $valid ? $value : ImageInfo::ORIENTATION_NORMAL;
        }

        return ImageInfo::ORIENTATION_NORMAL;
    }

    private static function unpack(string $format, string $bytes, int $offset): int
    {
        $value = unpack($format, $bytes, $offset);

        return is_array($value) && is_int($value[1]) ? $value[1] : 0;
    }
}
