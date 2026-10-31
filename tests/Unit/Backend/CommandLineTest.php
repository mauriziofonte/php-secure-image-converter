<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\AvifencBackend;
use Mfonte\SecureImageConverter\Backend\CwebpBackend;
use Mfonte\SecureImageConverter\Backend\Gif2webpBackend;
use Mfonte\SecureImageConverter\Backend\VipsBackend;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Tests\Unit\Backend\Fake\FakeProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The command line of each backend for representative jobs, built without running anything.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\AvifencBackend
 * @covers \Mfonte\SecureImageConverter\Backend\CwebpBackend
 * @covers \Mfonte\SecureImageConverter\Backend\Gif2webpBackend
 * @covers \Mfonte\SecureImageConverter\Backend\VipsBackend
 */
#[CoversClass(VipsBackend::class)]
#[CoversClass(CwebpBackend::class)]
#[CoversClass(Gif2webpBackend::class)]
#[CoversClass(AvifencBackend::class)]
final class CommandLineTest extends TestCase
{
    use BackendFixtures;
    use FakePrograms;

    private const VIPS_FACTS = ['keep' => '1', 'fail_on' => '1', 'loaders' => '', 'savers' => ''];

    protected function tear_down(): void
    {
        $this->removeFakePrograms();
        parent::tear_down();
    }

    private function program(string $name): string
    {
        return $this->fakePrograms() . '/' . $name;
    }

    private function runner(): FakeProcessRunner
    {
        return new FakeProcessRunner(null, $this->programOptions());
    }

    public function testVipsNamesTheLoaderWithoutResize(): void
    {
        $vips = new VipsBackend($this->runner());
        $webp = (string) ConversionRequest::defaultQualityFor(ImageFormat::WEBP);

        self::assertSame(
            [$this->program('vips'), 'pngload', 'input.png', 'output.webp[Q=' . $webp . ',effort=3,keep=icc]', '--fail-on', 'error'],
            $vips->commandLine(self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP), self::VIPS_FACTS)
        );
        self::assertSame(
            [$this->program('vips'), 'tiffload', 'input.tiff', 'output.avif[compression=av1,Q=50,effort=8,bitdepth=8,lossless=true,keep=none]', '--page', '0', '--n', '1', '--fail-on', 'error'],
            $vips->commandLine(
                self::job(self::info(ImageFormat::TIFF), ImageFormat::AVIF)->withQuality(50)->withEffort(9)->withLossless(true)->withKeepIccProfile(false),
                self::VIPS_FACTS
            )
        );
        self::assertSame(
            [$this->program('vips'), 'gifload', 'input.gif', 'output.gif', '--n', '-1'],
            $vips->commandLine(
                self::job(self::info(ImageFormat::GIF, 16, 12, 3), ImageFormat::GIF)->withStripMetadata(false),
                ['keep' => '0', 'fail_on' => '0']
            )
        );
    }

    public function testVipsResizesWithThumbnail(): void
    {
        $vips = new VipsBackend($this->runner());
        $animation = self::job(self::info(ImageFormat::GIF, 16, 12, 3), ImageFormat::WEBP, 8, 6)->withQuality(70);

        self::assertSame(
            [$this->program('vips'), 'thumbnail', 'input.gif[n=-1,fail-on=error]', 'output.webp[Q=70,effort=3,keep=icc]', '8', '--height', '6', '--size', 'force', '--no-rotate'],
            $vips->commandLine($animation, self::VIPS_FACTS)
        );
        self::assertSame(
            [$this->program('vips'), 'thumbnail', 'input.jpg', 'output.jpg[Q=90,optimize-coding=true,strip=true]', '6', '--height', '6', '--crop', 'centre', '--no-rotate'],
            $vips->commandLine(
                self::job(self::info(ImageFormat::JPEG), ImageFormat::JPEG, 6, 6)->withFit(ConversionRequest::FIT_COVER)->withQuality(90),
                ['keep' => '0', 'fail_on' => '0']
            )
        );
        self::assertSame(
            [$this->program('vips'), 'thumbnail', 'input.png[fail-on=error]', 'output.png[compression=1,keep=icc]', '10', '--height', '4', '--size', 'force', '--no-rotate'],
            $vips->commandLine(self::job(self::info(ImageFormat::PNG), ImageFormat::PNG, 10, 4)->withFit(ConversionRequest::FIT_FILL)->withEffort(0), self::VIPS_FACTS)
        );
    }

    public function testVipsEnvironmentAndMemory(): void
    {
        $vips = new VipsBackend($this->runner());
        $small = self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP);
        $large = self::job(self::info(ImageFormat::PNG, 8000, 5000), ImageFormat::WEBP, 800, 500);

        self::assertGreaterThanOrEqual(192 * 1048576, $vips->estimateMemoryBytes($small));
        self::assertLessThan(224 * 1048576, $vips->estimateMemoryBytes($small));
        self::assertGreaterThanOrEqual(256 * 1048576, $vips->estimateMemoryBytes($small->withOutputFormat(ImageFormat::AVIF)), 'an AVIF below 256 MiB may come out empty');
        self::assertGreaterThan($vips->estimateMemoryBytes($small) + 40000000 * 8, $vips->estimateMemoryBytes($large));

        $animation = self::job(self::info(ImageFormat::GIF, 100, 100, 50), ImageFormat::WEBP);
        self::assertSame(
            $vips->estimateMemoryBytes($animation->withAnimation(false)) + 49 * 10000 * 8 + 50 * 10000 * 4,
            $vips->estimateMemoryBytes($animation),
            'every frame is decoded, and every encoded frame kept'
        );
    }

    public function testCwebpCropsForCoverAndSelectsTheLosslessPreset(): void
    {
        $cwebp = new CwebpBackend($this->runner());
        $quality = (string) ConversionRequest::defaultQualityFor(ImageFormat::WEBP);

        self::assertSame(
            [$this->program('cwebp'), '-quiet', '-q', $quality, '-m', '3', '-metadata', 'icc', '-crop', '2', '0', '12', '12', '-resize', '6', '6', 'input.png', '-o', 'output.webp'],
            $cwebp->commandLine(self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP, 6, 6)->withFit(ConversionRequest::FIT_COVER), [])
        );
        self::assertSame(
            [$this->program('cwebp'), '-quiet', '-z', '9', '-metadata', 'all', 'input.jpg', '-o', 'output.webp'],
            $cwebp->commandLine(self::job(self::info(ImageFormat::JPEG), ImageFormat::WEBP)->withLossless(true)->withEffort(9)->withStripMetadata(false), [])
        );
        self::assertSame(
            [$this->program('cwebp'), '-quiet', '-q', '75', '-m', '0', '-metadata', 'none', '-resize', '8', '6', 'input.tiff', '-o', 'output.webp'],
            $cwebp->commandLine(self::job(self::info(ImageFormat::TIFF), ImageFormat::WEBP, 8, 6)->withQuality(75)->withEffort(0)->withKeepIccProfile(false), [])
        );
    }

    public function testGif2webpKeepsEveryFrame(): void
    {
        $gif2webp = new Gif2webpBackend($this->runner());
        $animation = self::job(self::info(ImageFormat::GIF, 16, 12, 300), ImageFormat::WEBP);

        self::assertSame(
            [$this->program('gif2webp'), '-quiet', '-mixed', '-q', (string) ConversionRequest::defaultQualityFor(ImageFormat::WEBP), '-m', '3', '-metadata', 'icc', 'input.gif', '-o', 'output.webp'],
            $gif2webp->commandLine($animation, [])
        );
        self::assertSame(
            [$this->program('gif2webp'), '-quiet', '-q', '100', '-m', '6', '-metadata', 'none', 'input.gif', '-o', 'output.webp'],
            $gif2webp->commandLine($animation->withLossless(true)->withEffort(9)->withKeepIccProfile(false), [])
        );
        self::assertLessThan(64 * 1048576, $gif2webp->estimateMemoryBytes($animation), 'little more for 300 frames');
    }

    public function testAvifencMapsQualitySpeedAndMetadata(): void
    {
        $avifenc = new AvifencBackend($this->runner());
        $job = self::job(self::info(ImageFormat::PNG), ImageFormat::AVIF)->withQuality(60);

        self::assertSame(
            [$this->program('avifenc'), '-j', '1', '-s', '6', '-d', '8', '-q', '60', '-y', '420', '--ignore-exif', '--ignore-xmp', 'input.png', 'output.avif'],
            $avifenc->commandLine($job, ['qcolor' => '1'])
        );
        self::assertSame(
            [$this->program('avifenc'), '-j', '1', '-s', '10', '-d', '8', '--min', '25', '--max', '25', '-y', '420', '--ignore-exif', '--ignore-xmp', '--ignore-icc', 'input.png', 'output.avif'],
            $avifenc->commandLine($job->withEffort(0)->withKeepIccProfile(false), ['qcolor' => '0'])
        );
        self::assertSame(
            [$this->program('avifenc'), '-j', '1', '-s', '1', '-d', '8', '-l', 'input.jpg', 'output.avif'],
            $avifenc->commandLine(
                self::job(self::info(ImageFormat::JPEG), ImageFormat::AVIF)->withLossless(true)->withEffort(9)->withStripMetadata(false),
                ['qcolor' => '1']
            )
        );
    }
}
