<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Backend\BackendJob
 */
#[CoversClass(BackendJob::class)]
final class BackendJobTest extends TestCase
{
    use BackendFixtures;

    public function testANewJobHasTheDefaultsOfConversionRequest(): void
    {
        $job = self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP);

        self::assertSame('/tmp/sic-test/in.png', $job->getInputPath());
        self::assertSame(ImageFormat::PNG, $job->getInputFormat());
        self::assertSame('/tmp/sic-test/out.webp', $job->getOutputPath());
        self::assertSame(ImageFormat::WEBP, $job->getOutputFormat());
        self::assertSame([16, 12], [$job->getWidth(), $job->getHeight()]);
        self::assertFalse($job->isResized());
        self::assertSame(ConversionRequest::FIT_CONTAIN, $job->getFit());
        self::assertNull($job->getRequestedQuality());
        self::assertSame(ConversionRequest::defaultQualityFor(ImageFormat::WEBP), $job->getQuality());
        self::assertSame(ConversionRequest::DEFAULT_EFFORT, $job->getEffort());
        self::assertFalse($job->isLossless());
        self::assertTrue($job->shouldStripMetadata());
        self::assertTrue($job->shouldKeepIccProfile());
        self::assertTrue($job->keepsAnimation());
        self::assertSame(1, $job->getFrameCount());
        self::assertFalse($job->isAnimated());
    }

    public function testFromRequestResolvesFormatSizeFitAndSettings(): void
    {
        $request = (new ConversionRequest(ConversionRequest::FORMAT_ORIGINAL))
            ->withWidth(8)
            ->withHeight(8)
            ->withFit(ConversionRequest::FIT_COVER)
            ->withQuality(55)
            ->withEffort(7)
            ->withLossless(true)
            ->withStripMetadata(false)
            ->withKeepIccProfile(false)
            ->withAnimationPolicy(ConversionRequest::ANIMATION_FIRST_FRAME);

        $job = BackendJob::fromRequest('/srv/in.gif', self::info(ImageFormat::GIF, 16, 12, 3), '/srv/out.gif', $request);

        self::assertSame(ImageFormat::GIF, $job->getOutputFormat());
        self::assertSame([8, 8], [$job->getWidth(), $job->getHeight()]);
        self::assertSame(ConversionRequest::FIT_COVER, $job->getFit());
        self::assertSame(55, $job->getQuality());
        self::assertSame(7, $job->getEffort());
        self::assertTrue($job->isLossless());
        self::assertFalse($job->needsLosslessMode(), 'GIF has no lossless mode to select');
        self::assertFalse($job->shouldStripMetadata());
        self::assertFalse($job->shouldKeepIccProfile());
        self::assertFalse($job->keepsAnimation());
        self::assertSame(1, $job->getFrameCount());
    }

    public function testFromRequestUsesContainWhenOnlyOneDimensionIsGiven(): void
    {
        $request = (new ConversionRequest(ImageFormat::WEBP))->withWidth(8)->withFit(ConversionRequest::FIT_FILL);

        $job = BackendJob::fromRequest('/srv/in.png', self::info(ImageFormat::PNG, 16, 12), '/srv/out.webp', $request);

        self::assertSame([8, 6], [$job->getWidth(), $job->getHeight()]);
        self::assertSame(ConversionRequest::FIT_CONTAIN, $job->getFit());
        self::assertTrue($job->isResized());
    }

    public function testFromRequestPreservesAnimationWhenAsked(): void
    {
        $job = BackendJob::fromRequest(
            '/srv/in.gif',
            self::info(ImageFormat::GIF, 16, 12, 3),
            '/srv/out.webp',
            new ConversionRequest(ImageFormat::WEBP)
        );

        self::assertTrue($job->keepsAnimation());
        self::assertSame(3, $job->getFrameCount());
        self::assertTrue($job->isAnimated());
        self::assertSame(16 * 12 * 3, $job->getInputPixels());
        self::assertSame(16 * 12 * 3, $job->getOutputPixels());
        self::assertSame(16 * 12, $job->withAnimation(false)->getInputPixels());
    }

    public function testFromRequestRefusesAnAnimationUnderTheRejectPolicy(): void
    {
        $request = (new ConversionRequest(ImageFormat::WEBP))->withAnimationPolicy(ConversionRequest::ANIMATION_REJECT);

        try {
            BackendJob::fromRequest('/srv/in.gif', self::info(ImageFormat::GIF, 16, 12, 2), '/srv/out.webp', $request);
            self::fail('An animation must be rejected.');
        } catch (InvalidImageException $exception) {
            self::assertSame(ErrorCode::ANIMATION_REJECTED, $exception->getErrorCode());
        }
        $still = BackendJob::fromRequest('/srv/in.gif', self::info(ImageFormat::GIF), '/srv/out.webp', $request);
        self::assertSame(1, $still->getFrameCount());
    }

    public function testTheQualityFollowsAFallbackFormatUnlessRequested(): void
    {
        $job = self::job(self::info(ImageFormat::PNG), ImageFormat::AVIF);

        self::assertSame(ConversionRequest::defaultQualityFor(ImageFormat::AVIF), $job->getQuality());
        self::assertSame(ConversionRequest::defaultQualityFor(ImageFormat::WEBP), $job->withOutputFormat(ImageFormat::WEBP)->getQuality());
        self::assertSame(70, $job->withQuality(70)->withOutputFormat(ImageFormat::WEBP)->getQuality());
    }

    public function testMetadataNeedsDependOnTheInput(): void
    {
        $plain = self::job(self::info(ImageFormat::JPEG), ImageFormat::WEBP);
        $rich = self::job(self::info(ImageFormat::JPEG)->withMetadata(true, true, false), ImageFormat::WEBP);

        self::assertFalse($plain->needsIccProfile());
        self::assertFalse($plain->withStripMetadata(false)->needsMetadata());
        self::assertTrue($rich->needsIccProfile());
        self::assertFalse($rich->withKeepIccProfile(false)->needsIccProfile());
        self::assertFalse($rich->needsMetadata());
        self::assertTrue($rich->withStripMetadata(false)->needsMetadata());
        self::assertFalse($rich->withStripMetadata(false)->needsIccProfile(), 'kept as part of all metadata');
    }

    public function testLosslessModeIsSelectedForWebpAndAvifOnly(): void
    {
        $job = self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP)->withLossless(true);

        self::assertTrue($job->needsLosslessMode());
        self::assertTrue($job->withOutputFormat(ImageFormat::AVIF)->needsLosslessMode());
        self::assertFalse($job->withOutputFormat(ImageFormat::JPEG)->needsLosslessMode());
        self::assertFalse($job->withLossless(false)->needsLosslessMode());
    }

    public function testWithersReturnCopies(): void
    {
        $job = self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP);
        $changed = $job->withFit(ConversionRequest::FIT_FILL)->withEffort(9)->withOutputFormat(ImageFormat::PNG);

        self::assertNotSame($job, $changed);
        self::assertSame(ConversionRequest::FIT_CONTAIN, $job->getFit());
        self::assertSame(ImageFormat::WEBP, $job->getOutputFormat());
        self::assertSame(ConversionRequest::FIT_FILL, $changed->getFit());
        self::assertSame(9, $changed->getEffort());
    }

    public function testPixelCountsSaturate(): void
    {
        $huge = new BackendJob('/a', self::info(ImageFormat::GIF, 2000000000, 2000000000, 4), '/b', ImageFormat::WEBP, 16, 12);

        self::assertSame(PHP_INT_MAX, $huge->getInputPixels());
        self::assertSame(16 * 12 * 4, $huge->getOutputPixels());
    }

    /**
     * @return array<string, array{0: callable(): mixed}>
     */
    public static function invalidJobs(): array
    {
        $info = new ImageInfo(ImageFormat::PNG, 16, 12, 1, 100);

        return [
            'relative input' => [static function () use ($info) {
                return new BackendJob('in.png', $info, '/out.webp', ImageFormat::WEBP, 1, 1);
            }],
            'dot segment' => [static function () use ($info) {
                return new BackendJob('/a/../in.png', $info, '/out.webp', ImageFormat::WEBP, 1, 1);
            }],
            'same paths' => [static function () use ($info) {
                return new BackendJob('/a.png', $info, '/a.png', ImageFormat::WEBP, 1, 1);
            }],
            'zero width' => [static function () use ($info) {
                return new BackendJob('/a.png', $info, '/b.webp', ImageFormat::WEBP, 0, 1);
            }],
            'height above the maximum' => [static function () use ($info) {
                return new BackendJob('/a.png', $info, '/b.webp', ImageFormat::WEBP, 1, Capabilities::OUTPUT_DIMENSION_MAX + 1);
            }],
            'svg output' => [static function () use ($info) {
                return new BackendJob('/a.png', $info, '/b.svg', ImageFormat::SVG, 1, 1);
            }],
            'unknown fit' => [static function () use ($info) {
                return (new BackendJob('/a.png', $info, '/b.webp', ImageFormat::WEBP, 1, 1))->withFit('stretch');
            }],
            'quality 0' => [static function () use ($info) {
                return (new BackendJob('/a.png', $info, '/b.webp', ImageFormat::WEBP, 1, 1))->withQuality(0);
            }],
            'effort 10' => [static function () use ($info) {
                return (new BackendJob('/a.png', $info, '/b.webp', ImageFormat::WEBP, 1, 1))->withEffort(10);
            }],
        ];
    }

    /**
     * @dataProvider invalidJobs
     *
     * @param callable(): mixed $build
     */
    #[DataProvider('invalidJobs')]
    public function testInvalidValuesAreRejected(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);

        $build();
    }
}
