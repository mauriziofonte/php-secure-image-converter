<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Pipeline;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\DetectionResult;
use Mfonte\SecureImageConverter\Isolation\IsolationPolicy;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;

use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;
use Mfonte\SecureImageConverter\Tests\Unit\Pipeline\Fake\FakeDetector;
use Mfonte\SecureImageConverter\Tests\Unit\Pipeline\Fake\FakeSemaphore;
use Mfonte\SecureImageConverter\Validation\Limits;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder
 */
#[CoversClass(SecureConverterBuilder::class)]
final class SecureConverterBuilderTest extends TestCase
{
    public function testABestEffortPolicyReportsTheDetectedLevelWithAWarning(): void
    {
        $detector = self::detector();

        $converter = (new SecureConverterBuilder())->withIsolationDetector($detector)->withBackends(['gd'])->build();

        self::assertSame(1, $detector->calls);
        self::assertSame(IsolationLevel::RLIMIT, $converter->getOptions()->getIsolationLevel());
        self::assertCount(1, $converter->getOptions()->getIsolationWarnings());
        self::assertStringContainsString('rlimit', $converter->getOptions()->getIsolationWarnings()[0]);
    }

    public function testTheSettingsReachTheConverter(): void
    {
        $semaphore = new FakeSemaphore();
        $options = (new ConverterOptions())->withWorkingDirectoryParent(sys_get_temp_dir())->withSlotWaitSeconds(1.0);
        $builder = (new SecureConverterBuilder())
            ->withIsolationDetector(self::detector())
            ->withIsolationPolicy(IsolationPolicy::bestEffort(IsolationLevel::RLIMIT))
            ->withLimits((new Limits())->withMaxFrames(7))
            ->withInputFormats([ImageFormat::PNG])
            ->withSemaphore($semaphore)
            ->withConverterOptions($options)
            ->withBackends(['gd']);

        $converter = $builder->build();

        self::assertSame([], $converter->getOptions()->getIsolationWarnings(), 'rlimit is what the policy expects');
        self::assertSame(1.0, $converter->getOptions()->getSlotWaitSeconds());
        self::assertSame(sys_get_temp_dir(), $converter->getOptions()->getWorkingDirectoryParent());
        self::assertSame(7, $converter->getCapabilities()->getLimits()['max_frames']);
        self::assertNotSame($converter, $builder->build(), 'build() can be called again');
    }

    public function testAStrictPolicyAboveTheDetectedLevelIsRefused(): void
    {
        $this->expectException(IsolationUnavailableException::class);

        (new SecureConverterBuilder())->withIsolationDetector(self::detector())
            ->withIsolationPolicy(IsolationPolicy::strict(IsolationLevel::SANDBOX))->build();
    }

    public function testAnUnknownBackendIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SecureConverterBuilder())->withIsolationDetector(self::detector())->withBackends(['paint'])->build();
    }

    private static function detector(): FakeDetector
    {
        return new FakeDetector(new DetectionResult(IsolationLevel::RLIMIT, [IsolationLevel::SANDBOX => 'bwrap is not installed'], time()));
    }
}
