<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation;

use Mfonte\SecureImageConverter\Contract\ImageFormat;

/**
 * Identifies the image format of a file from the first bytes of its content (magic bytes), never from its name,
 * extension or declared MIME type.
 *
 * Signatures, all at offset 0:
 * - JPEG: FF D8 FF;
 * - PNG: the 8-byte PNG signature;
 * - GIF: "GIF87a" or "GIF89a";
 * - BMP: "BM" followed, at offset 14, by the size of a known DIB header (12, 16, 40, 52, 56, 64, 108 or 124);
 * - WEBP: "RIFF", any 4-byte size, then "WEBP" (other RIFF files such as AVI or WAV are not identified);
 * - TIFF: "II*\0" or "MM\0*" (classic TIFF only; BigTIFF is not identified);
 * - AVIF: an ISO-BMFF "ftyp" box of at least 16 bytes whose major brand or one of the compatible brands present in
 *   the head is "avif" or "avis" (HEIC, plain "mif1" and other brands are not identified);
 * - SVG: text whose first element is <svg, after an optional UTF-8 byte order mark, whitespace, an XML declaration
 *   or other processing instructions, comments and a doctype (internal subset included). An HTML document is not
 *   identified, even with inline SVG.
 *
 * Detection only looks at the head and validates nothing else: ImageInspector parses the header of the identified
 * format. It is pure string inspection: it never reads files, decodes images or parses XML with an XML parser.
 */
final class FormatSniffer
{
    /**
     * Number of leading bytes detect() needs: pass the first HEAD_LENGTH bytes of the file, or the whole file when it
     * is shorter. Bytes beyond are ignored.
     */
    public const HEAD_LENGTH = 4096;

    /**
     * DIB header sizes of the known BMP variants: core (12), OS/2 2.x (16 and 64), info (40), V2 (52), V3 (56),
     * V4 (108) and V5 (124).
     *
     * @var list<int>
     */
    private const BMP_DIB_HEADER_SIZES = [12, 16, 40, 52, 56, 64, 108, 124];

    /**
     * ISO-BMFF brands of AVIF: still images and image sequences.
     *
     * @var list<string>
     */
    private const AVIF_BRANDS = ['avif', 'avis'];

    /**
     * Format of the content starting with $head, or null when it matches none of the formats above.
     *
     * @param string $head the first HEAD_LENGTH bytes of the file, or the whole file when shorter
     *
     * @return string|null an ImageFormat value
     */
    public function detect(string $head): ?string
    {
        if (strlen($head) > self::HEAD_LENGTH) {
            $head = substr($head, 0, self::HEAD_LENGTH);
        }

        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return ImageFormat::JPEG;
        }
        if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return ImageFormat::PNG;
        }
        if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
            return ImageFormat::GIF;
        }
        if (strncmp($head, 'BM', 2) === 0 && strlen($head) >= 18 && in_array(self::uint32Le($head, 14), self::BMP_DIB_HEADER_SIZES, true)) {
            return ImageFormat::BMP;
        }
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            return ImageFormat::WEBP;
        }
        if (strncmp($head, "II*\0", 4) === 0 || strncmp($head, "MM\0*", 4) === 0) {
            return ImageFormat::TIFF;
        }
        if (self::isAvif($head)) {
            return ImageFormat::AVIF;
        }
        if (self::isSvg($head)) {
            return ImageFormat::SVG;
        }

        return null;
    }

    /**
     * Whether the head starts with an ftyp box listing an AVIF brand.
     */
    private static function isAvif(string $head): bool
    {
        if (strlen($head) < 16 || substr($head, 4, 4) !== 'ftyp') {
            return false;
        }
        $size = self::uint32Be($head, 0);
        if ($size < 16) {
            return false;
        }

        // Major brand at 8, minor version at 12, compatible brands from 16 to the end of the box (or of the head).
        $end = min($size, strlen($head));
        if (in_array(substr($head, 8, 4), self::AVIF_BRANDS, true)) {
            return true;
        }
        for ($offset = 16; $offset + 4 <= $end; $offset += 4) {
            if (in_array(substr($head, $offset, 4), self::AVIF_BRANDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the first element of the text is <svg. Every step consumes at least one byte, so the scan is bounded by
     * the length of the head.
     */
    private static function isSvg(string $head): bool
    {
        $length = strlen($head);
        $offset = strncmp($head, "\xEF\xBB\xBF", 3) === 0 ? 3 : 0;

        while ($offset < $length) {
            $offset += strspn($head, " \t\r\n", $offset);
            if ($offset >= $length || $head[$offset] !== '<') {
                return false;
            }

            if (substr($head, $offset, 2) === '<?') {
                $end = strpos($head, '?>', $offset + 2);
                if ($end === false) {
                    return false;
                }
                $offset = $end + 2;
            } elseif (substr($head, $offset, 4) === '<!--') {
                $end = strpos($head, '-->', $offset + 4);
                if ($end === false) {
                    return false;
                }
                $offset = $end + 3;
            } elseif (substr($head, $offset, 9) === '<!DOCTYPE') {
                $end = self::endOfDoctype($head, $offset + 9);
                if ($end === null) {
                    return false;
                }
                $offset = $end + 1;
            } else {
                return substr($head, $offset, 4) === '<svg'
                    && $offset + 4 < $length
                    && strpos(" \t\r\n/>", $head[$offset + 4]) !== false;
            }
        }

        return false;
    }

    /**
     * Offset of the ">" closing a doctype, skipping comments, quoted literals and the bracketed internal subset, or
     * null when it is not within the head.
     */
    private static function endOfDoctype(string $head, int $offset): ?int
    {
        $length = strlen($head);
        $depth = 0;
        for (; $offset < $length; ++$offset) {
            $char = $head[$offset];
            if ($char === '<' && substr($head, $offset, 4) === '<!--') {
                $end = strpos($head, '-->', $offset + 4);
                if ($end === false) {
                    return null;
                }
                $offset = $end + 2;
            } elseif ($char === '"' || $char === "'") {
                $end = strpos($head, $char, $offset + 1);
                if ($end === false) {
                    return null;
                }
                $offset = $end;
            } elseif ($char === '[') {
                ++$depth;
            } elseif ($char === ']') {
                --$depth;
            } elseif ($char === '>' && $depth <= 0) {
                return $offset;
            }
        }

        return null;
    }

    private static function uint32Le(string $bytes, int $offset): int
    {
        $value = unpack('V', substr($bytes, $offset, 4));

        return is_array($value) && is_int($value[1]) ? $value[1] : -1;
    }

    private static function uint32Be(string $bytes, int $offset): int
    {
        $value = unpack('N', substr($bytes, $offset, 4));

        return is_array($value) && is_int($value[1]) ? $value[1] : -1;
    }
}
