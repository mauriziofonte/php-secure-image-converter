<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\CommandBuilder;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use Mfonte\SecureImageConverter\Isolation\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Runs real processes at the none, process and rlimit levels: timeout, process group kill, output caps, environment,
 * working directory and input. The resource limits themselves are tested in ResourceLimitsEnforcementTest, the
 * sandbox in SandboxTest.
 *
 * @covers \Mfonte\SecureImageConverter\Isolation\CommandBuilder
 * @covers \Mfonte\SecureImageConverter\Isolation\ProcessRunner
 *
 * @group isolation
 */
#[CoversClass(ProcessRunner::class)]
#[CoversClass(CommandBuilder::class)]
#[Group('isolation')]
final class ProcessRunnerTest extends IsolationTestCase
{
    /**
     * Every level below the sandbox.
     *
     * @return array<string, array{string}>
     */
    public static function levels(): array
    {
        return ['none' => [IsolationLevel::NONE]] + self::groupLevels();
    }

    private function runner(string $level, ?ProcessOptions $options = null): ProcessRunner
    {
        $this->requireLevel($level);

        return new ProcessRunner($level, $options);
    }

    /**
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testArgumentsReachTheProgramVerbatimWithoutAShell(string $level): void
    {
        $arguments = ['a b', '$(id)', '`id`', '; touch injected', "it's", '"quoted"', '*', '', "tab\tand\nnewline"];
        $result = $this->runner($level)->run(
            array_merge(['printf', '[%s]'], $arguments),
            $this->workingDirectory()
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame('[' . implode('][', $arguments) . ']', $result->getStdout());
        self::assertFileDoesNotExist($this->workingDirectory()->pathOf('injected'));
        self::assertSame($level, $result->getIsolationLevel());
    }

    /**
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testReportsTheExitCodeAndTheOutput(string $level): void
    {
        $result = $this->runner($level)->run(['sh', '-c', 'echo out; echo err >&2; exit 7'], $this->workingDirectory());

        self::assertSame(7, $result->getExitCode());
        self::assertNull($result->getSignal());
        self::assertFalse($result->isTimedOut());
        self::assertFalse($result->isSuccessful());
        self::assertSame("out\n", $result->getStdout());
        self::assertSame("err\n", $result->getStderr());
        self::assertFalse($result->isStdoutTruncated());
        self::assertFalse($result->isStderrTruncated());
        self::assertGreaterThan(0.0, $result->getDurationSeconds());
        self::assertLessThan(5.0, $result->getDurationSeconds());
    }

    /**
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testReportsTheTerminatingSignal(string $level): void
    {
        $result = $this->runner($level)->run(['sh', '-c', 'kill -KILL $$'], $this->workingDirectory());

        self::assertNull($result->getExitCode());
        self::assertSame(9, $result->getSignal());
        self::assertFalse($result->isTimedOut());
        self::assertFalse($result->isSuccessful());
    }

    /**
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testTimeoutKillsARunawayProcess(string $level): void
    {
        $options = (new ProcessOptions())->withTimeoutSeconds(0.5);
        $result = $this->runner($level, $options)->execute(['sleep', '30'], $this->workingDirectory());

        self::assertTrue($result->isTimedOut());
        self::assertFalse($result->isSuccessful());
        self::assertSame(15, $result->getSignal(), 'SIGTERM is enough for a process that does not ignore it');
        self::assertGreaterThanOrEqual(0.5, $result->getDurationSeconds());
        self::assertLessThan(3.0, $result->getDurationSeconds());
    }

    /**
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testRunThrowsTheContractTimeoutException(string $level): void
    {
        $options = (new ProcessOptions())->withTimeoutSeconds(0.25);
        $runner = $this->runner($level, $options);
        $started = microtime(true);
        try {
            $runner->run(['sleep', '30'], $this->workingDirectory());
            self::fail('A timeout must throw ConversionTimeoutException.');
        } catch (ConversionTimeoutException $exception) {
            self::assertSame(ErrorCode::CONVERSION_TIMEOUT, $exception->getErrorCode());
            self::assertSame(
                'The process did not finish within its wall-clock timeout of 0.25 seconds and was killed.',
                $exception->getMessage()
            );
        }
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    /**
     * @dataProvider groupLevels
     */
    #[DataProvider('groupLevels')]
    public function testTimeoutEscalatesToSigkillWhenSigtermIsIgnored(string $level): void
    {
        $options = (new ProcessOptions())->withTimeoutSeconds(0.3)->withKillGraceSeconds(0.4);
        $result = $this->runner($level, $options)->execute(
            ['sh', '-c', 'trap "" TERM; sleep 30 & echo $!; wait'],
            $this->workingDirectory()
        );

        self::assertTrue($result->isTimedOut());
        self::assertSame(9, $result->getSignal());
        self::assertGreaterThanOrEqual(0.7, $result->getDurationSeconds());
        self::assertLessThan(4.0, $result->getDurationSeconds());
        $pids = self::pids($result->getStdout());
        self::assertCount(1, $pids);
        self::assertTrue(self::waitUntilEnded($pids[0], 2.0), 'the child that ignored SIGTERM is killed as well');
    }

    /**
     * @dataProvider groupLevels
     */
    #[DataProvider('groupLevels')]
    public function testTimeoutKillsGrandchildren(string $level): void
    {
        $options = (new ProcessOptions())->withTimeoutSeconds(0.5);
        $result = $this->runner($level, $options)->execute(
            ['sh', '-c', 'sleep 30 & echo $!; sh -c "sleep 30 & echo \$!; wait" & wait'],
            $this->workingDirectory()
        );

        self::assertTrue($result->isTimedOut());
        $pids = self::pids($result->getStdout());
        self::assertCount(2, $pids, 'a child and a grandchild of the command were started');
        foreach ($pids as $pid) {
            self::assertTrue(self::waitUntilEnded($pid, 2.0), sprintf('descendant %d survived the timeout', $pid));
        }
    }

    /**
     * @dataProvider groupLevels
     */
    #[DataProvider('groupLevels')]
    public function testBackgroundChildrenDoNotOutliveASuccessfulRun(string $level): void
    {
        // The background sleep keeps stdout open: the runner must neither wait for it nor let it live on.
        $result = $this->runner($level)->run(['sh', '-c', 'sleep 30 & echo $!'], $this->workingDirectory());

        self::assertTrue($result->isSuccessful());
        self::assertLessThan(2.0, $result->getDurationSeconds());
        $pids = self::pids($result->getStdout());
        self::assertCount(1, $pids);
        self::assertTrue(self::waitUntilEnded($pids[0], 2.0), 'the background child was killed after the run');
    }

    /**
     * The none level has no process group: only the direct child is killed. This is why backends never run external
     * programs at that level.
     */
    public function testWithoutAProcessGroupOnlyTheDirectChildIsKilled(): void
    {
        $options = (new ProcessOptions())->withTimeoutSeconds(0.3);
        $result = (new ProcessRunner(IsolationLevel::NONE, $options))->execute(
            ['sh', '-c', 'sleep 30 >/dev/null 2>&1 & echo $!; wait'],
            $this->workingDirectory()
        );

        self::assertTrue($result->isTimedOut());
        $pids = self::pids($result->getStdout());
        self::assertCount(1, $pids);
        try {
            self::assertTrue(self::isRunning($pids[0]), 'the grandchild survives at the none level');
        } finally {
            if (function_exists('posix_kill')) {
                posix_kill($pids[0], 9);
            }
        }
    }

    /**
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testOutputIsCappedWhileTheProcessIsDrained(string $level): void
    {
        $options = (new ProcessOptions())->withMaxOutputBytes(1000)->withTimeoutSeconds(20.0);
        $runner = $this->runner($level, $options);

        $result = $runner->run(['head', '-c', '5000000', '/dev/zero'], $this->workingDirectory());
        self::assertTrue($result->isSuccessful(), 'the process is never blocked by a full pipe');
        self::assertSame(str_repeat("\0", 1000), $result->getStdout());
        self::assertTrue($result->isStdoutTruncated());
        self::assertFalse($result->isStderrTruncated());

        $result = $runner->run(['sh', '-c', 'head -c 5000000 /dev/zero >&2; echo done'], $this->workingDirectory());
        self::assertTrue($result->isSuccessful());
        self::assertSame("done\n", $result->getStdout());
        self::assertSame(1000, strlen($result->getStderr()));
        self::assertTrue($result->isStderrTruncated());
        self::assertFalse($result->isStdoutTruncated());

        $result = $runner->run(['printf', '%1000s', ''], $this->workingDirectory());
        self::assertSame(1000, strlen($result->getStdout()));
        self::assertFalse($result->isStdoutTruncated(), 'exactly the limit is not a truncation');
    }

    public function testOutputCanBeDiscardedEntirely(): void
    {
        $options = (new ProcessOptions())->withMaxOutputBytes(0);
        $result = (new ProcessRunner(IsolationLevel::NONE, $options))->run(['echo', 'x'], $this->workingDirectory());

        self::assertSame('', $result->getStdout());
        self::assertTrue($result->isStdoutTruncated());
    }

    /**
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testTheEnvironmentIsSanitised(string $level): void
    {
        putenv('SIC_TEST_SECRET=database-password');
        putenv('SIC_TEST_INHERITED=allowed');
        try {
            $options = (new ProcessOptions())
                ->withEnvironment(['LANG' => 'C', 'LC_ALL' => 'C', 'VIPS_CONCURRENCY' => '1'])
                ->withInheritedEnvironment(['SIC_TEST_INHERITED']);
            $result = $this->runner($level, $options)->run(['env'], $this->workingDirectory());
        } finally {
            putenv('SIC_TEST_SECRET');
            putenv('SIC_TEST_INHERITED');
        }

        self::assertTrue($result->isSuccessful());
        $environment = [];
        foreach (explode("\n", trim($result->getStdout())) as $line) {
            $pair = explode('=', $line, 2);
            self::assertCount(2, $pair);
            $environment[$pair[0]] = $pair[1];
        }
        ksort($environment);
        $directory = $this->workingDirectory()->getPath();
        self::assertSame([
            'HOME' => $directory,
            'LANG' => 'C',
            'LC_ALL' => 'C',
            'PATH' => implode(':', ProcessOptions::DEFAULT_SEARCH_PATH),
            'PWD' => $directory,
            'SIC_TEST_INHERITED' => 'allowed',
            'TMPDIR' => $directory,
            'VIPS_CONCURRENCY' => '1',
        ], $environment);
        self::assertStringNotContainsString('database-password', $result->getStdout());
    }

    /**
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testDescriptorsOpenInPhpAreNotInherited(string $level): void
    {
        $file = fopen(__FILE__, 'r');
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($file);
        self::assertIsResource($server);
        try {
            $result = $this->runner($level)->run(['ls', '-l', '/proc/self/fd'], $this->workingDirectory());
        } finally {
            fclose($file);
            fclose($server);
        }

        self::assertTrue($result->isSuccessful());
        self::assertStringNotContainsString(basename(__FILE__), $result->getStdout());
        self::assertStringNotContainsString('socket:', $result->getStdout());
        self::assertSame(1, preg_match_all('/ 0 -> \/dev\/null$/m', $result->getStdout()));
        self::assertSame(2, preg_match_all('/ [12] -> pipe:/m', $result->getStdout()));
        self::assertGreaterThan(0, preg_match_all('/ (\d+) -> (.+)$/m', $result->getStdout(), $matches, PREG_SET_ORDER));
        foreach ($matches as $match) {
            if ((int) $match[1] > 2) {
                // ls itself holds the /proc/<pid>/fd directory open while it lists it.
                self::assertMatchesRegularExpression('#\A(/dev/null|/proc/[0-9]+/fd)\z#', $match[2]);
            }
        }
    }

    /**
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testRunsInThePrivateWorkingDirectory(string $level): void
    {
        $result = $this->runner($level)->run(['sh', '-c', 'pwd; echo data > out.txt'], $this->workingDirectory());

        self::assertTrue($result->isSuccessful());
        self::assertSame($this->workingDirectory()->getPath() . "\n", $result->getStdout());
        self::assertStringEqualsFile($this->workingDirectory()->pathOf('out.txt'), "data\n");
    }

    /**
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testStandardInputIsEmptyOrAFile(string $level): void
    {
        $runner = $this->runner($level);

        $result = $runner->run(['cat'], $this->workingDirectory());
        self::assertTrue($result->isSuccessful(), 'cat sees end of file at once instead of waiting');
        self::assertSame('', $result->getStdout());

        $input = $this->workingDirectory()->pathOf('input.bin');
        file_put_contents($input, "line 1\nline 2\n\0binary");
        $result = $runner->run(['cat'], $this->workingDirectory(), $input);
        self::assertSame("line 1\nline 2\n\0binary", $result->getStdout());
    }

    public function testExplicitProgramPathsAreAccepted(): void
    {
        $result = (new ProcessRunner(IsolationLevel::NONE))->run([PHP_BINARY, '-n', '-r', 'echo 6 * 7;'], $this->workingDirectory());

        self::assertSame('42', $result->getStdout());
    }

    /**
     * @return array<string, array{list<string>, string|null, list<string>}>
     */
    public static function invalidRuns(): array
    {
        return [
            'empty command' => [[], null, []],
            'unknown program' => [['sic-no-such-program'], null, []],
            'relative program path' => [['bin/sh'], null, []],
            'NUL in an argument' => [['echo', "a\0b"], null, []],
            'missing standard input' => [['cat'], '/nonexistent/input', []],
            'directory as standard input' => [['cat'], '/', []],
            'relative read-only path' => [['true'], null, ['input.png']],
            'missing read-only path' => [['true'], null, ['/nonexistent/input.png']],
        ];
    }

    /**
     * @dataProvider invalidRuns
     *
     * @param list<string> $command
     * @param list<string> $readOnlyPaths
     */
    #[DataProvider('invalidRuns')]
    public function testInvalidRunsAreRejected(array $command, ?string $stdinPath, array $readOnlyPaths): void
    {
        try {
            (new ProcessRunner(IsolationLevel::NONE))->run($command, $this->workingDirectory(), $stdinPath, $readOnlyPaths);
            self::fail('The run must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(ErrorCode::INTERNAL_ERROR, $exception->getErrorCode());
            self::assertStringNotContainsString('/nonexistent', $exception->getMessage(), 'no path in messages');
        }
    }

    public function testARemovedWorkingDirectoryIsRejected(): void
    {
        $this->workingDirectory()->remove();

        $this->expectException(InvalidArgumentException::class);

        (new ProcessRunner(IsolationLevel::NONE))->run(['true'], $this->workingDirectory());
    }

    /**
     * @return array<string, array{string, string, non-empty-string}>
     */
    public static function missingTools(): array
    {
        return [
            'process without setsid' => [IsolationLevel::PROCESS, ProcessOptions::TOOL_SETSID, 'setsid is not available'],
            'rlimit without prlimit' => [IsolationLevel::RLIMIT, ProcessOptions::TOOL_PRLIMIT, 'prlimit is not available'],
            'sandbox without bwrap' => [IsolationLevel::SANDBOX, ProcessOptions::TOOL_BWRAP, 'bubblewrap (bwrap) is not available'],
        ];
    }

    /**
     * @dataProvider missingTools
     *
     * @param non-empty-string $reason
     */
    #[DataProvider('missingTools')]
    public function testNeverRunsBelowItsLevel(string $level, string $tool, string $reason): void
    {
        $runner = new ProcessRunner($level, (new ProcessOptions())->withToolPath($tool, null));
        $reasonFound = $runner->getUnavailableReason();
        self::assertIsString($reasonFound);
        self::assertStringStartsWith($reason, $reasonFound);

        try {
            $runner->run(['true'], $this->workingDirectory());
            self::fail('The runner must refuse to run below its level.');
        } catch (IsolationUnavailableException $exception) {
            self::assertSame(ErrorCode::ISOLATION_UNAVAILABLE, $exception->getErrorCode());
            self::assertStringContainsString(sprintf('The %s isolation level is unavailable', $level), $exception->getMessage());
        }
    }

    public function testInvalidLevelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcessRunner('chroot');
    }

    public function testAccessors(): void
    {
        $options = (new ProcessOptions())->withTimeoutSeconds(3.0);
        $runner = new ProcessRunner(IsolationLevel::PROCESS, $options);

        self::assertSame(IsolationLevel::PROCESS, $runner->getLevel());
        self::assertSame($options, $runner->getOptions());
        self::assertEquals(new ProcessOptions(), (new ProcessRunner(IsolationLevel::NONE))->getOptions());
        self::assertTrue(ProcessRunner::isProcOpenAvailable());
        self::assertNull((new ProcessRunner(IsolationLevel::NONE))->getUnavailableReason());
    }

    public function testRunsAreIndependent(): void
    {
        $this->requireLevel(IsolationLevel::PROCESS);
        $runner = new ProcessRunner(IsolationLevel::PROCESS);
        $results = [];
        for ($index = 0; $index < 5; ++$index) {
            $results[] = $runner->run(['sh', '-c', 'echo ' . $index . '; exit ' . $index], $this->workingDirectory());
        }

        self::assertSame([0, 1, 2, 3, 4], array_map(static function (ProcessResult $result): ?int {
            return $result->getExitCode();
        }, $results));
        self::assertSame("4\n", $results[4]->getStdout());
    }
}
