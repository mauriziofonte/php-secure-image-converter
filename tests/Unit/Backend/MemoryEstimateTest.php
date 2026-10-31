<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Backend\AvifencBackend;
use Mfonte\SecureImageConverter\Backend\BackendFactory;
use Mfonte\SecureImageConverter\Backend\BackendInterface;
use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Backend\CwebpBackend;
use Mfonte\SecureImageConverter\Backend\GdBackend;
use Mfonte\SecureImageConverter\Backend\Gif2webpBackend;
use Mfonte\SecureImageConverter\Backend\ImagickBackend;
use Mfonte\SecureImageConverter\Backend\VipsBackend;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Tests\Unit\Backend\Fake\FakeProcessRunner;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The memory estimates against the peak address space measured for them (tests/Fixtures/backend/memory-peaks.csv),
 * and the settings they depend on: effort, lossless mode, bit depth, input format, resize and animation.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\AbstractBackend
 * @covers \Mfonte\SecureImageConverter\Backend\AvifencBackend
 * @covers \Mfonte\SecureImageConverter\Backend\CwebpBackend
 * @covers \Mfonte\SecureImageConverter\Backend\GdBackend
 * @covers \Mfonte\SecureImageConverter\Backend\Gif2webpBackend
 * @covers \Mfonte\SecureImageConverter\Backend\ImagickBackend
 * @covers \Mfonte\SecureImageConverter\Backend\VipsBackend
 */
#[CoversClass(AbstractBackend::class)]
#[CoversClass(AvifencBackend::class)]
#[CoversClass(CwebpBackend::class)]
#[CoversClass(GdBackend::class)]
#[CoversClass(Gif2webpBackend::class)]
#[CoversClass(ImagickBackend::class)]
#[CoversClass(VipsBackend::class)]
final class MemoryEstimateTest extends TestCase
{
    private const MIB = 1048576;

    /**
     * Backends whose encoder threads reserve a second malloc arena (64 MiB) in some runs only: their estimate stays
     * 80 MiB above the smallest peak too.
     */
    private const THREADED = [VipsBackend::NAME, ImagickBackend::NAME];

    /**
     * Every measured job: backend, input and output format, effort, lossless, bits per channel, input and output
     * size, frames, and the largest and smallest peak in MiB.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: bool, 5: int, 6: int, 7: int, 8: int, 9: int, 10: int, 11: int, 12: int}>
     */
    public static function peaks(): array
    {
        $lines = file(\dirname(__DIR__, 2) . '/Fixtures/backend/memory-peaks.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        $rows = [];
        $header = null;
        foreach ($lines as $line) {
            if ($line[0] === '#') {
                continue;
            }
            $fields = explode(',', $line);
            if ($header === null) {
                $header = $fields;
                self::assertCount(13, $header);

                continue;
            }
            self::assertCount(13, $fields, $line);
            list($backend, $input, $output) = $fields;
            $numbers = array_map('intval', array_slice($fields, 3));
            $label = sprintf('%s %s to %s effort %d%s %d-bit %dx%d', $backend, $input, $output, $numbers[0], $numbers[1] === 1 ? ' lossless' : '', $numbers[2], $numbers[3], $numbers[4]);
            if ($numbers[5] !== $numbers[3] || $numbers[6] !== $numbers[4]) {
                $label .= sprintf(' to %dx%d', $numbers[5], $numbers[6]);
            }
            if ($numbers[7] > 1) {
                $label .= sprintf(' x%d', $numbers[7]);
            }
            $rows[$label] = [$backend, $input, $output, $numbers[0], $numbers[1] === 1, $numbers[2], $numbers[3], $numbers[4], $numbers[5], $numbers[6], $numbers[7], $numbers[8], $numbers[9]];
        }
        self::assertGreaterThan(200, count($rows));

        return $rows;
    }

    /**
     * @dataProvider peaks
     */
    #[DataProvider('peaks')]
    public function testTheEstimateIsAboveTheMeasuredPeak(
        string $backend,
        string $input,
        string $output,
        int $effort,
        bool $lossless,
        int $bits,
        int $width,
        int $height,
        int $outputWidth,
        int $outputHeight,
        int $frames,
        int $largest,
        int $smallest
    ): void {
        $info = (new ImageInfo($input, $width, $height, $frames, 1000))->withBitDepth($bits);
        $job = $this->job($info, $output, $outputWidth, $outputHeight)->withEffort($effort)->withLossless($lossless);

        $estimate = self::backend($backend)->estimateMemoryBytes($job) / self::MIB;

        self::assertGreaterThanOrEqual(1.15 * $largest + 16, $estimate, '15 % and 16 MiB above the largest peak');
        if (in_array($backend, self::THREADED, true)) {
            self::assertGreaterThanOrEqual($smallest + 80, $estimate, 'room for a second malloc arena');
        }
    }

    public function testTheEstimateFollowsTheSettingsThatCostMemory(): void
    {
        $png = self::png(2000, 2000, 8);
        $deep = self::png(2000, 2000, 16);
        $webp = new ImageInfo(ImageFormat::WEBP, 2000, 2000, 1, 1000);
        $vips = self::backend(VipsBackend::NAME);
        $cwebp = self::backend(CwebpBackend::NAME);
        $imagick = self::backend(ImagickBackend::NAME);
        $avifenc = self::backend(AvifencBackend::NAME);

        foreach ([$vips, $cwebp, $imagick] as $backend) {
            $job = $this->job($png, ImageFormat::WEBP);
            self::assertGreaterThan(
                $backend->estimateMemoryBytes($job->withEffort(8)),
                $backend->estimateMemoryBytes($job->withEffort(9)),
                $backend->getName() . ': libwebp method 6'
            );
            self::assertSame($backend->estimateMemoryBytes($job->withEffort(0)), $backend->estimateMemoryBytes($job->withEffort(8)));
        }
        foreach ([$vips, $imagick] as $backend) {
            $job = $this->job($png, ImageFormat::AVIF);
            self::assertGreaterThan(
                $backend->estimateMemoryBytes($job->withEffort(2)),
                $backend->estimateMemoryBytes($job->withEffort(3)),
                $backend->getName() . ': AV1 above effort 2'
            );
        }
        $job = $this->job($png, ImageFormat::AVIF);
        self::assertSame($vips->estimateMemoryBytes($job->withEffort(3)), $vips->estimateMemoryBytes($job->withEffort(0)->withLossless(true)), 'lossless AV1 at any effort');
        self::assertGreaterThan($avifenc->estimateMemoryBytes($job->withEffort(4)), $avifenc->estimateMemoryBytes($job->withEffort(5)), 'speed 4 and slower');
        self::assertGreaterThan($avifenc->estimateMemoryBytes($job->withEffort(9)), $avifenc->estimateMemoryBytes($job->withLossless(true)));
        self::assertGreaterThan($avifenc->estimateMemoryBytes($job->withLossless(true)->withEffort(4)), $avifenc->estimateMemoryBytes($job->withLossless(true)->withEffort(5)));

        $job = $this->job($png, ImageFormat::WEBP)->withEffort(9);
        self::assertGreaterThan($vips->estimateMemoryBytes($job), $vips->estimateMemoryBytes($this->job($deep, ImageFormat::WEBP)->withEffort(9)), 'more than 8 bits');
        self::assertGreaterThan($vips->estimateMemoryBytes($job), $vips->estimateMemoryBytes($this->job($webp, ImageFormat::WEBP)->withEffort(9)), 'the WEBP decoder');
        self::assertGreaterThan(
            $cwebp->estimateMemoryBytes($this->job($png, ImageFormat::WEBP)),
            $cwebp->estimateMemoryBytes($this->job($png, ImageFormat::WEBP, 2000, 1999)),
            'the rescaled copy'
        );
        self::assertGreaterThan(
            $cwebp->estimateMemoryBytes($this->job($png, ImageFormat::WEBP)->withLossless(true)->withEffort(7)),
            $cwebp->estimateMemoryBytes($this->job($png, ImageFormat::WEBP)->withLossless(true)->withEffort(8)),
            'the lossless presets of methods 5 and 6'
        );

        $animation = new ImageInfo(ImageFormat::GIF, 100, 100, 50, 1000);
        $job = $this->job($animation, ImageFormat::WEBP);
        self::assertSame(
            $vips->estimateMemoryBytes($job->withAnimation(false)) + 49 * 10000 * 4 + 50 * 10000 * 4,
            $vips->estimateMemoryBytes($job),
            'every frame is decoded, and every encoded frame kept'
        );
        self::assertLessThan(64 * self::MIB, self::backend(Gif2webpBackend::NAME)->estimateMemoryBytes($this->job(new ImageInfo(ImageFormat::GIF, 16, 12, 300, 1000), ImageFormat::WEBP)), 'little more for 300 frames');
    }

    /**
     * The sizes BackendOptions documents: the largest still image, in megapixels, below the default memory ceiling.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: int, 4: int, 5: int}>
     */
    public static function documentedSizes(): array
    {
        $rows = [];
        $table = [
            // output, lossless, effort, bits, megapixels in vips, cwebp, GD, Imagick and avifenc (0: not encoded)
            [ImageFormat::JPEG, false, 9, 8, [254, 0, 204, 104, 0]],
            [ImageFormat::GIF, false, 9, 8, [29, 0, 204, 59, 0]],
            [ImageFormat::WEBP, false, 8, 8, [37, 54, 42, 36, 0]],
            [ImageFormat::WEBP, false, 9, 8, [25, 35, 42, 27, 0]],
            [ImageFormat::WEBP, false, 9, 16, [21, 35, 42, 27, 0]],
            [ImageFormat::WEBP, true, 7, 8, [37, 51, 45, 39, 0]],
            [ImageFormat::WEBP, true, 8, 8, [37, 32, 45, 39, 0]],
            [ImageFormat::AVIF, false, 2, 8, [42, 0, 0, 46, 76]],
            [ImageFormat::AVIF, false, 2, 16, [40, 0, 0, 36, 76]],
            [ImageFormat::AVIF, false, 3, 8, [30, 0, 0, 33, 76]],
            [ImageFormat::AVIF, false, 4, 16, [30, 0, 0, 27, 76]],
            [ImageFormat::AVIF, false, 5, 8, [30, 0, 0, 33, 45]],
            [ImageFormat::AVIF, true, 4, 8, [30, 0, 0, 0, 39]],
            [ImageFormat::AVIF, true, 5, 8, [30, 0, 0, 0, 28]],
        ];
        $backends = [VipsBackend::NAME, CwebpBackend::NAME, GdBackend::NAME, ImagickBackend::NAME, AvifencBackend::NAME];
        foreach ($table as list($output, $lossless, $effort, $bits, $sizes)) {
            foreach ($sizes as $index => $megapixels) {
                if ($megapixels > 0) {
                    $label = sprintf('%s %s%s effort %d %d-bit', $backends[$index], $output, $lossless ? ' lossless' : '', $effort, $bits);
                    $rows[$label] = [$backends[$index], $output, $lossless, $effort, $bits, $megapixels];
                }
            }
        }

        return $rows;
    }

    /**
     * @dataProvider documentedSizes
     */
    #[DataProvider('documentedSizes')]
    public function testTheDocumentedSizesFitBelowTheDefaultCeiling(string $backend, string $output, bool $lossless, int $effort, int $bits, int $megapixels): void
    {
        $fits = function (int $megapixels) use ($backend, $output, $lossless, $effort, $bits): bool {
            $side = (int) ceil(sqrt($megapixels * 1000000));
            $job = $this->job(self::png($side, $side, $bits), $output)->withEffort($effort)->withLossless($lossless);

            return self::backend($backend)->estimateMemoryBytes($job) <= BackendOptions::DEFAULT_MEMORY_CEILING_BYTES;
        };

        self::assertTrue($fits($megapixels), sprintf('%d megapixels fit', $megapixels));
        self::assertFalse($fits($megapixels + 1), sprintf('%d megapixels do not fit', $megapixels + 1));
    }

    private static function backend(string $name): BackendInterface
    {
        return BackendFactory::create($name, new FakeProcessRunner(), (new BackendOptions())->withCapabilityCache(false));
    }

    private static function png(int $width, int $height, int $bits): ImageInfo
    {
        return (new ImageInfo(ImageFormat::PNG, $width, $height, 1, 1000))->withBitDepth($bits);
    }

    private function job(ImageInfo $input, string $output, ?int $width = null, ?int $height = null): BackendJob
    {
        $job = new BackendJob('/tmp/sic-test/in', $input, '/tmp/sic-test/out', $output, $width !== null ? $width : $input->getWidth(), $height !== null ? $height : $input->getHeight());

        return $job->isResized() ? $job->withFit(ConversionRequest::FIT_FILL) : $job;
    }
}
