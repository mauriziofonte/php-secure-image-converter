<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Pipeline;

use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use Mfonte\SecureImageConverter\Pipeline\ProcessingBudget;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Timeouts and CPU limits derived from pixels, frames, format and effort.
 *
 * @covers \Mfonte\SecureImageConverter\Pipeline\ProcessingBudget
 */
#[CoversClass(ProcessingBudget::class)]
final class ProcessingBudgetTest extends TestCase
{
    /**
     * @return array<string, array{string, bool, int, float}>
     */
    public static function rates(): array
    {
        return [
            'png' => [ImageFormat::PNG, false, 9, 4.0],
            'gif' => [ImageFormat::GIF, false, 4, 8.0],
            'webp effort 2' => [ImageFormat::WEBP, false, 2, 6.0],
            'webp effort 4' => [ImageFormat::WEBP, false, 4, 6.0],
            'webp effort 6' => [ImageFormat::WEBP, false, 6, 15.0],
            'webp effort 9' => [ImageFormat::WEBP, false, 9, 60.0],
            'lossless webp effort 3' => [ImageFormat::WEBP, true, 3, 20.0],
            'lossless webp effort 7' => [ImageFormat::WEBP, true, 7, 160.0],
            'avif effort 0' => [ImageFormat::AVIF, false, 0, 6.0],
            'avif effort 5' => [ImageFormat::AVIF, false, 5, 60.0],
            'avif effort 7' => [ImageFormat::AVIF, false, 7, 150.0],
            'avif effort 8' => [ImageFormat::AVIF, false, 8, 300.0],
            'lossless avif effort 9' => [ImageFormat::AVIF, true, 9, 600.0],
        ];
    }

    /**
     * @dataProvider rates
     */
    #[DataProvider('rates')]
    public function testTheEstimateFollowsFormatModeAndEffort(string $format, bool $lossless, int $effort, float $rate): void
    {
        // 2000 x 1000: 2 megapixels decoded and encoded.
        $job = self::job(2000, 1000, 1, $format)->withLossless($lossless)->withEffort($effort);

        self::assertEqualsWithDelta(15.0 + 2.0 + 2.0 * $rate, (new ProcessingBudget())->estimateSeconds($job), 0.0001);
    }

    public function testEveryFrameOfAnAnimationCounts(): void
    {
        $job = self::job(1000, 1000, 10, ImageFormat::WEBP)->withAnimation(true);

        self::assertEqualsWithDelta(15.0 + 10.0 + 60.0, (new ProcessingBudget())->estimateSeconds($job), 0.0001);
    }

    public function testTheFloorTheCeilingAndTheScaleApply(): void
    {
        $small = self::job(10, 10, 1, ImageFormat::WEBP);
        $large = self::job(4000, 4000, 1, ImageFormat::AVIF)->withEffort(9);
        $budget = new ProcessingBudget();

        self::assertSame(30.0, $budget->timeoutSeconds($small));
        self::assertSame(60, $budget->cpuSeconds($small));
        self::assertTrue($budget->admits($small));
        self::assertFalse($budget->admits($large), '16 MP at effort 9 needs about 4800 s');
        self::assertSame(3600.0, $budget->timeoutSeconds($large));
        self::assertSame(min(ResourceLimits::MAX_CPU_SECONDS, 7200), $budget->cpuSeconds($large));
        self::assertTrue($budget->withMaxSeconds(6000.0)->admits($large));

        $scaled = $budget->withScale(3.0)->withMinSeconds(1.0);
        self::assertEqualsWithDelta(3.0 * (15.0 + 0.0001 + 6.0 * 0.0001), $scaled->timeoutSeconds($small), 0.0001);

        $applied = $budget->apply($small);
        self::assertSame([30.0, 60], [$applied->getTimeoutSeconds(), $applied->getCpuSeconds()]);
        self::assertNull($small->getTimeoutSeconds(), 'the job is immutable');
    }

    public function testTheDefaultRequestOfTheLargestImageIsAdmitted(): void
    {
        // 40 megapixels, the default limit, at the default WEBP settings.
        self::assertTrue((new ProcessingBudget())->admits(self::job(8000, 5000, 1, ImageFormat::WEBP)));
    }

    /**
     * @return array<string, array{callable(ProcessingBudget): ProcessingBudget}>
     */
    public static function invalidSettings(): array
    {
        return [
            'ceiling below the floor' => [static function (ProcessingBudget $budget): ProcessingBudget {
                return $budget->withMaxSeconds(10.0);
            }],
            'ceiling above the runner maximum' => [static function (ProcessingBudget $budget): ProcessingBudget {
                return $budget->withMaxSeconds(1.0e9);
            }],
            'floor of zero' => [static function (ProcessingBudget $budget): ProcessingBudget {
                return $budget->withMinSeconds(0.0);
            }],
            'floor above the ceiling' => [static function (ProcessingBudget $budget): ProcessingBudget {
                return $budget->withMinSeconds(4000.0);
            }],
            'scale too small' => [static function (ProcessingBudget $budget): ProcessingBudget {
                return $budget->withScale(0.01);
            }],
            'scale not a number' => [static function (ProcessingBudget $budget): ProcessingBudget {
                return $budget->withScale(NAN);
            }],
        ];
    }

    /**
     * @dataProvider invalidSettings
     */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRefused(callable $change): void
    {
        $this->expectException(InvalidArgumentException::class);

        $change(new ProcessingBudget());
    }

    private static function job(int $width, int $height, int $frames, string $format): BackendJob
    {
        return new BackendJob('/srv/in', new ImageInfo(ImageFormat::PNG, $width, $height, $frames, 1000), '/srv/out', $format, $width, $height);
    }
}
