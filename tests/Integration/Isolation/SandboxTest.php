<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Isolation;

use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\CommandBuilder;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessRunner;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the bubblewrap sandbox: no network, read-only system, no host data, private /tmp, only the working
 * directory writable, no capabilities, resource limits inside, and every process of the sandbox killed with it.
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
        foreach ($mounts as $mountPoint => $flags) {
            // The sandbox's own root, /tmp, /proc and /dev are private to it; the working directory is the only
            // writable host path.
            $private = in_array($mountPoint, [$workingDirectory, '/', '/tmp'], true)
                || preg_match('#\A/(proc|dev)(/|\z)#', (string) $mountPoint) === 1;
            if (!$private) {
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

    public function testTmpIsPrivate(): void
    {
        $name = 'sic-private-' . bin2hex(random_bytes(8));
        $result = $this->runner()->run(['sh', '-c', 'echo x > /tmp/' . $name . ' && ls /tmp'], $this->workingDirectory());

        self::assertTrue($result->isSuccessful(), 'the sandbox has a writable /tmp of its own');
        self::assertStringContainsString($name, $result->getStdout());
        self::assertFileDoesNotExist(sys_get_temp_dir() . '/' . $name);
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
