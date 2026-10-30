<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Isolation;

use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\CommandBuilder;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Isolation\CommandBuilder
 */
#[CoversClass(CommandBuilder::class)]
final class CommandBuilderTest extends TestCase
{
    private const TOOLS = [
        ProcessOptions::TOOL_SETSID => '/usr/bin/setsid',
        ProcessOptions::TOOL_PRLIMIT => '/usr/bin/prlimit',
        ProcessOptions::TOOL_BWRAP => '/usr/bin/bwrap',
    ];

    private const COMMAND = ['/usr/bin/cwebp', '-q', '80', 'in put.png', '-o', '$(out).webp'];

    /**
     * @var WorkingDirectory|null
     */
    private $directory;

    protected function tear_down(): void
    {
        if ($this->directory !== null) {
            $this->directory->remove();
        }
        parent::tear_down();
    }

    private function options(): ProcessOptions
    {
        return (new ProcessOptions())
            ->withSandboxReadOnlyPaths([])
            ->withResourceLimits((new ResourceLimits())->withAddressSpaceBytes(536870912)->withProcesses(32));
    }

    public function testNoneRunsTheCommandAsItIs(): void
    {
        $builder = new CommandBuilder($this->options());

        self::assertSame(self::COMMAND, $builder->build(IsolationLevel::NONE, self::COMMAND, [], '/tmp/job', []));
    }

    public function testProcessWrapsTheCommandInSetsid(): void
    {
        $builder = new CommandBuilder($this->options());

        self::assertSame(
            array_merge(['/usr/bin/setsid', '--'], self::COMMAND),
            $builder->build(IsolationLevel::PROCESS, self::COMMAND, self::TOOLS, '/tmp/job', [])
        );
    }

    public function testRlimitAddsPrlimitWithEveryLimit(): void
    {
        $builder = new CommandBuilder($this->options());

        self::assertSame(
            array_merge(
                ['/usr/bin/setsid', '--', '/usr/bin/prlimit'],
                ['--as=536870912', '--cpu=60', '--fsize=268435456', '--nofile=128', '--core=0', '--nproc=32', '--'],
                self::COMMAND
            ),
            $builder->build(IsolationLevel::RLIMIT, self::COMMAND, self::TOOLS, '/tmp/job', [])
        );
    }

    public function testSandboxRunsBubblewrapInsidePrlimit(): void
    {
        $builder = new CommandBuilder($this->options());
        $arguments = $builder->build(IsolationLevel::SANDBOX, self::COMMAND, self::TOOLS, '/tmp/job', ['/srv/in.png']);

        self::assertSame(['/usr/bin/setsid', '--', '/usr/bin/prlimit'], array_slice($arguments, 0, 3));
        $bwrap = array_search('/usr/bin/bwrap', $arguments, true);
        self::assertSame(10, $bwrap, 'bubblewrap runs under the resource limits');
        self::assertSame('--', $arguments[9]);
        self::assertSame(
            array_merge(
                $builder->bubblewrapArguments('/tmp/job', ['/srv/in.png']),
                ['--'],
                self::COMMAND
            ),
            array_slice($arguments, 11)
        );
    }

    public function testBubblewrapIsolatesEverything(): void
    {
        $builder = new CommandBuilder($this->options());

        self::assertSame(
            [
                '--unshare-all', '--die-with-parent', '--new-session', '--cap-drop', 'ALL',
                '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp',
                '--ro-bind', '/srv/in.png', '/srv/in.png',
                '--bind', '/tmp/job', '/tmp/job', '--chdir', '/tmp/job',
            ],
            $builder->bubblewrapArguments('/tmp/job', ['/srv/in.png'])
        );
    }

    public function testSystemPathsAreMountedReadOnlyAndLinksAreRecreated(): void
    {
        $this->directory = WorkingDirectory::create();
        $root = $this->directory->getPath();
        mkdir($root . '/usr', 0700);
        file_put_contents($root . '/config', '');
        symlink('usr', $root . '/bin');
        $builder = new CommandBuilder(
            (new ProcessOptions())->withSandboxReadOnlyPaths([$root . '/usr', $root . '/bin', $root . '/config', $root . '/missing'])
        );

        self::assertSame(
            [
                '--unshare-all', '--die-with-parent', '--new-session', '--cap-drop', 'ALL',
                '--ro-bind', $root . '/usr', $root . '/usr',
                '--symlink', 'usr', $root . '/bin',
                '--ro-bind', $root . '/config', $root . '/config',
                '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp',
                '--bind', '/tmp/job', '/tmp/job', '--chdir', '/tmp/job',
            ],
            $builder->bubblewrapArguments('/tmp/job', [])
        );
    }

    public function testEnvironmentIsBuiltFromScratch(): void
    {
        putenv('SIC_TEST_INHERITED=inherited value');
        putenv('SIC_TEST_SECRET=must not leak');
        try {
            $options = (new ProcessOptions())
                ->withSearchPath(['/opt/bin', '/usr/bin'])
                ->withEnvironment(['LANG' => 'C.UTF-8', 'SIC_TEST_INHERITED' => 'explicit wins'])
                ->withInheritedEnvironment(['SIC_TEST_INHERITED', 'SIC_TEST_UNSET']);
            $environment = (new CommandBuilder($options))->environment('/tmp/job');
        } finally {
            putenv('SIC_TEST_INHERITED');
            putenv('SIC_TEST_SECRET');
        }

        self::assertSame([
            'SIC_TEST_INHERITED' => 'explicit wins',
            'LANG' => 'C.UTF-8',
            'PATH' => '/opt/bin:/usr/bin',
            'HOME' => '/tmp/job',
            'TMPDIR' => '/tmp/job',
            'PWD' => '/tmp/job',
        ], $environment);
    }

    public function testInheritedVariablesAreCopiedWhenSet(): void
    {
        putenv('SIC_TEST_INHERITED=inherited value');
        try {
            $options = (new ProcessOptions())->withInheritedEnvironment(['SIC_TEST_INHERITED']);
            $environment = (new CommandBuilder($options))->environment('/tmp/job');
        } finally {
            putenv('SIC_TEST_INHERITED');
        }

        self::assertSame('inherited value', $environment['SIC_TEST_INHERITED']);
        self::assertSame(['SIC_TEST_INHERITED', 'LANG', 'LC_ALL', 'PATH', 'HOME', 'TMPDIR', 'PWD'], array_keys($environment));
    }

    public function testShellCommandQuotesEveryArgumentAndExecs(): void
    {
        self::assertSame(
            "exec '/usr/bin/cwebp' '-q' '80' 'in put.png' '-o' '\$(out).webp' 'it'\\''s' ''",
            CommandBuilder::toShellCommand(array_merge(self::COMMAND, ["it's", '']))
        );
    }
}
