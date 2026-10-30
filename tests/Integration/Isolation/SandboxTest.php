<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\CommandBuilder;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessRunner;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the bubblewrap sandbox: no network, read-only system, no host data, host configuration below /usr hidden,
 * an empty read-only /tmp, only the working directory writable (no file can be stored in memory; memfd and System V
 * shared memory are bounded only by a cgroup limit, see CommandBuilder), no capabilities, resource limits inside,
 * and every process of the sandbox killed with it.
 *
 * Skipped where bubblewrap cannot run (for example in a container with Docker's default seccomp profile); the CI job
 * "isolation" installs bubblewrap and sets SIC_EXPECTED_ISOLATION_LEVEL=sandbox, so that there these tests run or
 * fail, never skip.
 *
 * @covers \Mfonte\SecureImageConverter\Isolation\CommandBuilder
 * @covers \Mfonte\SecureImageConverter\Isolation\ProcessRunner
 *
 * @group isolation
 * @group sandbox
 */
#[CoversClass(ProcessRunner::class)]
#[CoversClass(CommandBuilder::class)]
#[Group('isolation')]
#[Group('sandbox')]
final class SandboxTest extends IsolationTestCase
{
    /**
     * @var WorkingDirectory|null
     */
    private $hostDirectory;

    protected function tear_down(): void
    {
        if ($this->hostDirectory !== null) {
            $this->hostDirectory->remove();
        }
        parent::tear_down();
    }

    private function runner(?ProcessOptions $options = null): ProcessRunner
    {
        $this->requireLevel(IsolationLevel::SANDBOX);

        return new ProcessRunner(IsolationLevel::SANDBOX, $options);
    }

    public function testRunsACommand(): void
    {
        $result = $this->runner()->run(['sh', '-c', 'echo inside; exit 3'], $this->workingDirectory());

        self::assertSame(3, $result->getExitCode());
        self::assertSame("inside\n", $result->getStdout());
        self::assertSame(IsolationLevel::SANDBOX, $result->getIsolationLevel());
    }

    public function testHasNoNetwork(): void
    {
        $result = $this->runner()->run(['cat', '/proc/net/dev'], $this->workingDirectory());

        self::assertTrue($result->isSuccessful());
        $interfaces = [];
        foreach (explode("\n", $result->getStdout()) as $line) {
            if (preg_match('/\A\s*([^\s:|]+):/', $line, $match) === 1) {
                $interfaces[] = $match[1];
            }
        }
        self::assertSame(['lo'], $interfaces, 'a new network namespace has only a loopback interface');
    }

    public function testTheSystemIsMountedReadOnly(): void
    {
        $result = $this->runner()->run(['cat', '/proc/self/mountinfo'], $this->workingDirectory());

        self::assertTrue($result->isSuccessful());
        $mounts = [];
        foreach (explode("\n", trim($result->getStdout())) as $line) {
            $fields = explode(' ', $line);
            if (count($fields) > 5) {
                $mounts[$fields[4]] = explode(',', $fields[5]);
            }
        }
        self::assertArrayHasKey('/usr', $mounts);
        self::assertContains('ro', $mounts['/usr']);
        $workingDirectory = $this->workingDirectory()->getPath();
        self::assertArrayHasKey($workingDirectory, $mounts);
        self::assertContains('rw', $mounts[$workingDirectory]);
        foreach (['/', '/tmp', '/dev'] as $mountPoint) {
            self::assertArrayHasKey($mountPoint, $mounts);
        }
        foreach ($mounts as $mountPoint => $flags) {
            // Besides the working directory, only /proc and the device nodes and /dev/pts below /dev may be
            // writable; the sandbox's own root, /tmp and /dev itself are read-only file systems in memory.
            $writable = $mountPoint === $workingDirectory
                || preg_match('#\A/(proc(/|\z)|dev/.)#', (string) $mountPoint) === 1;
            if (!$writable) {
                self::assertContains('ro', $flags, sprintf('%s must be read-only', $mountPoint));
            }
        }
    }

    public function testHostDataIsInvisibleAndOnlyTheWorkingDirectoryIsWritable(): void
    {
        $this->hostDirectory = WorkingDirectory::create();
        $secret = $this->hostDirectory->pathOf('secret.txt');
        file_put_contents($secret, 'host secret');
        $planted = $this->hostDirectory->pathOf('planted.txt');
        // Host paths that must not exist in the sandbox; bubblewrap creates the parents of the working directory,
        // so a parent of it (for example /home when TMPDIR is below it) is left out.
        $hostPaths = array_values(array_filter(
            ['/home', '/root', '/var', '/srv', '/etc/passwd', '/etc/shadow', '/etc/ssl'],
            function (string $path): bool {
                return strpos($this->workingDirectory()->getPath() . '/', $path . '/') !== 0;
            }
        ));
        $script = 'cat "$1" 2>/dev/null; echo x > "$2" 2>/dev/null; echo ok > result.txt; shift 2; '
            . 'for path in "$@"; do test -e "$path" && echo "visible $path"; done; echo done';

        $result = $this->runner()->run(
            array_merge(['sh', '-c', $script, 'sh', $secret, $planted], $hostPaths),
            $this->workingDirectory()
        );

        self::assertSame("done\n", $result->getStdout());
        self::assertFileDoesNotExist($planted);
        self::assertStringEqualsFile($this->workingDirectory()->pathOf('result.txt'), "ok\n");
    }

    public function testTmpIsPrivateEmptyAndReadOnly(): void
    {
        $name = 'sic-host-' . bin2hex(random_bytes(8));
        file_put_contents('/tmp/' . $name, 'host file');
        try {
            $result = $this->runner()->run(['ls', '-A', '/tmp'], $this->workingDirectory());
        } finally {
            unlink('/tmp/' . $name);
        }

        self::assertTrue($result->isSuccessful());
        self::assertStringNotContainsString($name, $result->getStdout());
        // Only the mount point of the working directory may show, when it lies below /tmp.
        foreach (preg_split('/\n/', trim($result->getStdout()), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            self::assertStringStartsWith(
                '/tmp/' . $entry . '/',
                $this->workingDirectory()->getPath() . '/',
                sprintf('/tmp/%s is not a parent of the working directory', $entry)
            );
        }
    }

    /**
     * No path outside the working directory accepts a file: the file systems in memory are read-only, so no file can
     * be stored in memory (memory held without a file system, such as memfd and System V shared memory, needs a
     * cgroup limit, see CommandBuilder).
     */
    public function testNothingCanBeStoredOutsideTheWorkingDirectory(): void
    {
        $script = 'for path in "$@"; do echo x > "$path" 2>/dev/null && echo "stored $path"; done; '
            . 'mkdir /tmp/new-directory 2>/dev/null && echo "created a directory"; echo x > stored.txt && echo done';
        $paths = ['/file', '/tmp/file', '/dev/file', '/dev/shm/file', '/usr/file'];
        foreach (ProcessOptions::DEFAULT_SANDBOX_HIDDEN_PATHS as $hidden) {
            $paths[] = $hidden . '/file';
        }

        $result = $this->runner()->run(array_merge(['sh', '-c', $script, 'sh'], $paths), $this->workingDirectory());

        self::assertSame("done\n", $result->getStdout());
        self::assertStringEqualsFile($this->workingDirectory()->pathOf('stored.txt'), "x\n");
    }

    /**
     * Hidden directories are empty and read-only in the sandbox, even below a path mounted read-only.
     */
    public function testHiddenDirectoriesAreEmpty(): void
    {
        $this->hostDirectory = WorkingDirectory::create();
        $public = $this->hostDirectory->pathOf('public.txt');
        file_put_contents($public, 'public');
        $configuration = $this->hostDirectory->pathOf('etc');
        mkdir($configuration, 0700);
        file_put_contents($configuration . '/secret.conf', 'env[DB_PASSWORD] = secret');
        $hidden = [$configuration];
        foreach (ProcessOptions::DEFAULT_SANDBOX_HIDDEN_PATHS as $path) {
            if (is_dir($path)) {
                $hidden[] = $path;
            }
        }
        $options = (new ProcessOptions())->withSandboxHiddenPaths(
            array_merge(ProcessOptions::DEFAULT_SANDBOX_HIDDEN_PATHS, [$configuration])
        );

        $script = 'cat "$0"; echo; for path in "$@"; do echo "$path: $(ls -A "$path" | wc -l)"; done';
        $result = $this->runner($options)->run(
            array_merge(['sh', '-c', $script, $public], $hidden),
            $this->workingDirectory(),
            null,
            [$this->hostDirectory->getPath()]
        );

        self::assertTrue($result->isSuccessful(), $result->getStderr());
        $expected = "public\n";
        foreach ($hidden as $path) {
            $expected .= $path . ": 0\n";
        }
        self::assertSame($expected, $result->getStdout());
    }

    public function testReadOnlyInputsAreReadableButNotWritable(): void
    {
        $this->hostDirectory = WorkingDirectory::create();
        $input = $this->hostDirectory->pathOf('input.png');
        file_put_contents($input, 'image bytes');

        $result = $this->runner()->run(
            ['sh', '-c', 'cat "$1"; echo changed >> "$1" 2>/dev/null || echo " read-only"', 'sh', $input],
            $this->workingDirectory(),
            null,
            [$input]
        );

        self::assertSame("image bytes read-only\n", $result->getStdout());
        self::assertStringEqualsFile($input, 'image bytes');
    }

    /**
     * The standard input reaches the sandboxed program as descriptor 0; a run that replaces it with a link to a host
     * file (created in the shared working directory, though the host file is invisible inside) gets the next run
     * refused, as at the lower levels.
     */
    public function testStandardInputReachesTheProgramAndAPlantedLinkIsRefused(): void
    {
        $runner = $this->runner();
        $this->hostDirectory = WorkingDirectory::create();
        $host = $this->hostDirectory->pathOf('host.txt');
        file_put_contents($host, 'host data');
        $input = $this->workingDirectory()->pathOf('input.txt');
        file_put_contents($input, "line 1\n\0binary");

        $result = $runner->run(['sh', '-c', 'cat; readlink /proc/self/fd/0'], $this->workingDirectory(), $input);
        self::assertSame("line 1\n\0binary" . $input . "\n", $result->getStdout());

        $replace = $runner->run(
            ['sh', '-c', 'rm -f input.txt && ln -s "$1" input.txt && echo replaced', 'sh', $host],
            $this->workingDirectory()
        );
        self::assertSame("replaced\n", $replace->getStdout());
        try {
            $runner->run(['sh', '-c', 'cat; echo pwned > /proc/self/fd/0'], $this->workingDirectory(), $input);
            self::fail('The link must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'The standard input must be a readable regular file directly inside the working directory, not a '
                    . 'symbolic link, without other hard links.',
                $exception->getMessage()
            );
        }
        self::assertStringEqualsFile($host, 'host data');
    }

    public function testCapabilitiesAreDropped(): void
    {
        $result = $this->runner()->run(['cat', '/proc/self/status'], $this->workingDirectory());

        self::assertTrue($result->isSuccessful());
        // Without permitted or effective capabilities, and with no_new_privs set by bubblewrap, the bounding set
        // cannot be used to regain any.
        self::assertMatchesRegularExpression('/^NoNewPrivs:\s+1$/m', $result->getStdout());
        foreach (['CapInh', 'CapPrm', 'CapEff', 'CapAmb'] as $set) {
            self::assertMatchesRegularExpression('/^' . $set . ':\s+0+$/m', $result->getStdout(), $set . ' is empty');
        }
    }

    public function testTheEnvironmentIsSanitised(): void
    {
        putenv('SIC_TEST_SECRET=database-password');
        try {
            $result = $this->runner()->run(['env'], $this->workingDirectory());
        } finally {
            putenv('SIC_TEST_SECRET');
        }

        $names = [];
        foreach (explode("\n", trim($result->getStdout())) as $line) {
            $names[] = explode('=', $line, 2)[0];
        }
        sort($names);
        self::assertSame(['HOME', 'LANG', 'LC_ALL', 'PATH', 'PWD', 'TMPDIR'], $names);
    }

    public function testResourceLimitsApplyInsideTheSandbox(): void
    {
        $limits = (new ResourceLimits())->withCpuSeconds(17)->withOpenFiles(64);
        $result = $this->runner((new ProcessOptions())->withResourceLimits($limits))->run(
            ['cat', '/proc/self/limits'],
            $this->workingDirectory()
        );

        self::assertMatchesRegularExpression('/^Max cpu time\s+17\s+17\s/m', $result->getStdout());
        self::assertMatchesRegularExpression('/^Max open files\s+64\s+64\s/m', $result->getStdout());
        self::assertMatchesRegularExpression('/^Max core file size\s+0\s+0\s/m', $result->getStdout());
    }

    public function testMemoryCapStopsARunawayAllocationInsideTheSandbox(): void
    {
        $limits = (new ResourceLimits())->withAddressSpaceBytes(256 * 1024 * 1024);
        $script = '$blocks = []; for ($i = 0; $i < 1024; ++$i) { $blocks[] = str_repeat("x", 1048576); } echo "survived";';
        $result = $this->runner((new ProcessOptions())->withResourceLimits($limits))->execute(
            [PHP_BINARY, '-n', '-d', 'memory_limit=-1', '-r', $script],
            $this->workingDirectory()
        );

        self::assertFalse($result->isSuccessful());
        self::assertFalse($result->isTimedOut());
        self::assertStringNotContainsString('survived', $result->getStdout());
    }

    /**
     * A descendant that leaves the process group with setsid() escapes the group kill of the lower levels; in the
     * sandbox it is still in the PID namespace, which dies with the run.
     */
    public function testTimeoutKillsEveryProcessOfTheSandbox(): void
    {
        $options = (new ProcessOptions())->withTimeoutSeconds(0.5);
        $script = 'setsid sh -c "sleep 2; echo escaped > escaped.txt" </dev/null >/dev/null 2>&1 & sleep 30';
        $result = $this->runner($options)->execute(['sh', '-c', $script], $this->workingDirectory());

        self::assertTrue($result->isTimedOut());
        self::assertLessThan(3.0, $result->getDurationSeconds());
        usleep(2500000);
        self::assertFileDoesNotExist($this->workingDirectory()->pathOf('escaped.txt'));
    }

    public function testBackgroundProcessesDoNotOutliveASuccessfulRun(): void
    {
        $script = 'setsid sh -c "sleep 2; echo escaped > escaped.txt" </dev/null >/dev/null 2>&1 & echo started';
        $result = $this->runner()->run(['sh', '-c', $script], $this->workingDirectory());

        self::assertSame("started\n", $result->getStdout());
        usleep(2500000);
        self::assertFileDoesNotExist($this->workingDirectory()->pathOf('escaped.txt'));
    }
}
