<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Validation;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Tests\Unit\Contract\SerializedStateTrait;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Validation\ImageInfo
 */
#[CoversClass(ImageInfo::class)]
final class ImageInfoTest extends TestCase
{
    use SerializedStateTrait;

    public function testNewInfoDescribesAnEightBitImageWithoutMetadata(): void
    {
        $info = new ImageInfo(ImageFormat::GIF, 640, 480, 12, 34567);

        self::assertSame(ImageFormat::GIF, $info->getFormat());
        self::assertSame('image/gif', $info->getMimeType());
        self::assertSame(640, $info->getWidth());
        self::assertSame(480, $info->getHeight());
        self::assertSame(12, $info->getFrameCount());
        self::assertTrue($info->isAnimated());
        self::assertSame(34567, $info->getByteSize());
        self::assertSame(8, $info->getBitDepth());
        self::assertFalse($info->hasIccProfile());
        self::assertFalse($info->hasExif());
        self::assertFalse($info->hasMetadata());
        self::assertSame(ImageInfo::ORIENTATION_NORMAL, $info->getOrientation());
        self::assertFalse($info->hasTrailingData());
    }

    public function testToArrayListsEveryFact(): void
    {
        $info = (new ImageInfo(ImageFormat::PNG, 3, 2, 1, 99))->withBitDepth(16)->withMetadata(true, false, true)
            ->withOrientation(6)->withTrailingData(true);

        self::assertSame([
            'format' => 'png',
            'mime_type' => 'image/png',
            'width' => 3,
            'height' => 2,
            'frame_count' => 1,
            'byte_size' => 99,
            'bit_depth' => 16,
            'icc_profile' => true,
            'exif' => false,
            'metadata' => true,
            'orientation' => 6,
            'trailing_data' => true,
            'variants' => [],
        ], $info->toArray());
        self::assertFalse($info->isAnimated());
    }

    public function testWithMethodsReturnModifiedCopies(): void
    {
        $info = new ImageInfo(ImageFormat::AVIF, 10, 10, 1, 10);
        $deep = $info->withBitDepth(12);
        $tagged = $info->withMetadata(false, true, false);

        self::assertNotSame($info, $deep);
        self::assertSame(8, $info->getBitDepth());
        self::assertSame(12, $deep->getBitDepth());
        self::assertFalse($info->hasExif());
        self::assertTrue($tagged->hasExif());
        self::assertTrue($tagged->hasMetadata(), 'EXIF is metadata');
        self::assertFalse($tagged->hasIccProfile());
        self::assertFalse($info->withMetadata(true, false, false)->hasMetadata(), 'a colour profile alone is not metadata');
    }

    /**
     * @return array<string, array{string, int, int, int, int}>
     */
    public static function invalidConstructorArguments(): array
    {
        return [
            'unknown format' => ['jpg', 1, 1, 1, 1],
            'svg is not a raster format' => [ImageFormat::SVG, 1, 1, 1, 1],
            'zero width' => [ImageFormat::PNG, 0, 1, 1, 1],
            'zero height' => [ImageFormat::PNG, 1, 0, 1, 1],
            'width above LIMIT_VALUE_MAX' => [ImageFormat::PNG, Capabilities::LIMIT_VALUE_MAX + 1, 1, 1, 1],
            'height above LIMIT_VALUE_MAX' => [ImageFormat::PNG, 1, Capabilities::LIMIT_VALUE_MAX + 1, 1, 1],
            'zero frames' => [ImageFormat::GIF, 1, 1, 0, 1],
            'frames above LIMIT_VALUE_MAX' => [ImageFormat::GIF, 1, 1, Capabilities::LIMIT_VALUE_MAX + 1, 1],
            'frames of a format that is not animatable' => [ImageFormat::JPEG, 1, 1, 2, 1],
            'zero bytes' => [ImageFormat::BMP, 1, 1, 1, 0],
        ];
    }

    /**
     * @dataProvider invalidConstructorArguments
     */
    #[DataProvider('invalidConstructorArguments')]
    public function testConstructorRejectsValuesOutsideTheirDomain(string $format, int $width, int $height, int $frames, int $bytes): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ImageInfo($format, $width, $height, $frames, $bytes);
    }

    public function testConstructorAcceptsTheExtremesOfEveryDomain(): void
    {
        $info = new ImageInfo(ImageFormat::WEBP, Capabilities::LIMIT_VALUE_MAX, 1, Capabilities::LIMIT_VALUE_MAX, PHP_INT_MAX);

        self::assertSame(Capabilities::LIMIT_VALUE_MAX, $info->getWidth());
        self::assertSame(Capabilities::LIMIT_VALUE_MAX, $info->getFrameCount());
        self::assertSame(PHP_INT_MAX, $info->getByteSize());
    }

    public function testBitDepthMustBeWithinItsRange(): void
    {
        $info = new ImageInfo(ImageFormat::TIFF, 1, 1, 1, 1);

        self::assertSame(ImageInfo::BIT_DEPTH_MAX, $info->withBitDepth(ImageInfo::BIT_DEPTH_MAX)->getBitDepth());
        $this->expectException(InvalidArgumentException::class);

        $info->withBitDepth(ImageInfo::BIT_DEPTH_MIN - 1);
    }

    public function testBitDepthAboveTheRangeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ImageInfo(ImageFormat::TIFF, 1, 1, 1, 1))->withBitDepth(ImageInfo::BIT_DEPTH_MAX + 1);
    }

    public function testOrientationAndTrailingDataAreSetOnCopies(): void
    {
        $info = new ImageInfo(ImageFormat::JPEG, 10, 10, 1, 10);
        $turned = $info->withOrientation(ImageInfo::ORIENTATION_MAX);
        $padded = $info->withTrailingData(true);

        self::assertSame(ImageInfo::ORIENTATION_NORMAL, $info->getOrientation());
        self::assertSame(ImageInfo::ORIENTATION_MAX, $turned->getOrientation());
        self::assertFalse($info->hasTrailingData());
        self::assertTrue($padded->hasTrailingData());
        self::assertFalse($padded->withTrailingData(false)->hasTrailingData());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidOrientations(): array
    {
        return [
            'zero' => [0],
            'nine' => [9],
            'negative' => [-1],
        ];
    }

    /**
     * @dataProvider invalidOrientations
     */
    #[DataProvider('invalidOrientations')]
    public function testOrientationMustBeAnExifValue(int $orientation): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ImageInfo(ImageFormat::JPEG, 1, 1, 1, 1))->withOrientation($orientation);
    }

    public function testVariantsAreSetOnCopiesInCanonicalOrder(): void
    {
        $info = new ImageInfo(ImageFormat::BMP, 10, 10, 1, 10);
        $variant = $info->withVariants([ImageInfo::VARIANT_BMP_V3_HEADER, ImageInfo::VARIANT_BMP_BIT_FIELDS]);

        self::assertSame([], $info->getVariants());
        self::assertSame([ImageInfo::VARIANT_BMP_BIT_FIELDS, ImageInfo::VARIANT_BMP_V3_HEADER], $variant->getVariants());
        self::assertSame([ImageInfo::VARIANT_BMP_BIT_FIELDS, ImageInfo::VARIANT_BMP_V3_HEADER], $variant->toArray()['variants']);
        self::assertSame([], $variant->withVariants([])->getVariants());
        foreach (ImageInfo::VARIANTS as $name => $format) {
            self::assertSame([$name], (new ImageInfo($format, 1, 1, 1, 1))->withVariants([$name])->getVariants());
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function invalidVariants(): array
    {
        return [
            'variant of another format' => [ImageFormat::PNG, [ImageInfo::VARIANT_JPEG_CMYK]],
            'unknown variant' => [ImageFormat::JPEG, ['progressive']],
            'repeated variant' => [ImageFormat::BMP, [ImageInfo::VARIANT_BMP_2_BIT, ImageInfo::VARIANT_BMP_2_BIT]],
        ];
    }

    /**
     * @param list<string> $variants
     *
     * @dataProvider invalidVariants
     */
    #[DataProvider('invalidVariants')]
    public function testVariantsMustBeDistinctVariantsOfTheFormat(string $format, array $variants): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ImageInfo($format, 1, 1, 1, 1))->withVariants($variants);
    }

    public function testSerializationRoundTrip(): void
    {
        $info = (new ImageInfo(ImageFormat::GIF, 5, 6, 7, 8))->withBitDepth(16)->withMetadata(true, true, true)
            ->withOrientation(3)->withTrailingData(true);
        $cmyk = (new ImageInfo(ImageFormat::JPEG, 5, 6, 1, 8))->withVariants([ImageInfo::VARIANT_JPEG_CMYK]);

        self::assertEquals($info, unserialize(serialize($info), ['allowed_classes' => [ImageInfo::class]]));
        self::assertEquals($cmyk, unserialize(serialize($cmyk), ['allowed_classes' => [ImageInfo::class]]));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function tamperedStates(): array
    {
        return [
            'svg format' => ['format', ImageFormat::SVG],
            'zero width' => ['width', 0],
            'negative height' => ['height', -1],
            'frames of a still format' => ['frameCount', 2],
            'zero byte size' => ['byteSize', 0],
            'bit depth below range' => ['bitDepth', 4],
            'string width' => ['width', '10'],
            'integer flag' => ['iccProfile', 1],
            'null flag' => ['otherMetadata', null],
            'orientation zero' => ['orientation', 0],
            'orientation nine' => ['orientation', 9],
            'string orientation' => ['orientation', '1'],
            'integer trailing data flag' => ['trailingData', 0],
            'variant of another format' => ['variants', [ImageInfo::VARIANT_BMP_2_BIT]],
            'unknown variant' => ['variants', ['progressive']],
            'repeated variant' => ['variants', [ImageInfo::VARIANT_JPEG_CMYK, ImageInfo::VARIANT_JPEG_CMYK]],
            'string variants' => ['variants', ImageInfo::VARIANT_JPEG_CMYK],
            'variants keyed by name' => ['variants', ['cmyk' => ImageInfo::VARIANT_JPEG_CMYK]],
        ];
    }

    /**
     * @param mixed $value
     *
     * @dataProvider tamperedStates
     */
    #[DataProvider('tamperedStates')]
    public function testUnserializeRejectsATamperedState(string $property, $value): void
    {
        $state = self::stateOf(new ImageInfo(ImageFormat::JPEG, 10, 10, 1, 100));
        self::assertArrayHasKey($property, $state);
        $state[$property] = $value;

        $this->expectException(InvalidArgumentException::class);

        unserialize(self::serializedWithState(ImageInfo::class, $state), ['allowed_classes' => [ImageInfo::class]]);
    }
}
