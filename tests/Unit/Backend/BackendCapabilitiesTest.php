<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\BackendCapabilities;
use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Backend\BackendCapabilities
 */
#[CoversClass(BackendCapabilities::class)]
final class BackendCapabilitiesTest extends TestCase
{
    use BackendFixtures;

    private static function full(): BackendCapabilities
    {
        return (new BackendCapabilities('vips', [ImageFormat::WEBP, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::JPEG], [ImageFormat::WEBP, ImageFormat::PNG, ImageFormat::AVIF]))
            ->withInputVariants([ImageInfo::VARIANT_JPEG_CMYK])
            ->withFirstFrameFormats([ImageFormat::GIF, ImageFormat::WEBP])
            ->withAnimatedInputFormats([ImageFormat::GIF])
            ->withAnimatedOutputFormats([ImageFormat::WEBP])
            ->withLosslessFormats([ImageFormat::WEBP])
            ->withFits([ConversionRequest::FIT_FILL, ConversionRequest::FIT_CONTAIN, ConversionRequest::FIT_COVER])
            ->withAnimatedFits([ConversionRequest::FIT_CONTAIN])
            ->withOrientedFormats([ImageFormat::JPEG])
            ->withIccProfile(true)
            ->withMetadata(false)
            ->withNotes(['its resize with fit cover of an animation had the wrong size']);
    }

    public function testFormatsAndFitsAreKeptInCanonicalOrder(): void
    {
        $capabilities = self::full();

        self::assertSame('vips', $capabilities->getBackend());
        self::assertTrue($capabilities->isAvailable());
        self::assertNull($capabilities->getUnavailableReason());
        self::assertSame([ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::WEBP], $capabilities->getInputFormats());
        self::assertSame([ImageFormat::PNG, ImageFormat::WEBP, ImageFormat::AVIF], $capabilities->getOutputFormats());
        self::assertSame([ImageInfo::VARIANT_JPEG_CMYK], $capabilities->getInputVariants());
        self::assertSame([ImageFormat::GIF, ImageFormat::WEBP], $capabilities->getFirstFrameFormats());
        self::assertSame(ConversionRequest::fitModes(), $capabilities->getFits());
        self::assertSame([ConversionRequest::FIT_CONTAIN], $capabilities->getAnimatedFits());
        self::assertTrue($capabilities->keepsIccProfile());
        self::assertFalse($capabilities->keepsMetadata());
    }

    public function testAnUnavailableBackendListsNothing(): void
    {
        $capabilities = BackendCapabilities::unavailable('gd', 'php is not installed');

        self::assertFalse($capabilities->isAvailable());
        self::assertSame('php is not installed', $capabilities->getUnavailableReason());
        self::assertSame([], $capabilities->getInputFormats());
        self::assertSame('it is unavailable: php is not installed', $capabilities->explainUnsupported(self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP)));
        $this->expectException(InvalidArgumentException::class);
        $capabilities->withFits([ConversionRequest::FIT_FILL]);
    }

    public function testTheArrayFormRoundTrips(): void
    {
        foreach ([self::full(), BackendCapabilities::unavailable('gd', 'the gd extension is missing')] as $capabilities) {
            self::assertEquals($capabilities, BackendCapabilities::fromArray($capabilities->toArray()));
            self::assertSame($capabilities->toArray(), BackendCapabilities::fromArray($capabilities->toArray())->toArray());
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidArrays(): array
    {
        $valid = self::full()->toArray();
        $unavailable = BackendCapabilities::unavailable('gd', 'missing')->toArray();
        $cases = [
            'other schema' => ['schema' => 1] + $valid,
            'variant of a format that is not an input' => ['input_variants' => [ImageInfo::VARIANT_BMP_BIT_FIELDS]] + $valid,
            'unknown variant' => ['input_variants' => ['progressive']] + $valid,
            'repeated variant' => ['input_variants' => [ImageInfo::VARIANT_JPEG_CMYK, ImageInfo::VARIANT_JPEG_CMYK]] + $valid,
            'unknown key' => $valid + ['extra' => true],
            'available with a reason' => ['unavailable_reason' => 'x'] + $valid,
            'unavailable with formats' => ['input_formats' => [ImageFormat::PNG]] + $unavailable,
            'format not a string' => ['input_formats' => [1]] + $valid,
            'svg' => ['output_formats' => [ImageFormat::SVG]] + $valid,
            'duplicate' => ['input_formats' => [ImageFormat::PNG, ImageFormat::PNG]] + $valid,
            'animated output not an output' => ['animated_output_formats' => [ImageFormat::GIF]] + $valid,
            'first frame of a still format' => ['first_frame_formats' => [ImageFormat::JPEG]] + $valid,
            'lossless jpeg' => ['lossless_formats' => [ImageFormat::JPEG]] + $valid,
            'unknown fit' => ['fits' => ['stretch']] + $valid,
            'animated fit not a fit' => ['fits' => [ConversionRequest::FIT_FILL], 'animated_fits' => [ConversionRequest::FIT_COVER]] + $valid,
            'oriented format not an input' => ['oriented_formats' => [ImageFormat::BMP]] + $valid,
            'flag not a boolean' => ['icc_profile' => 1] + $valid,
            'note with a newline' => ['notes' => ["two\nlines"]] + $valid,
            'bad backend name' => ['backend' => 'Vips!'] + $valid,
        ];
        unset($valid['notes']);
        $cases['missing key'] = $valid + ['other' => []];

        $data = [];
        foreach ($cases as $name => $array) {
            $data[$name] = [$array];
        }

        return $data;
    }

    /**
     * @dataProvider invalidArrays
     *
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalidArrays')]
    public function testInvalidArraysAreRejected(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        BackendCapabilities::fromArray($data);
    }

    /**
     * @return array<string, array{0: string|null, 1: callable(): BackendJob}>
     */
    public static function jobs(): array
    {
        return [
            'still conversion' => [null, static function () {
                return self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP);
            }],
            'unknown input' => ['it does not decode bmp', static function () {
                return self::job(self::info(ImageFormat::BMP), ImageFormat::WEBP);
            }],
            'variant decoded' => [null, static function () {
                return self::job(self::info(ImageFormat::JPEG)->withVariants([ImageInfo::VARIANT_JPEG_CMYK]), ImageFormat::WEBP);
            }],
            'unknown output' => ['it does not encode jpeg', static function () {
                return self::job(self::info(ImageFormat::PNG), ImageFormat::JPEG);
            }],
            'first frame' => [null, static function () {
                return self::job(self::info(ImageFormat::GIF, 16, 12, 3), ImageFormat::PNG)->withAnimation(false);
            }],
            'first frame not supported' => ['it does not decode the first frame of an animated png alone', static function () {
                return self::job(self::info(ImageFormat::PNG, 16, 12, 3), ImageFormat::WEBP)->withAnimation(false);
            }],
            'animation' => [null, static function () {
                return self::job(self::info(ImageFormat::GIF, 16, 12, 3), ImageFormat::WEBP);
            }],
            'animated input not supported' => ['it does not decode every frame of an animated webp', static function () {
                return self::job(self::info(ImageFormat::WEBP, 16, 12, 3), ImageFormat::WEBP);
            }],
            'animated output not supported' => ['it does not encode an animated png', static function () {
                return self::job(self::info(ImageFormat::GIF, 16, 12, 3), ImageFormat::PNG);
            }],
            'resize' => [null, static function () {
                return self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP, 8, 8)->withFit(ConversionRequest::FIT_COVER);
            }],
            'animated resize' => [null, static function () {
                return self::job(self::info(ImageFormat::GIF, 16, 12, 3), ImageFormat::WEBP, 8, 6);
            }],
            'animated cover' => ['it does not resize animations with fit cover', static function () {
                return self::job(self::info(ImageFormat::GIF, 16, 12, 3), ImageFormat::WEBP, 8, 8)->withFit(ConversionRequest::FIT_COVER);
            }],
            'lossless webp' => [null, static function () {
                return self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP)->withLossless(true);
            }],
            'lossless avif' => ['it does not encode a lossless avif', static function () {
                return self::job(self::info(ImageFormat::PNG), ImageFormat::AVIF)->withLossless(true);
            }],
            'orientation applied' => [null, static function () {
                return self::job(self::info(ImageFormat::JPEG)->withOrientation(6), ImageFormat::WEBP, 12, 16)->withOrientation(6);
            }],
            'orientation not applied' => ['it does not apply the orientation of png', static function () {
                return self::job(self::info(ImageFormat::PNG)->withOrientation(6), ImageFormat::WEBP, 12, 16)->withOrientation(6);
            }],
            'orientation ignored' => [null, static function () {
                return self::job(self::info(ImageFormat::PNG)->withOrientation(6), ImageFormat::WEBP);
            }],
        ];
    }

    /**
     * @dataProvider jobs
     *
     * @param callable(): BackendJob $job
     */
    #[DataProvider('jobs')]
    public function testExplainUnsupported(?string $expected, callable $job): void
    {
        self::assertSame($expected, self::full()->explainUnsupported($job()));
    }

    public function testAnInputOfAVariantNotProbedIsNotDecoded(): void
    {
        $capabilities = self::capabilities('gd', [ImageFormat::JPEG, ImageFormat::BMP], [ImageFormat::PNG])
            ->withInputVariants([ImageInfo::VARIANT_BMP_64_BIT]);
        $cmyk = self::job(self::info(ImageFormat::JPEG)->withVariants([ImageInfo::VARIANT_JPEG_CMYK]), ImageFormat::PNG);
        $bitFields = self::info(ImageFormat::BMP)->withVariants([ImageInfo::VARIANT_BMP_BIT_FIELDS, ImageInfo::VARIANT_BMP_V3_HEADER]);

        self::assertSame('it does not decode the jpeg_cmyk variant of jpeg', $capabilities->explainUndecodable($cmyk));
        self::assertSame('it does not decode the jpeg_cmyk variant of jpeg', $capabilities->explainUnsupported($cmyk));
        self::assertSame(
            'it does not decode the bmp_bit_fields and bmp_v3_header variant of bmp',
            $capabilities->explainUnsupported(self::job($bitFields, ImageFormat::PNG))
        );
        self::assertNull($capabilities->explainUnsupported(self::job(self::info(ImageFormat::BMP)->withVariants([ImageInfo::VARIANT_BMP_64_BIT]), ImageFormat::PNG)));
        self::assertNull($capabilities->explainUndecodable(self::job(self::info(ImageFormat::JPEG), ImageFormat::GIF)), 'the output is not checked');
        self::assertSame('it does not encode gif', $capabilities->explainUnsupported(self::job(self::info(ImageFormat::JPEG), ImageFormat::GIF)));
    }

    public function testABackendWithoutFitsDoesNotResize(): void
    {
        $capabilities = new BackendCapabilities('avifenc', [ImageFormat::PNG], [ImageFormat::AVIF]);
        $job = self::job(self::info(ImageFormat::PNG), ImageFormat::AVIF, 8, 6);

        self::assertSame('it does not resize', $capabilities->explainUnsupported($job));
        self::assertNull($capabilities->explainUnsupported(self::job(self::info(ImageFormat::PNG), ImageFormat::AVIF)));
    }

    public function testMetadataGaps(): void
    {
        $input = self::info(ImageFormat::JPEG)->withMetadata(true, true, false);
        $job = self::job($input, ImageFormat::WEBP);
        $plain = new BackendCapabilities('gd', [ImageFormat::JPEG], [ImageFormat::WEBP]);

        self::assertSame([], self::full()->getMetadataGaps($job));
        self::assertSame(['the ICC profile'], $plain->getMetadataGaps($job));
        self::assertSame(['the metadata'], self::full()->getMetadataGaps($job->withStripMetadata(false)));
        self::assertSame([], $plain->getMetadataGaps($job->withKeepIccProfile(false)));
    }
}
