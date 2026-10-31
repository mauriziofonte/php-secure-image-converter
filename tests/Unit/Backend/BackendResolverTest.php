<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\BackendCapabilities;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Backend\BackendResolver;
use Mfonte\SecureImageConverter\Backend\BackendSelection;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Tests\Unit\Backend\Fake\FakeBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Backend\BackendResolver
 * @covers \Mfonte\SecureImageConverter\Backend\BackendSelection
 */
#[CoversClass(BackendResolver::class)]
#[CoversClass(BackendSelection::class)]
final class BackendResolverTest extends TestCase
{
    use BackendFixtures;

    /**
     * A host like the reference image: vips does everything but BMP, cwebp and avifenc stills, gif2webp animated
     * GIF, gd stills without metadata.
     *
     * @return list<FakeBackend>
     */
    private static function host(): array
    {
        $all = [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::WEBP, ImageFormat::AVIF, ImageFormat::TIFF];

        return [
            new FakeBackend(
                self::capabilities('vips', $all, $all)
                    ->withFirstFrameFormats([ImageFormat::PNG, ImageFormat::GIF, ImageFormat::WEBP])
                    ->withAnimatedInputFormats([ImageFormat::GIF, ImageFormat::WEBP])
                    ->withAnimatedOutputFormats([ImageFormat::GIF, ImageFormat::WEBP])
                    ->withAnimatedFits([ConversionRequest::FIT_CONTAIN, ConversionRequest::FIT_FILL])
                    ->withLosslessFormats([ImageFormat::WEBP, ImageFormat::AVIF])
                    ->withIccProfile(true)
                    ->withMetadata(true),
                234881024,
                18
            ),
            new FakeBackend(
                self::capabilities('cwebp', [ImageFormat::JPEG, ImageFormat::PNG], [ImageFormat::WEBP])->withIccProfile(true),
                33554432,
                16
            ),
            new FakeBackend(
                (new BackendCapabilities('gif2webp', [ImageFormat::GIF], [ImageFormat::WEBP]))
                    ->withAnimatedInputFormats([ImageFormat::GIF])
                    ->withAnimatedOutputFormats([ImageFormat::WEBP]),
                33554432,
                0
            ),
            new FakeBackend(
                self::capabilities('gd', [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::BMP], [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::BMP])
                    ->withFirstFrameFormats([ImageFormat::GIF]),
                134217728,
                24
            ),
        ];
    }

    public function testVipsIsPreferredWhenItCanPerformTheJob(): void
    {
        $selection = (new BackendResolver(self::host()))->resolve(self::job(self::info(ImageFormat::PNG), ImageFormat::AVIF));

        self::assertSame('vips', $selection->getBackend()->getName());
        self::assertSame(ImageFormat::AVIF, $selection->getJob()->getOutputFormat());
        self::assertFalse($selection->isFallback());
        self::assertSame([], $selection->getReasons());
        self::assertSame([], $selection->getWarnings());
    }

    public function testTheNextBackendIsChosenWithTheReasons(): void
    {
        $backends = self::host();
        array_shift($backends);
        $selection = (new BackendResolver($backends))->resolve(self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP, 8, 6));

        self::assertSame('cwebp', $selection->getBackend()->getName());
        self::assertFalse($selection->isFallback());
    }

    public function testAvifFallsBackToWebpThenToTheInputFormat(): void
    {
        $backends = self::host();
        array_shift($backends);
        $resolver = new BackendResolver($backends);

        $webp = $resolver->resolve(self::job(self::info(ImageFormat::JPEG), ImageFormat::AVIF));
        self::assertSame('cwebp', $webp->getBackend()->getName());
        self::assertSame(ImageFormat::WEBP, $webp->getJob()->getOutputFormat());
        self::assertSame(ConversionRequest::defaultQualityFor(ImageFormat::WEBP), $webp->getJob()->getQuality());
        self::assertTrue($webp->isFallback());
        self::assertContains('avif: cwebp: it does not encode avif', $webp->getReasons());
        self::assertContains('avif: gd: it does not encode avif', $webp->getReasons());

        $original = $resolver->resolve(self::job(self::info(ImageFormat::BMP), ImageFormat::AVIF));
        self::assertSame('gd', $original->getBackend()->getName());
        self::assertSame(ImageFormat::BMP, $original->getJob()->getOutputFormat());
        self::assertContains('webp: gd: it does not encode webp', $original->getReasons());
    }

    public function testFallbackFormats(): void
    {
        self::assertSame([ImageFormat::AVIF, ImageFormat::WEBP, ImageFormat::PNG], BackendResolver::fallbackFormats(ImageFormat::AVIF, ImageFormat::PNG));
        self::assertSame([ImageFormat::AVIF, ImageFormat::WEBP], BackendResolver::fallbackFormats(ImageFormat::AVIF, ImageFormat::WEBP));
        self::assertSame([ImageFormat::WEBP, ImageFormat::GIF], BackendResolver::fallbackFormats(ImageFormat::WEBP, ImageFormat::GIF));
        self::assertSame([ImageFormat::PNG], BackendResolver::fallbackFormats(ImageFormat::PNG, ImageFormat::PNG));
        self::assertSame([ImageFormat::WEBP], BackendResolver::fallbackFormats(ImageFormat::WEBP, ImageFormat::SVG));
    }

    public function testAnAnimationKeepsEveryFrameWhereTheFormatCanCarryIt(): void
    {
        $animation = self::info(ImageFormat::GIF, 16, 12, 10);
        $resolver = new BackendResolver(self::host());

        $webp = $resolver->resolve(self::job($animation, ImageFormat::WEBP));
        self::assertSame('vips', $webp->getBackend()->getName());
        self::assertSame(10, $webp->getJob()->getFrameCount());

        $avif = $resolver->resolve(self::job($animation, ImageFormat::AVIF));
        self::assertSame('vips', $avif->getBackend()->getName());
        self::assertSame(ImageFormat::AVIF, $avif->getJob()->getOutputFormat(), 'the format comes first');
        self::assertSame(1, $avif->getJob()->getFrameCount());
        self::assertTrue($avif->isFallback());
        self::assertContains('avif (animated): vips: it does not encode an animated avif', $avif->getReasons());
    }

    public function testTheFirstFramePolicyIsHonoured(): void
    {
        $job = self::job(self::info(ImageFormat::GIF, 16, 12, 10), ImageFormat::WEBP)->withAnimation(false);
        $backends = self::host();
        array_shift($backends);

        $selection = (new BackendResolver($backends))->resolve($job);

        self::assertSame('gd', $selection->getBackend()->getName(), 'gif2webp cannot convert the first frame alone');
        self::assertSame(ImageFormat::GIF, $selection->getJob()->getOutputFormat(), 'gd does not encode WEBP');
        self::assertSame(1, $selection->getJob()->getFrameCount());
    }

    public function testTheMemoryCeilingSendsLargeAnimationsToAFrugalBackend(): void
    {
        // 300 frames of 1.3 megapixels: about 400 megapixels, the default Limits maximum.
        $job = self::job(self::info(ImageFormat::GIF, 1333, 1000, 300), ImageFormat::WEBP);

        $selection = (new BackendResolver(self::host(), BackendOptions::DEFAULT_MEMORY_CEILING_BYTES))->resolve($job);

        self::assertSame('gif2webp', $selection->getBackend()->getName());
        self::assertSame(300, $selection->getJob()->getFrameCount());
        self::assertMatchesRegularExpression('/^webp \(animated\): vips: it needs about \d+ MiB, above the memory ceiling of 2048 MiB$/', $selection->getReasons()[0]);
    }

    public function testOnlyTheMemoryCeilingInTheWayIsAResourceLimit(): void
    {
        $job = self::job(self::info(ImageFormat::PNG, 8000, 5000), ImageFormat::WEBP);

        try {
            (new BackendResolver(self::host(), 268435456))->resolve($job);
            self::fail('No backend fits under 256 MiB.');
        } catch (ResourceLimitException $exception) {
            self::assertSame(ErrorCode::RESOURCE_LIMIT, $exception->getErrorCode());
            self::assertStringContainsString('webp: vips: it needs about', $exception->getMessage());
            self::assertStringContainsString('png: gd: it needs about', $exception->getMessage());
        }
    }

    public function testNoCapableBackendIsAConversionFailure(): void
    {
        $backends = [new FakeBackend(BackendCapabilities::unavailable('vips', 'vips is not installed'))];

        try {
            (new BackendResolver($backends))->resolve(self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP));
            self::fail('Nothing can convert.');
        } catch (ConversionFailedException $exception) {
            self::assertSame(
                'No backend can convert this image: webp: vips: it is unavailable: vips is not installed; '
                . 'png: vips: it is unavailable: vips is not installed.',
                $exception->getMessage()
            );
        }
        $this->expectException(ConversionFailedException::class);
        $this->expectExceptionMessage('no backend is configured');
        (new BackendResolver([]))->resolve(self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP));
    }

    public function testABackendKeepingTheMetadataIsPreferred(): void
    {
        $input = self::info(ImageFormat::JPEG)->withMetadata(true, true, false);
        $gd = new FakeBackend(self::capabilities('gd', [ImageFormat::JPEG], [ImageFormat::WEBP]));
        $cwebp = new FakeBackend(self::capabilities('cwebp', [ImageFormat::JPEG], [ImageFormat::WEBP])->withIccProfile(true));
        $resolver = new BackendResolver([$gd, $cwebp]);

        $selection = $resolver->resolve(self::job($input, ImageFormat::WEBP));
        self::assertSame('cwebp', $selection->getBackend()->getName());
        self::assertSame(['webp: gd: it drops the ICC profile'], $selection->getReasons());
        self::assertSame([], $selection->getWarnings());

        $kept = $resolver->resolve(self::job($input, ImageFormat::WEBP)->withStripMetadata(false));
        self::assertSame('gd', $kept->getBackend()->getName(), 'the first capable backend when all drop something');
        self::assertSame(['gd drops the metadata'], $kept->getWarnings());
    }

    public function testBackendsAreListedOnceInOrder(): void
    {
        $resolver = new BackendResolver(self::host(), 536870912);

        self::assertSame(['vips', 'cwebp', 'gif2webp', 'gd'], array_keys($resolver->getCapabilities()));
        self::assertCount(4, $resolver->getBackends());
        self::assertSame(536870912, $resolver->getMemoryCeilingBytes());

        $this->expectException(InvalidArgumentException::class);
        new BackendResolver([self::host()[0], self::host()[0]]);
    }

    public function testTheCeilingMustBeInRange(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BackendResolver([], 1024);
    }
}
