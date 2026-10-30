<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract;

use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;

/**
 * Image formats known to the library, as lowercase string constants, with their MIME types and file extensions.
 *
 * Being known does not mean being enabled: which formats a converter accepts as input or produces as output is
 * reported by Capabilities. A format is always detected from the file content (magic bytes), never from a name,
 * extension or declared MIME type; the MIME helpers below only translate names.
 */
final class ImageFormat
{
    public const JPEG = 'jpeg';
    public const PNG = 'png';
    public const GIF = 'gif';
    public const BMP = 'bmp';
    public const WEBP = 'webp';
    public const AVIF = 'avif';
    public const TIFF = 'tiff';
    public const SVG = 'svg';

    /**
     * Canonical MIME type of each format, in canonical format order.
     *
     * @var array<string, string>
     */
    private const MIME_TYPES = [
        self::JPEG => 'image/jpeg',
        self::PNG => 'image/png',
        self::GIF => 'image/gif',
        self::BMP => 'image/bmp',
        self::WEBP => 'image/webp',
        self::AVIF => 'image/avif',
        self::TIFF => 'image/tiff',
        self::SVG => 'image/svg+xml',
    ];

    /**
     * Preferred file extension of each format, without the leading dot.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        self::JPEG => 'jpg',
        self::PNG => 'png',
        self::GIF => 'gif',
        self::BMP => 'bmp',
        self::WEBP => 'webp',
        self::AVIF => 'avif',
        self::TIFF => 'tiff',
        self::SVG => 'svg',
    ];

    /**
     * Non-canonical MIME types still produced by libmagic, older browsers and servers.
     *
     * @var array<string, string>
     */
    private const MIME_ALIASES = [
        'image/jpg' => self::JPEG,
        'image/pjpeg' => self::JPEG,
        'image/x-png' => self::PNG,
        'image/x-bmp' => self::BMP,
        'image/x-ms-bmp' => self::BMP,
        'image/svg' => self::SVG,
    ];

    private function __construct()
    {
    }

    /**
     * Every known format, in canonical order: jpeg, png, gif, bmp, webp, avif, tiff, svg.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::MIME_TYPES);
    }

    /**
     * Whether the string is a known format name. Names are case-sensitive and always lowercase.
     */
    public static function isValid(string $format): bool
    {
        return isset(self::MIME_TYPES[$format]);
    }

    /**
     * Canonical MIME type of a format, e.g. "image/svg+xml" for svg.
     *
     * @throws UnsupportedFormatException when the format is not known
     */
    public static function mimeType(string $format): string
    {
        return self::MIME_TYPES[self::assertKnown($format)];
    }

    /**
     * Preferred file extension of a format, without the leading dot, e.g. "jpg" for jpeg.
     *
     * @throws UnsupportedFormatException when the format is not known
     */
    public static function extension(string $format): string
    {
        return self::EXTENSIONS[self::assertKnown($format)];
    }

    /**
     * Format named by a MIME type, or null when the MIME type is not an image type known to the library.
     *
     * Matching is case-insensitive, ignores surrounding whitespace and media type parameters (anything after ";"),
     * and accepts the aliases listed in MIME_ALIASES (for example image/x-ms-bmp, as reported by libmagic).
     */
    public static function tryFromMimeType(string $mimeType): ?string
    {
        $semicolon = strpos($mimeType, ';');
        if ($semicolon !== false) {
            $mimeType = substr($mimeType, 0, $semicolon);
        }
        $mimeType = strtolower(trim($mimeType, " \t"));

        $format = array_search($mimeType, self::MIME_TYPES, true);
        if (is_string($format)) {
            return $format;
        }

        return isset(self::MIME_ALIASES[$mimeType]) ? self::MIME_ALIASES[$mimeType] : null;
    }

    /**
     * Format named by a MIME type, see tryFromMimeType().
     *
     * @throws UnsupportedFormatException when the MIME type is not an image type known to the library
     */
    public static function fromMimeType(string $mimeType): string
    {
        $format = self::tryFromMimeType($mimeType);
        if ($format === null) {
            throw new UnsupportedFormatException('The MIME type is not a supported image type.');
        }

        return $format;
    }

    /**
     * @throws UnsupportedFormatException
     */
    private static function assertKnown(string $format): string
    {
        if (!self::isValid($format)) {
            throw new UnsupportedFormatException(sprintf(
                'Unknown image format; expected one of: %s.',
                implode(', ', self::all())
            ));
        }

        return $format;
    }
}
