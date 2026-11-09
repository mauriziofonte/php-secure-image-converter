<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Isolation;

use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\IsolationDetector;
use Mfonte\SecureImageConverter\Isolation\IsolationDetectorInterface;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessRunner;
use Mfonte\SecureImageConverter\Isolation\ProcessRunnerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The isolation services consumed by the backends are reachable through interfaces, so that backends can be tested
 * with fakes.
 *
 * @covers \Mfonte\SecureImageConverter\Isolation\IsolationDetector
 * @covers \Mfonte\SecureImageConverter\Isolation\ProcessRunner
 */
#[CoversClass(ProcessRunner::class)]
#[CoversClass(IsolationDetector::class)]
final class IsolationInterfacesTest extends TestCase
{
    public function testTheRunnerAndTheDetectorImplementTheirInterfaces(): void
    {
        self::assertContains(ProcessRunnerInterface::class, (array) class_implements(ProcessRunner::class));
        self::assertContains(IsolationDetectorInterface::class, (array) class_implements(IsolationDetector::class));
    }

    public function testWithOptionsKeepsTheLevelAndLeavesTheRunnerUnchanged(): void
    {
        $options = new ProcessOptions();
        $runner = new ProcessRunner(IsolationLevel::RLIMIT, $options);
        $other = (new ProcessOptions())->withTimeoutSeconds(5.0)->withToolPath(ProcessOptions::TOOL_PRLIMIT, null);

        $derived = $runner->withOptions($other);

        self::assertNotSame($runner, $derived);
        self::assertSame(IsolationLevel::RLIMIT, $derived->getLevel());
        self::assertSame($other, $derived->getOptions());
        self::assertSame($options, $runner->getOptions());
        self::assertSame(
            'prlimit is not available (not found, not executable or disabled)',
            $derived->getUnavailableReason(),
            'the tools are located again with the new options'
        );
    }
}
