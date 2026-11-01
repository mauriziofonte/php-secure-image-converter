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
     * Every measured job: backend, input format and variant, content, output format, effort, lossless, bits per
     * channel, input and output size, frames, and the largest and smallest peak in MiB.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: bool, 7: int, 8: int, 9: int, 10: int, 11: int, 12: int, 13: int, 14: int}>
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
                self::assertCount(15, $header);

                continue;
            }
            self::assertCount(15, $fields, $line);
            list($backend, $input, $variant, $content, $output) = $fields;
            $numbers = array_map('intval', array_slice($fields, 5));
            $label = sprintf(
                '%s %s%s %s to %s effort %d%s %d-bit %dx%d',
                $backend,
                $input,
                $variant !== '' ? ' (' . $variant . ')' : '',
                $content,
                $output,
                $numbers[0],
                $numbers[1] === 1 ? ' lossless' : '',
                $numbers[2],
                $numbers[3],
                $numbers[4]
            );
            if ($numbers[5] !== $numbers[3] || $numbers[6] !== $numbers[4]) {
                $label .= sprintf(' to %dx%d', $numbers[5], $numbers[6]);
            }
            if ($numbers[7] > 1) {
                $label .= sprintf(' x%d', $numbers[7]);
            }
            self::assertArrayNotHasKey($label, $rows, 'one row per job');
            self::assertGreaterThanOrEqual($numbers[9], $numbers[8], $line);
            $rows[$label] = [$backend, $input, $variant, $content, $output, $numbers[0], $numbers[1] === 1, $numbers[2], $numbers[3], $numbers[4], $numbers[5], $numbers[6], $numbers[7], $numbers[8], $numbers[9]];
        }
        self::assertGreaterThan(200, count($rows));

        return $rows;
    }

    /**
     * The margin rule of AbstractBackend::ESTIMATE_MARGIN_PERCENT and ESTIMATE_MARGIN_BYTES, for every row.
     *
     * @dataProvider peaks
     */
    #[DataProvider('peaks')]
    public function testTheEstimateIsAboveTheMeasuredPeakWithTheMargin(
        string $backend,
        string $input,
        string $variant,
        string $content,
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
        $estimate = $this->estimateOf(\func_get_args());
        $required = $largest * self::MIB * (100 + AbstractBackend::ESTIMATE_MARGIN_PERCENT) / 100 + AbstractBackend::ESTIMATE_MARGIN_BYTES;

        self::assertGreaterThanOrEqual($required, $estimate, sprintf(
            '%d MiB is not %d %% and %d MiB above the largest peak of %d MiB (%s content)',
            intdiv($estimate, self::MIB),
            AbstractBackend::ESTIMATE_MARGIN_PERCENT,
            intdiv(AbstractBackend::ESTIMATE_MARGIN_BYTES, self::MIB),
            $largest,
            $content
        ));
    }

    /**
     * The rows that differ only in size (one content, input, output, effort and bit depth, not resized): between the
     * two largest sizes of each, the estimate grows by at least the growth of the peak plus
     * AbstractBackend::ESTIMATE_MARGIN_PERCENT %, so that it grows at least as fast as the costliest content measured
     * beyond the largest size measured.
     */
    public function testTheEstimateGrowsAtLeastAsFastAsThePeakOfEveryContent(): void
    {
        $series = [];
        foreach (self::peaks() as $row) {
            if ($row[8] !== $row[10] || $row[9] !== $row[11]) {
                continue;
            }
            $key = implode(' ', [$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6] ? 'lossless' : 'lossy', $row[7], $row[12]]);
            $series[$key][$row[8] * $row[9]] = $row;
        }
        $failures = [];
        $checked = 0;
        foreach ($series as $key => $rows) {
            if (\count($rows) < 2) {
                continue;
            }
            krsort($rows);
            list($large, $small) = array_values($rows);
            $growth = $this->estimateOf($large) - $this->estimateOf($small);
            $needed = ($large[13] - $small[13]) * self::MIB * (100 + AbstractBackend::ESTIMATE_MARGIN_PERCENT) / 100;
            ++$checked;
            if ($growth < $needed) {
                $failures[] = sprintf('%s, %dx%d to %dx%d: %d MiB, not %d', $key, $small[8], $small[9], $large[8], $large[9], intdiv($growth, self::MIB), (int) ($needed / self::MIB));
            }
        }

        self::assertGreaterThan(100, $checked);
        self::assertSame([], $failures);
    }

    public function testTheEstimateFollowsTheSettingsThatCostMemory(): void
    {
        $png = self::png(2000, 2000, 8);
        $deep = self::png(2000, 2000, 16);
        $webp = new ImageInfo(ImageFormat::WEBP, 2000, 2000, 1, 1000);
        $gif = new ImageInfo(ImageFormat::GIF, 2000, 2000, 1, 1000);
        $vips = self::backend(VipsBackend::NAME);
        $cwebp = self::backend(CwebpBackend::NAME);
        $imagick = self::backend(ImagickBackend::NAME);
        $avifenc = self::backend(AvifencBackend::NAME);
        $gd = self::backend(GdBackend::NAME);
        $gif2webp = self::backend(Gif2webpBackend::NAME);

        foreach ([[$vips, $png], [$cwebp, $png], [$imagick, $png], [$gif2webp, $gif]] as list($backend, $input)) {
            $job = $this->job($input, ImageFormat::WEBP);
            self::assertGreaterThan(
                $backend->estimateMemoryBytes($job->withEffort(8)),
                $backend->estimateMemoryBytes($job->withEffort(9)),
                $backend->getName() . ': libwebp method 6'
            );
            self::assertSame($backend->estimateMemoryBytes($job->withEffort(0)), $backend->estimateMemoryBytes($job->withEffort(8)));
        }
        foreach ([$cwebp, $gif2webp] as $backend) {
            $job = $this->job($backend === $cwebp ? $png : $gif, ImageFormat::WEBP)->withLossless(true);
            self::assertGreaterThan(
                $backend->estimateMemoryBytes($job->withEffort(7)),
                $backend->estimateMemoryBytes($job->withEffort(8)),
                $backend->getName() . ': the lossless presets of methods 5 and 6'
            );
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
        self::assertGreaterThan($avifenc->estimateMemoryBytes($job->withEffort(4)), $avifenc->estimateMemoryBytes($job->withEffort(4)->withLossless(true)));
        self::assertGreaterThan($avifenc->estimateMemoryBytes($job->withLossless(true)->withEffort(4)), $avifenc->estimateMemoryBytes($job->withLossless(true)->withEffort(5)));

        self::assertGreaterThan($imagick->estimateMemoryBytes($job), $imagick->estimateMemoryBytes($this->job($deep, ImageFormat::AVIF)), 'more than 8 bits');
        self::assertSame($vips->estimateMemoryBytes($job), $vips->estimateMemoryBytes($this->job($deep, ImageFormat::AVIF)), 'the 16-bit rows need no more');
        self::assertGreaterThan(
            $vips->estimateMemoryBytes($this->job($png, ImageFormat::WEBP)),
            $vips->estimateMemoryBytes($this->job($webp, ImageFormat::WEBP)),
            'the WEBP decoder'
        );
        foreach ([$cwebp, $imagick, $gd] as $backend) {
            self::assertGreaterThan(
                $backend->estimateMemoryBytes($this->job($png, ImageFormat::WEBP)),
                $backend->estimateMemoryBytes($this->job($png, ImageFormat::WEBP, 2000, 1999)),
                $backend->getName() . ': the resized copy'
            );
        }

        $animation = new ImageInfo(ImageFormat::GIF, 100, 100, 50, 1000);
        $job = $this->job($animation, ImageFormat::WEBP);
        self::assertSame(
            $vips->estimateMemoryBytes($job->withAnimation(false)) + 49 * 10000 * 4 + 50 * 10000 * 4,
            $vips->estimateMemoryBytes($job),
            'every frame is decoded, and every encoded frame kept'
        );
        self::assertSame(
            $gif2webp->estimateMemoryBytes($job->withAnimation(false)) + 50 * 10000 * 2,
            $gif2webp->estimateMemoryBytes($job),
            'one frame decoded at a time, every encoded frame kept'
        );
        self::assertLessThan(64 * self::MIB, $gif2webp->estimateMemoryBytes($this->job(new ImageInfo(ImageFormat::GIF, 16, 12, 300, 1000), ImageFormat::WEBP)), 'little more for 300 frames');
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
            // output, lossless, effort, bits, megapixels in vips, cwebp, gif2webp (from a GIF), GD, Imagick and
            // avifenc (0: not encoded)
            [ImageFormat::JPEG, false, 9, 8, [88, 0, 0, 126, 55, 0]],
            [ImageFormat::JPEG, false, 9, 16, [88, 0, 0, 126, 49, 0]],
            [ImageFormat::GIF, false, 9, 8, [25, 0, 0, 126, 51, 0]],
            [ImageFormat::WEBP, false, 8, 8, [23, 47, 27, 38, 33, 0]],
            [ImageFormat::WEBP, false, 9, 8, [16, 29, 21, 38, 23, 0]],
            [ImageFormat::WEBP, true, 7, 8, [23, 46, 30, 35, 32, 0]],
            [ImageFormat::WEBP, true, 9, 8, [23, 24, 24, 35, 32, 0]],
            [ImageFormat::AVIF, false, 2, 8, [12, 0, 0, 0, 13, 16]],
            [ImageFormat::AVIF, false, 2, 16, [12, 0, 0, 0, 11, 16]],
            [ImageFormat::AVIF, false, 4, 8, [9, 0, 0, 0, 10, 16]],
            [ImageFormat::AVIF, false, 4, 16, [9, 0, 0, 0, 9, 16]],
            [ImageFormat::AVIF, false, 9, 8, [9, 0, 0, 0, 10, 13]],
            [ImageFormat::AVIF, false, 9, 16, [9, 0, 0, 0, 9, 13]],
            [ImageFormat::AVIF, true, 4, 8, [9, 0, 0, 0, 0, 14]],
            [ImageFormat::AVIF, true, 9, 8, [9, 0, 0, 0, 0, 12]],
        ];
        $backends = [VipsBackend::NAME, CwebpBackend::NAME, Gif2webpBackend::NAME, GdBackend::NAME, ImagickBackend::NAME, AvifencBackend::NAME];
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
            $input = $backend === Gif2webpBackend::NAME ? new ImageInfo(ImageFormat::GIF, $side, $side, 1, 1000) : self::png($side, $side, $bits);
            $job = $this->job($input, $output)->withEffort($effort)->withLossless($lossless);

            return self::backend($backend)->estimateMemoryBytes($job) <= BackendOptions::DEFAULT_MEMORY_CEILING_BYTES;
        };

        self::assertTrue($fits($megapixels), sprintf('%d megapixels fit', $megapixels));
        self::assertFalse($fits($megapixels + 1), sprintf('%d megapixels do not fit', $megapixels + 1));
    }

    /**
     * The estimate of the job of a row of peaks().
     *
     * @param array<int, mixed> $row
     */
    private function estimateOf(array $row): int
    {
        list($backend, $input, $variant, , $output, $effort, $lossless, $bits, $width, $height, $outputWidth, $outputHeight, $frames) = $row;
        self::assertIsString($backend);
        self::assertIsString($input);
        self::assertIsString($variant);
        self::assertIsString($output);
        self::assertIsInt($effort);
        self::assertIsBool($lossless);
        self::assertIsInt($bits);
        self::assertIsInt($width);
        self::assertIsInt($height);
        self::assertIsInt($outputWidth);
        self::assertIsInt($outputHeight);
        self::assertIsInt($frames);
        $info = (new ImageInfo($input, $width, $height, $frames, 1000))->withBitDepth($bits)->withVariants($variant !== '' ? [$variant] : []);
        $job = $this->job($info, $output, $outputWidth, $outputHeight)->withEffort($effort)->withLossless($lossless);

        return self::backend($backend)->estimateMemoryBytes($job);
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
