<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Isolation;

use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\CommandBuilder;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessRunner;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves that the prlimit limits of the rlimit level are enforced by the kernel on the command and on everything it
 * starts: a runaway allocation, a busy loop and an oversized write are stopped long before the wall-clock timeout.
 *
 * @covers \Mfonte\SecureImageConverter\Isolation\CommandBuilder
 * @covers \Mfonte\SecureImageConverter\Isolation\ProcessRunner
 * @covers \Mfonte\SecureImageConverter\Isolation\ResourceLimits
 *
 * @group isolation
 */
#[CoversClass(ProcessRunner::class)]
#[CoversClass(CommandBuilder::class)]
#[CoversClass(ResourceLimits::class)]
#[Group('isolation')]
final class ResourceLimitsEnforcementTest extends IsolationTestCase
{
    private function runner(ResourceLimits $limits, float $timeout = 30.0): ProcessRunner
    {
        $this->requireLevel(IsolationLevel::RLIMIT);

        return new ProcessRunner(
            IsolationLevel::RLIMIT,
            (new ProcessOptions())->withResourceLimits($limits)->withTimeoutSeconds($timeout)
        );
    }

    public function testMemoryCapStopsARunawayAllocation(): void
    {
        $limits = (new ResourceLimits())->withAddressSpaceBytes(256 * 1024 * 1024);
        // Allocates up to 1 GiB in 1 MiB blocks; without the cap it would print "survived" and exit with 0.
        $script = '$blocks = []; for ($i = 0; $i < 1024; ++$i) { $blocks[] = str_repeat("x", 1048576); } echo "survived";';
        $result = $this->runner($limits)->execute(
            [PHP_BINARY, '-n', '-d', 'memory_limit=-1', '-r', $script],
            $this->workingDirectory()
        );

        self::assertFalse($result->isSuccessful());
        self::assertFalse($result->isTimedOut(), 'the memory cap, not the timeout, stopped the process');
        self::assertStringNotContainsString('survived', $result->getStdout());
        self::assertLessThan(10.0, $result->getDurationSeconds());
        self::assertTrue(
            $result->getExitCode() !== null || in_array($result->getSignal(), [6, 9, 11], true),
            'the process failed to allocate, or crashed on the failed allocation'
        );
    }

    public function testCpuCapKillsABusyLoop(): void
    {
        $limits = (new ResourceLimits())->withCpuSeconds(1);
        $result = $this->runner($limits)->execute(['sh', '-c', 'while :; do :; done'], $this->workingDirectory());

        self::assertFalse($result->isTimedOut(), 'the CPU cap, not the timeout, stopped the process');
        self::assertContains($result->getSignal(), [24, 9], 'SIGXCPU or SIGKILL');
        self::assertLessThan(10.0, $result->getDurationSeconds());
    }

    public function testFileSizeCapStopsAnOversizedWrite(): void
    {
        $limits = (new ResourceLimits())->withFileSizeBytes(1048576);
        $result = $this->runner($limits)->execute(
            ['dd', 'if=/dev/zero', 'of=big.bin', 'bs=65536', 'count=64'],
            $this->workingDirectory()
        );

        self::assertSame(25, $result->getSignal(), 'SIGXFSZ');
        self::assertFalse($result->isTimedOut());
        $size = filesize($this->workingDirectory()->pathOf('big.bin'));
        self::assertSame(1048576, $size, 'the file stops growing at the limit');
    }

    public function testOpenFileCapIsEnforced(): void
    {
        // PHP passes its own descriptors on, replaced with /dev/null (under Composer and PHPUnit 10 or later, eight
        // or more): the cap leaves room for them, and stays below the 100 opens of the script.
        $limits = (new ResourceLimits())->withOpenFiles(64);
        // Opens /dev/null up to 100 times; without the cap every open succeeds.
        $script = '$handles = []; for ($i = 0; $i < 100; ++$i) { $handle = @fopen("/dev/null", "r"); '
            . 'if ($handle === false) { echo count($handles); exit(0); } $handles[] = $handle; } echo "all opened";';
        $result = $this->runner($limits)->run([PHP_BINARY, '-n', '-r', $script], $this->workingDirectory());

        self::assertTrue($result->isSuccessful());
        self::assertMatchesRegularExpression('/\A[0-9]+\z/', $result->getStdout());
        self::assertGreaterThan(0, (int) $result->getStdout());
        self::assertLessThanOrEqual(61, (int) $result->getStdout(), 'at most 64 descriptors, 3 of them standard');
    }

    public function testEveryLimitIsAppliedToTheCommandAndItsChildren(): void
    {
        $limits = (new ResourceLimits())
            ->withAddressSpaceBytes(536870912)
            ->withCpuSeconds(17)
            ->withFileSizeBytes(2097152)
            ->withOpenFiles(64)
            ->withProcesses(4096);
        // cat is a child of sh: limits are inherited.
        $result = $this->runner($limits)->run(['sh', '-c', 'cat /proc/self/limits'], $this->workingDirectory());

        self::assertTrue($result->isSuccessful());
        $expected = [
            'Max cpu time' => '17',
            'Max file size' => '2097152',
            'Max core file size' => '0',
            'Max processes' => '4096',
            'Max open files' => '64',
            'Max address space' => '536870912',
        ];
        foreach ($expected as $row => $value) {
            self::assertMatchesRegularExpression(
                '/^' . preg_quote($row, '/') . '\s+' . $value . '\s+' . $value . '\s/m',
                $result->getStdout(),
                sprintf('%s is set as soft and hard limit', $row)
            );
        }
    }

    public function testNoLimitIsAppliedBelowTheRlimitLevel(): void
    {
        $this->requireLevel(IsolationLevel::PROCESS);
        $options = (new ProcessOptions())->withResourceLimits((new ResourceLimits())->withCpuSeconds(17));
        $result = (new ProcessRunner(IsolationLevel::PROCESS, $options))->run(
            ['cat', '/proc/self/limits'],
            $this->workingDirectory()
        );

        self::assertDoesNotMatchRegularExpression('/^Max cpu time\s+17\s/m', $result->getStdout());
    }
}
