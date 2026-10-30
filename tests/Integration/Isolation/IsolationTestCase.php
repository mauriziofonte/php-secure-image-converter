<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Isolation;

use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\DetectionResult;
use Mfonte\SecureImageConverter\Isolation\IsolationDetector;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Base class of the tests that start real processes. Each test gets a fresh working directory.
 *
 * A test that needs an isolation level the host does not provide is skipped, with the detector's reason, unless the
 * environment variable SIC_EXPECTED_ISOLATION_LEVEL names that level or a stronger one: then it fails, so that the
 * CI job that installs bubblewrap cannot pass by skipping the sandbox tests.
 */
abstract class IsolationTestCase extends TestCase
{
    /**
     * Name of the environment variable holding the level the host is expected to provide.
     */
    public const EXPECTED_LEVEL_VARIABLE = 'SIC_EXPECTED_ISOLATION_LEVEL';

    /**
     * Detection with the default options, shared by all tests of the run.
     *
     * @var DetectionResult|null
     */
    private static $detection;

    /**
     * @var WorkingDirectory|null
     */
    private $workingDirectory;

    /**
     * Files opened by openDescriptors().
     *
     * @var list<resource>
     */
    private $openFiles = [];

    /**
     * Directory handles opened by openDescriptors().
     *
     * @var list<resource>
     */
    private $openDirectories = [];

    protected function set_up(): void
    {
        parent::set_up();
        $this->workingDirectory = WorkingDirectory::create();
    }

    protected function tear_down(): void
    {
        $this->closeDescriptors();
        if ($this->workingDirectory !== null) {
            $this->workingDirectory->remove();
            $this->workingDirectory = null;
        }
        parent::tear_down();
    }

    protected function workingDirectory(): WorkingDirectory
    {
        self::assertNotNull($this->workingDirectory);

        return $this->workingDirectory;
    }

    /**
     * Opens $count descriptors in this PHP process until closeDescriptors() or the end of the test: files, which a
     * child process would inherit, or directory handles, which PHP opens with close-on-exec.
     */
    protected function openDescriptors(int $count, bool $inheritable): void
    {
        for ($index = 0; $index < $count; ++$index) {
            if ($inheritable) {
                $file = fopen('/dev/null', 'r');
                self::assertIsResource($file);
                $this->openFiles[] = $file;
            } else {
                $directory = opendir('/');
                self::assertIsResource($directory);
                $this->openDirectories[] = $directory;
            }
        }
    }

    protected function closeDescriptors(): void
    {
        foreach ($this->openFiles as $file) {
            fclose($file);
        }
        foreach ($this->openDirectories as $directory) {
            closedir($directory);
        }
        $this->openFiles = [];
        $this->openDirectories = [];
    }

    /**
     * Skips the test unless this PHP process may open at least $count descriptors.
     */
    protected static function requireOpenFilesLimit(int $count): void
    {
        $limits = function_exists('posix_getrlimit') ? posix_getrlimit() : false;
        $limit = is_array($limits) ? ($limits['soft openfiles'] ?? null) : null;
        if ($limit !== 'unlimited' && (!is_numeric($limit) || (int) $limit < $count)) {
            self::markTestSkipped(sprintf(
                'This test needs a soft open files limit of at least %d (ulimit -n).',
                $count
            ));
        }
    }

    /**
     * Processes that are running (not zombies) and have $argument among their command line arguments.
     *
     * @return list<int>
     */
    protected static function processesWithArgument(string $argument): array
    {
        $pids = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
            $commandLine = @file_get_contents($path);
            $pid = (int) basename(dirname($path));
            if (
                is_string($commandLine)
                && in_array($argument, explode("\0", $commandLine), true)
                && self::isRunning($pid)
            ) {
                $pids[] = $pid;
            }
        }

        return $pids;
    }

    /**
     * What the detector finds on this host with the default options (probed once, without the file cache).
     */
    protected static function hostDetection(): DetectionResult
    {
        if (self::$detection === null) {
            self::$detection = (new IsolationDetector(new ProcessOptions(), null, 0))->detect();
        }

        return self::$detection;
    }

    /**
     * The level SIC_EXPECTED_ISOLATION_LEVEL names, or null when it is not set.
     */
    protected static function expectedLevel(): ?string
    {
        $level = getenv(self::EXPECTED_LEVEL_VARIABLE);
        if (!is_string($level) || $level === '') {
            return null;
        }
        self::assertTrue(IsolationLevel::isValid($level), self::EXPECTED_LEVEL_VARIABLE . ' must name an isolation level.');

        return $level;
    }

    /**
     * Skips the test when the host does not provide $level, or fails it when the environment expects the level.
     */
    protected function requireLevel(string $level): void
    {
        $detection = self::hostDetection();
        if (IsolationLevel::isAtLeast($detection->getLevel(), $level)) {
            return;
        }
        $message = sprintf(
            'The %s isolation level is not available on this host (detected: %s): %s.',
            $level,
            $detection->getLevel(),
            $detection->getReasons()[$level] ?? 'unknown reason'
        );
        $expected = self::expectedLevel();
        if ($expected !== null && IsolationLevel::isAtLeast($expected, $level)) {
            self::fail($message . ' ' . self::EXPECTED_LEVEL_VARIABLE . ' requires it.');
        }
        self::markTestSkipped($message);
    }

    /**
     * Levels from process up to rlimit, for tests that must hold at every level that uses a process group.
     *
     * @return array<string, array{string}>
     */
    public static function groupLevels(): array
    {
        return [
            'process' => [IsolationLevel::PROCESS],
            'rlimit' => [IsolationLevel::RLIMIT],
        ];
    }

    /**
     * Whether a process exists and is not a zombie (a zombie has ended and only waits to be reaped by its parent,
     * which in a container can be a PID 1 that never reaps).
     */
    protected static function isRunning(int $pid): bool
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if (!is_string($stat)) {
            return false;
        }
        $afterName = strrchr($stat, ')');

        return is_string($afterName) && preg_match('/\A\) [ZX]/', $afterName) !== 1;
    }

    /**
     * Waits up to $timeout seconds for a process to end.
     */
    protected static function waitUntilEnded(int $pid, float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (self::isRunning($pid)) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(20000);
        }

        return true;
    }

    /**
     * Process IDs printed one per line by a test command.
     *
     * @return list<int>
     */
    protected static function pids(string $output): array
    {
        $pids = [];
        foreach (preg_split('/\s+/', trim($output)) ?: [] as $token) {
            if (preg_match('/\A[1-9][0-9]*\z/', $token) === 1) {
                $pids[] = (int) $token;
            }
        }

        return $pids;
    }
}
