<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\CommandBuilder;
use Mfonte\SecureImageConverter\Isolation\IsolationDetector;
use Mfonte\SecureImageConverter\Isolation\OutputCapture;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use Mfonte\SecureImageConverter\Isolation\ProcessRunner;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Runs real processes at the none, process and rlimit levels: timeout, process group kill, output caps, environment,
 * working directory and input. The resource limits themselves are tested in ResourceLimitsEnforcementTest, the
 * sandbox in SandboxTest.
 *
 * @covers \Mfonte\SecureImageConverter\Isolation\CommandBuilder
 * @covers \Mfonte\SecureImageConverter\Isolation\OutputCapture
 * @covers \Mfonte\SecureImageConverter\Isolation\ProcessRunner
 *
 * @group isolation
 */
#[CoversClass(ProcessRunner::class)]
#[CoversClass(CommandBuilder::class)]
#[CoversClass(OutputCapture::class)]
#[Group('isolation')]
final class ProcessRunnerTest extends IsolationTestCase
{
    private const STANDARD_INPUT_REFUSED = 'The standard input must be a readable regular file directly inside the '
        . 'working directory, not a symbolic link, without other hard links.';

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
        $arguments = [
            'a b', '$(id)', '`id`', '; touch injected', "it's", "'", "''", "'\\''", '"quoted"', '\\', '*', '',
            "tab\tand\nnewline", '-n', '--',
            // Non-ASCII and invalid UTF-8 bytes, which escapeshellarg() drops on PHP 7.2 and 7.3 in the C locale.
            'é', "\x80\xff", "x\x80\xff\xc3(y", "\xc3", "\x7f\x01\x1b[0m",
        ];
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
    public function testALongArgumentWithEveryByteReachesTheProgramVerbatim(string $level): void
    {
        // Every byte value but NUL, single quotes included, close to the command line limit once quoted.
        $argument = str_repeat(implode('', array_map('chr', range(1, 255))), 470);
        self::assertGreaterThan(119000, strlen($argument));
        $command = ['printf', '%s', $argument];
        self::assertLessThan(
            CommandBuilder::MAX_COMMAND_LINE_BYTES - 1000,
            strlen(CommandBuilder::toShellCommand($command))
        );

        $result = $this->runner($level)->run($command, $this->workingDirectory());

        self::assertTrue($result->isSuccessful());
        self::assertSame(strlen($argument), strlen($result->getStdout()));
        self::assertSame(sha1($argument), sha1($result->getStdout()), 'the output is byte for byte the argument');
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function oversizedCommands(): array
    {
        return [
            'one long argument' => [['printf', '%s', str_repeat('x', CommandBuilder::MAX_COMMAND_LINE_BYTES)]],
            'many arguments' => [array_merge(['printf', '%s'], array_fill(0, 20000, 'abcdef'))],
            'quotes count four times' => [['printf', '%s', str_repeat("'", 40000)]],
        ];
    }

    /**
     * The limit is the same on every PHP version, also where proc_open() receives the argument list itself.
     *
     * @dataProvider oversizedCommands
     *
     * @param list<string> $command
     */
    #[DataProvider('oversizedCommands')]
    public function testCommandLinesOverTheLimitAreRejected(array $command): void
    {
        try {
            (new ProcessRunner(IsolationLevel::NONE))->run($command, $this->workingDirectory());
            self::fail('An oversized command line must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(ErrorCode::INTERNAL_ERROR, $exception->getErrorCode());
            self::assertSame(
                'The command line must not be longer than 131071 bytes once quoted, isolation wrappers included.',
                $exception->getMessage()
            );
        }
    }

    public function testTheLimitCountsTheIsolationWrappers(): void
    {
        $this->requireLevel(IsolationLevel::RLIMIT);
        $command = ['printf', '%s', str_repeat('x', CommandBuilder::MAX_COMMAND_LINE_BYTES - 100)];
        $result = (new ProcessRunner(IsolationLevel::NONE))->run($command, $this->workingDirectory());
        self::assertTrue($result->isSuccessful(), 'the command fits without wrappers');

        $this->expectException(InvalidArgumentException::class);

        (new ProcessRunner(IsolationLevel::RLIMIT))->run($command, $this->workingDirectory());
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
     * Descriptors with close-on-exec never reach the child, so they are neither replaced nor counted against the
     * open files limit.
     *
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testDescriptorsWithCloseOnExecAreLeftAlone(string $level): void
    {
        $options = (new ProcessOptions())->withResourceLimits((new ResourceLimits())->withOpenFiles(32));
        $runner = $this->runner($level, $options);
        $this->openDescriptors(64, false);

        $result = $runner->run(['ls', '/proc/self/fd'], $this->workingDirectory());

        self::assertTrue($result->isSuccessful());
        self::assertLessThan(16, count(explode("\n", trim($result->getStdout()))), $result->getStdout());
    }

    /**
     * proc_open() cannot close descriptors in the child, only replace them, and the replacements count against the
     * open files limit: when they would leave too little room, the run fails before anything starts instead of
     * letting the program fail to load (exit code 127).
     */
    public function testInheritedDescriptorsMustLeaveRoomBelowTheOpenFilesLimit(): void
    {
        $this->requireLevel(IsolationLevel::RLIMIT);
        $limits = (new ResourceLimits())->withOpenFiles(32);
        $options = (new ProcessOptions())->withResourceLimits($limits);
        // Descriptors are allocated lowest first: these fill the numbers below 32.
        $this->openDescriptors(40, true);

        try {
            (new ProcessRunner(IsolationLevel::RLIMIT, $options))->run(['true'], $this->workingDirectory());
            self::fail('The run must be refused.');
        } catch (ConversionFailedException $exception) {
            self::assertSame(ErrorCode::CONVERSION_FAILED, $exception->getErrorCode());
            self::assertMatchesRegularExpression(
                '/\AThe [0-9]+ descriptors inherited from PHP would leave the process fewer than 16 free descriptors '
                . 'below its open files limit of 32: raise the limit with ResourceLimits::withOpenFiles\(\) /',
                $exception->getMessage()
            );
        }

        $roomy = $options->withResourceLimits($limits->withOpenFiles(1024));
        $result = (new ProcessRunner(IsolationLevel::RLIMIT, $roomy))->run(['true'], $this->workingDirectory());
        self::assertTrue($result->isSuccessful(), 'a higher limit leaves room');
        $result = (new ProcessRunner(IsolationLevel::PROCESS, $options))->run(['true'], $this->workingDirectory());
        self::assertTrue($result->isSuccessful(), 'below the rlimit level there is no limit to check');
    }

    /**
     * stream_select() cannot poll descriptors numbered 1024 or more: the pipes get such numbers when PHP holds that
     * many descriptors open. The run must fail, and its process must be killed, instead of being reported as a
     * success without output.
     *
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testOutputThatCannotBeReadFailsTheRun(string $level): void
    {
        $runner = $this->runner($level);
        self::requireOpenFilesLimit(1400);
        $token = 'sic-unread-' . bin2hex(random_bytes(6));
        // Directory handles have close-on-exec: the child inherits none of them, only the pipes are affected.
        $this->openDescriptors(1100, false);
        $started = microtime(true);

        try {
            $runner->run(['sh', '-c', 'printf output; sleep 5; : "$0"', $token], $this->workingDirectory());
            self::fail('A run whose output cannot be read must not succeed.');
        } catch (ConversionFailedException $exception) {
            self::assertSame(ErrorCode::CONVERSION_FAILED, $exception->getErrorCode());
            self::assertSame(
                'The output of the process cannot be read: PHP holds more open descriptors than stream_select() '
                . 'supports (FD_SETSIZE).',
                $exception->getMessage()
            );
        }
        self::assertLessThan(4.0, microtime(true) - $started, 'the run fails at once, not when the process ends');
        self::assertSame([], self::processesWithArgument($token), 'the process was killed');

        $this->closeDescriptors();
        self::assertSame('output', $runner->run(['printf', 'output'], $this->workingDirectory())->getStdout());
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

    /**
     * A program can reopen its standard input for writing through /proc/self/fd/0: the file must be one it may write
     * anyway, directly in the working directory, and not reachable from outside through another link.
     */
    public function testStandardInputMustBeAFileOfTheWorkingDirectory(): void
    {
        $runner = new ProcessRunner(IsolationLevel::NONE);
        $outside = WorkingDirectory::create();
        try {
            $secret = $outside->pathOf('secret.txt');
            file_put_contents($secret, 'secret');
            $symbolicLink = $this->workingDirectory()->pathOf('symbolic.txt');
            symlink($secret, $symbolicLink);
            $hardLink = $this->workingDirectory()->pathOf('hard.txt');
            link($secret, $hardLink);
            $parent = $this->workingDirectory()->getPath() . '/../' . basename($outside->getPath()) . '/secret.txt';
            file_put_contents($this->workingDirectory()->pathOf('input.txt'), 'input');
            symlink('input.txt', $this->workingDirectory()->pathOf('alias.txt'));
            mkdir($this->workingDirectory()->pathOf('sub'));
            file_put_contents($this->workingDirectory()->getPath() . '/sub/input.txt', 'input');
            $dotted = $this->workingDirectory()->getPath() . '/./input.txt';

            $refused = [
                $secret,
                $symbolicLink,
                $hardLink,
                $parent,
                $this->workingDirectory()->pathOf('alias.txt'),
                $this->workingDirectory()->getPath() . '/sub/input.txt',
                $dotted,
            ];
            foreach ($refused as $path) {
                try {
                    $runner->run(['cat'], $this->workingDirectory(), $path);
                    self::fail('The standard input must be refused.');
                } catch (InvalidArgumentException $exception) {
                    self::assertSame(self::STANDARD_INPUT_REFUSED, $exception->getMessage());
                }
            }
        } finally {
            $outside->remove();
        }

        $result = $runner->run(['cat'], $this->workingDirectory(), $this->workingDirectory()->pathOf('input.txt'));
        self::assertSame('input', $result->getStdout());
    }

    /**
     * A run can replace its standard input file with a link to a host file. PHP caches path lookups (realpath cache,
     * stat cache): the next run with the same path must still look at the file itself, never at a cached answer.
     *
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testStandardInputReplacedWithALinkBetweenTwoRunsIsRefused(string $level): void
    {
        $runner = $this->runner($level);
        $outside = WorkingDirectory::create();
        try {
            $host = $outside->pathOf('host.txt');
            file_put_contents($host, 'host data');
            $input = $this->workingDirectory()->pathOf('input.txt');
            file_put_contents($input, 'input');
            self::assertSame('input', $runner->run(['cat'], $this->workingDirectory(), $input)->getStdout());
            // Warm every cache PHP has for this path, as an application that looked at the file would.
            self::assertIsString(realpath($input));
            self::assertTrue(is_file($input));

            $replace = $runner->run(
                ['sh', '-c', 'rm -f input.txt && ln -s "$1" input.txt && echo replaced', 'sh', $host],
                $this->workingDirectory()
            );
            self::assertSame("replaced\n", $replace->getStdout());

            try {
                $runner->run(['sh', '-c', 'cat; echo pwned > /proc/self/fd/0'], $this->workingDirectory(), $input);
                self::fail('The link must be refused.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(self::STANDARD_INPUT_REFUSED, $exception->getMessage());
            }
            self::assertStringEqualsFile($host, 'host data');
        } finally {
            $outside->remove();
        }
    }

    public function testStandardInputReplacedWithAFifoIsRefusedWithoutBlocking(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('ext-posix is needed to create a FIFO.');
        }
        $input = $this->workingDirectory()->pathOf('input.txt');
        self::assertTrue(posix_mkfifo($input, 0600));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(self::STANDARD_INPUT_REFUSED);

        (new ProcessRunner(IsolationLevel::NONE))->run(['cat'], $this->workingDirectory(), $input);
    }

    /**
     * Under an open_basedir that excludes /proc and /dev/fd, the descriptors that the child would inherit cannot be
     * listed: from the process level up the run must fail instead of letting them through.
     */
    public function testUnlistableDescriptorsFailClosedFromTheProcessLevelUp(): void
    {
        $this->requireLevel(IsolationLevel::PROCESS);
        $output = $this->runPhp(
            '-d',
            'open_basedir=' . implode(':', [self::projectDirectory(), sys_get_temp_dir(), '/usr', '/bin', '/dev/null']),
            '-r',
            'require $argv[1]; $directory = ' . WorkingDirectory::class . '::create();'
                . ' foreach (["' . IsolationLevel::NONE . '", "' . IsolationLevel::PROCESS . '"] as $level) {'
                . ' try { $result = (new ' . ProcessRunner::class . '($level))->run(["true"], $directory);'
                . ' echo $level, ": ran ", $result->getExitCode(), "\n"; }'
                . ' catch (\Throwable $e) { echo $level, ": ", get_class($e), ": ", $e->getMessage(), "\n"; } }'
                . ' $directory->remove();',
            self::projectDirectory() . '/vendor/autoload.php'
        );

        self::assertSame([
            IsolationLevel::NONE . ': ran 0',
            IsolationLevel::PROCESS . ': ' . ConversionFailedException::class . ': The open descriptors of this process'
                . ' cannot be listed (neither /proc/self/fd nor /dev/fd is readable, for example because of'
                . ' open_basedir, or scandir() is disabled), so the child could inherit them.',
        ], $output);
    }

    /**
     * Under an open_basedir that allows /proc/self/fd but not /proc/self/fdinfo, the flags of a descriptor cannot be
     * read: it counts as inheritable, so that a file PHP holds open outside open_basedir never reaches the child.
     *
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function testDescriptorsWhoseFlagsCannotBeReadAreNotInherited(string $level): void
    {
        $this->requireLevel($level);
        $output = $this->runPhp(
            '-r',
            'require $argv[1]; $held = fopen($argv[2], "r");'
                . ' ini_set("open_basedir", implode(":", [$argv[3] . "/src", $argv[3] . "/vendor", sys_get_temp_dir(),'
                . ' "/proc/self/fd", "/usr", "/bin", "/dev/null"]));'
                . ' $directory = ' . WorkingDirectory::class . '::create();'
                . ' $result = (new ' . ProcessRunner::class . '($argv[4]))->run(["ls", "-l", "/proc/self/fd"], $directory);'
                . ' $directory->remove(); echo $result->getStdout();',
            self::projectDirectory() . '/vendor/autoload.php',
            __FILE__,
            self::projectDirectory(),
            $level
        );

        self::assertNotSame([], preg_grep('/ 0 -> \/dev\/null$/', $output), implode("\n", $output));
        self::assertSame([], preg_grep('/' . preg_quote(basename(__FILE__), '/') . '/', $output));
    }

    /**
     * Hosting panels disable functions such as scandir() and readlink(): the runner then fails closed with the
     * library's exceptions or does without them, never with an \Error.
     */
    public function testDisabledScandirAndReadlinkFailClosed(): void
    {
        $this->requireLevel(IsolationLevel::PROCESS);
        $output = $this->runPhp(
            '-d',
            'disable_functions=scandir,readlink',
            '-r',
            'require $argv[1]; $directory = ' . WorkingDirectory::class . '::create();'
                . ' foreach (["' . IsolationLevel::NONE . '", "' . IsolationLevel::PROCESS . '"] as $level) {'
                . ' try { $result = (new ' . ProcessRunner::class . '($level))->run(["true"], $directory);'
                . ' echo $level, ": ran ", $result->getExitCode(), "\n"; }'
                . ' catch (\Throwable $e) { echo $level, ": ", get_class($e), "\n"; } }'
                // Without readlink() a linked system path is mounted at its own path instead of being recreated.
                . ' mkdir($directory->pathOf("usr")); symlink($directory->pathOf("usr"), $directory->pathOf("lib"));'
                . ' $options = (new ' . ProcessOptions::class . '())->withSandboxReadOnlyPaths([$directory->pathOf("lib")]);'
                . ' $arguments = (new ' . CommandBuilder::class . '($options))->bubblewrapArguments("/tmp/job", []);'
                . ' echo implode(" ", array_slice($arguments, 5, 3)) === "--ro-bind " . $directory->pathOf("lib") . " "'
                . ' . $directory->pathOf("lib") ? "bound" : "not bound", "\n";'
                . ' unlink($directory->pathOf("lib")); rmdir($directory->pathOf("usr")); var_dump($directory->remove());',
            self::projectDirectory() . '/vendor/autoload.php'
        );

        self::assertSame([
            IsolationLevel::NONE . ': ran 0',
            IsolationLevel::PROCESS . ': ' . ConversionFailedException::class,
            'bound',
            'bool(true)',
        ], $output);
    }

    /**
     * Under an open_basedir that excludes the system directories (Plesk's default), programs cannot be found: the
     * detection and the runner say so with the library's results and exceptions, without warnings that an error
     * handler (as frameworks install) would turn into exceptions.
     */
    public function testAnOpenBasedirThatHidesProgramsRaisesNoWarning(): void
    {
        $output = $this->runPhp(
            '-r',
            'require $argv[1]; error_reporting(E_ALL);'
                . ' set_error_handler(function ($severity, $message) {'
                . ' if ((error_reporting() & $severity) === 0) { return false; } echo "warning: ", $message, "\n"; return true; });'
                . ' ini_set("open_basedir", $argv[2] . ":" . sys_get_temp_dir());'
                . ' echo "detected: ", (new ' . IsolationDetector::class . '(null, null, 0))->detect()->getLevel(), "\n";'
                . ' $directory = ' . WorkingDirectory::class . '::create();'
                . ' try { (new ' . ProcessRunner::class . '("' . IsolationLevel::NONE . '"))->run(["true"], $directory);'
                . ' echo "ran\n"; } catch (\Throwable $e) { echo get_class($e), "\n"; }'
                . ' $directory->remove();',
            self::projectDirectory() . '/vendor/autoload.php',
            self::projectDirectory()
        );

        self::assertSame(['detected: ' . IsolationLevel::NONE, InvalidArgumentException::class], $output);
    }

    /**
     * With php_uname() disabled the architecture is unknown, so every open descriptor is replaced: the run works and
     * throws nothing outside the library's exceptions.
     */
    public function testDisabledPhpUnameStillRuns(): void
    {
        $output = $this->runPhp(
            '-d',
            'disable_functions=php_uname',
            '-r',
            'require $argv[1]; $directory = ' . WorkingDirectory::class . '::create();'
                . ' $result = (new ' . ProcessRunner::class . '("' . IsolationLevel::NONE . '"))'
                . '->run(["sh", "-c", "echo ok"], $directory); $directory->remove(); echo trim($result->getStdout());',
            self::projectDirectory() . '/vendor/autoload.php'
        );

        self::assertSame(['ok'], $output);
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
            'standard input outside the working directory' => [['cat'], __FILE__, []],
            'NUL in the standard input path' => [['cat'], "/nonexistent/input\0", []],
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

    private static function projectDirectory(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * Runs PHP_BINARY with the given arguments in a new process and returns its output lines (stdout and stderr).
     *
     * @return list<string>
     */
    private function runPhp(string ...$arguments): array
    {
        $command = implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY], $arguments)));
        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));

        return $output;
    }
}
