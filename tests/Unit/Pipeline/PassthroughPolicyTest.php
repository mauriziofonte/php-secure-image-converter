<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Pipeline;

use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Pipeline\PassthroughPolicy;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The one rule that lets the input bytes be returned unchanged.
 *
 * @covers \Mfonte\SecureImageConverter\Pipeline\PassthroughPolicy
 */
#[CoversClass(PassthroughPolicy::class)]
final class PassthroughPolicyTest extends TestCase
{
    /**
     * @return array<string, array{ImageInfo, ConversionRequest}>
     */
    public static function acceptedInputs(): array
    {
        $webp = new ConversionRequest(ImageFormat::WEBP);
        $avif = new ConversionRequest(ImageFormat::AVIF);

        return [
            'jpeg for webp' => [self::info(ImageFormat::JPEG), $webp],
            'png for webp' => [self::info(ImageFormat::PNG), $webp],
            'gif for webp' => [self::info(ImageFormat::GIF), $webp],
            'webp for webp' => [self::info(ImageFormat::WEBP), $webp],
            'webp for avif' => [self::info(ImageFormat::WEBP), $avif],
            'avif for avif' => [self::info(ImageFormat::AVIF), $avif],
            'bmp for original' => [self::info(ImageFormat::BMP), new ConversionRequest(ConversionRequest::FORMAT_ORIGINAL)],
            'metadata kept on request' => [self::info(ImageFormat::JPEG)->withMetadata(true, true, true), $webp->withStripMetadata(false)],
            'icc profile kept on request' => [self::info(ImageFormat::PNG)->withMetadata(true, false, false), $webp],
            'animation kept' => [new ImageInfo(ImageFormat::GIF, 40, 30, 3, 900), $webp],
        ];
    }

    /**
     * @dataProvider acceptedInputs
     */
    #[DataProvider('acceptedInputs')]
    public function testAnInputThatSatisfiesTheRequestMayBeReturned(ImageInfo $input, ConversionRequest $request): void
    {
        $policy = new PassthroughPolicy();

        self::assertNull($policy->refusal($input, $request, self::job($input, $request)));
        self::assertTrue($policy->allows($input, $request, self::job($input, $request)));
    }

    /**
     * @return array<string, array{ImageInfo, ConversionRequest, string}>
     */
    public static function refusedInputs(): array
    {
        $webp = new ConversionRequest(ImageFormat::WEBP);

        return [
            'trailing data' => [self::info(ImageFormat::PNG)->withTrailingData(true), $webp->withStripMetadata(false), 'it holds data that decoders skip'],
            'exif to strip' => [self::info(ImageFormat::JPEG)->withMetadata(false, true, false), $webp, 'it carries metadata to strip'],
            'comments to strip' => [self::info(ImageFormat::GIF)->withMetadata(false, false, true), $webp, 'it carries metadata to strip'],
            'icc profile to strip' => [self::info(ImageFormat::PNG)->withMetadata(true, false, false), $webp->withKeepIccProfile(false), 'it carries an ICC profile to strip'],
            'oriented' => [self::info(ImageFormat::JPEG)->withOrientation(3), $webp, 'it is not stored upright'],
            'resized' => [self::info(ImageFormat::PNG), $webp->withWidth(20), 'it must be resized'],
            'first frame' => [new ImageInfo(ImageFormat::GIF, 40, 30, 3, 900), $webp->withAnimationPolicy(ConversionRequest::ANIMATION_FIRST_FRAME), 'its animation must be reduced to the first frame'],
            'bmp for webp' => [self::info(ImageFormat::BMP), $webp, 'bmp is not acceptable for a webp request'],
            'tiff for avif' => [self::info(ImageFormat::TIFF), new ConversionRequest(ImageFormat::AVIF), 'tiff is not acceptable for a avif request'],
            'avif for webp' => [self::info(ImageFormat::AVIF), $webp, 'avif is not acceptable for a webp request'],
        ];
    }

    /**
     * @dataProvider refusedInputs
     */
    #[DataProvider('refusedInputs')]
    public function testAnInputThatDoesNotSatisfyTheRequestIsRefusedWithItsReason(ImageInfo $input, ConversionRequest $request, string $reason): void
    {
        $policy = new PassthroughPolicy();

        self::assertSame($reason, $policy->refusal($input, $request, self::job($input, $request)));
        self::assertFalse($policy->allows($input, $request, self::job($input, $request)));
    }

    private static function info(string $format): ImageInfo
    {
        return new ImageInfo($format, 40, 30, 1, 500);
    }

    private static function job(ImageInfo $input, ConversionRequest $request): BackendJob
    {
        return BackendJob::fromRequest('/srv/in', $input, '/srv/out', $request);
    }
}
