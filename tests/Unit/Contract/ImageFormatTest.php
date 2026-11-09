<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Contract;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Contract\ImageFormat
 */
#[CoversClass(ImageFormat::class)]
final class ImageFormatTest extends TestCase
{
    public function testAllListsEveryFormatInCanonicalOrder(): void
    {
        self::assertSame(['jpeg', 'png', 'gif', 'bmp', 'webp', 'avif', 'tiff', 'svg'], ImageFormat::all());
        self::assertSame(
            [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::BMP, ImageFormat::WEBP, ImageFormat::AVIF, ImageFormat::TIFF, ImageFormat::SVG],
            ImageFormat::all()
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function formats(): array
    {
        return [
            'jpeg' => ['jpeg', 'image/jpeg', 'jpg'],
            'png' => ['png', 'image/png', 'png'],
            'gif' => ['gif', 'image/gif', 'gif'],
            'bmp' => ['bmp', 'image/bmp', 'bmp'],
            'webp' => ['webp', 'image/webp', 'webp'],
            'avif' => ['avif', 'image/avif', 'avif'],
            'tiff' => ['tiff', 'image/tiff', 'tiff'],
            'svg' => ['svg', 'image/svg+xml', 'svg'],
        ];
    }

    /**
     * @dataProvider formats
     */
    #[DataProvider('formats')]
    public function testFormatMapsToMimeTypeAndExtensionAndBack(string $format, string $mimeType, string $extension): void
    {
        self::assertTrue(ImageFormat::isValid($format));
        self::assertSame($mimeType, ImageFormat::mimeType($format));
        self::assertSame($extension, ImageFormat::extension($format));
        self::assertSame($format, ImageFormat::fromMimeType($mimeType));
        self::assertSame($format, ImageFormat::tryFromMimeType($mimeType));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownFormats(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['JPEG'],
            'extension instead of format' => ['jpg'],
            'trailing space' => ['png '],
            'unknown' => ['heic'],
            'target pseudo-format' => ['original'],
        ];
    }

    /**
     * @dataProvider unknownFormats
     */
    #[DataProvider('unknownFormats')]
    public function testUnknownFormatIsInvalid(string $format): void
    {
        self::assertFalse(ImageFormat::isValid($format));
    }

    /**
     * @dataProvider unknownFormats
     */
    #[DataProvider('unknownFormats')]
    public function testMimeTypeOfUnknownFormatThrows(string $format): void
    {
        $this->expectException(InvalidArgumentException::class);

        ImageFormat::mimeType($format);
    }

    /**
     * @dataProvider unknownFormats
     */
    #[DataProvider('unknownFormats')]
    public function testExtensionOfUnknownFormatThrows(string $format): void
    {
        $this->expectException(InvalidArgumentException::class);

        ImageFormat::extension($format);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function mimeTypeVariants(): array
    {
        return [
            'uppercase' => ['IMAGE/PNG', 'png'],
            'parameters' => ['image/jpeg; charset=binary', 'jpeg'],
            'surrounding whitespace' => [" \timage/webp ", 'webp'],
            'alias image/jpg' => ['image/jpg', 'jpeg'],
            'alias image/pjpeg' => ['image/pjpeg', 'jpeg'],
            'alias image/x-png' => ['image/x-png', 'png'],
            'alias image/x-ms-bmp (libmagic)' => ['image/x-ms-bmp', 'bmp'],
            'alias image/x-bmp' => ['image/x-bmp', 'bmp'],
            'alias image/svg' => ['image/svg', 'svg'],
        ];
    }

    /**
     * @dataProvider mimeTypeVariants
     */
    #[DataProvider('mimeTypeVariants')]
    public function testMimeTypeVariantsResolve(string $mimeType, string $format): void
    {
        self::assertSame($format, ImageFormat::fromMimeType($mimeType));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownMimeTypes(): array
    {
        return [
            'empty' => [''],
            'not an image' => ['text/html'],
            'unknown image type' => ['image/heic'],
            'octet stream' => ['application/octet-stream'],
            'type without subtype' => ['image'],
            'embedded newline' => ["image/png\nX-Injected: 1"],
        ];
    }

    /**
     * @dataProvider unknownMimeTypes
     */
    #[DataProvider('unknownMimeTypes')]
    public function testUnknownMimeTypeIsRejected(string $mimeType): void
    {
        self::assertNull(ImageFormat::tryFromMimeType($mimeType));

        $this->expectException(UnsupportedFormatException::class);
        ImageFormat::fromMimeType($mimeType);
    }

    public function testAnimatableFormats(): void
    {
        self::assertSame(['png', 'gif', 'webp', 'avif'], ImageFormat::animatable());
        self::assertSame(array_values(array_intersect(ImageFormat::all(), ImageFormat::animatable())), ImageFormat::animatable());

        foreach (ImageFormat::all() as $format) {
            self::assertSame(in_array($format, ['png', 'gif', 'webp', 'avif'], true), ImageFormat::supportsAnimation($format), $format);
        }
        self::assertFalse(ImageFormat::supportsAnimation('GIF'));
        self::assertFalse(ImageFormat::supportsAnimation('apng'));
    }
}
