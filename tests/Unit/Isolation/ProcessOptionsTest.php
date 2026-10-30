<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Isolation\ProcessOptions
 */
#[CoversClass(ProcessOptions::class)]
final class ProcessOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = new ProcessOptions();

        self::assertSame(30.0, $options->getTimeoutSeconds());
        self::assertSame(1.0, $options->getKillGraceSeconds());
        self::assertSame(1048576, $options->getMaxOutputBytes());
        self::assertEquals(new ResourceLimits(), $options->getResourceLimits());
        self::assertSame(['LANG' => 'C', 'LC_ALL' => 'C'], $options->getEnvironment());
        self::assertSame([], $options->getInheritedEnvironment());
        self::assertSame(
            ['/usr/local/bin', '/usr/bin', '/bin', '/usr/local/sbin', '/usr/sbin', '/sbin'],
            $options->getSearchPath()
        );
        self::assertSame(ProcessOptions::DEFAULT_SANDBOX_READ_ONLY_PATHS, $options->getSandboxReadOnlyPaths());
        self::assertSame([], $options->getToolPaths());
    }

    public function testTheSandboxShowsNoUserOrSecretDataByDefault(): void
    {
        foreach (ProcessOptions::DEFAULT_SANDBOX_READ_ONLY_PATHS as $path) {
            self::assertMatchesRegularExpression(
                '#\A/(usr|bin|sbin|lib|lib32|lib64|libx32|etc/alternatives|etc/ld\.so\.(cache|conf|conf\.d))\z#',
                $path
            );
        }
    }

    public function testWithersReturnModifiedCopies(): void
    {
        $defaults = new ProcessOptions();
        $limits = (new ResourceLimits())->withCpuSeconds(3);
        $options = $defaults
            ->withTimeoutSeconds(2.5)
            ->withKillGraceSeconds(0.0)
            ->withMaxOutputBytes(0)
            ->withResourceLimits($limits)
            ->withEnvironment(['VIPS_CONCURRENCY' => '1', 'lower_case' => ''])
            ->withInheritedEnvironment(['TZ', 'TZ', 'MAGICK_HOME'])
            ->withSearchPath(['/opt/tools/bin', '/usr/bin'])
            ->withSandboxReadOnlyPaths(['/usr', '/opt/tools'])
            ->withToolPath(ProcessOptions::TOOL_PRLIMIT, '/opt/tools/bin/prlimit')
            ->withToolPath(ProcessOptions::TOOL_BWRAP, null);

        self::assertSame(2.5, $options->getTimeoutSeconds());
        self::assertSame(0.0, $options->getKillGraceSeconds());
        self::assertSame(0, $options->getMaxOutputBytes());
        self::assertSame($limits, $options->getResourceLimits());
        self::assertSame(['VIPS_CONCURRENCY' => '1', 'lower_case' => ''], $options->getEnvironment());
        self::assertSame(['TZ', 'MAGICK_HOME'], $options->getInheritedEnvironment());
        self::assertSame(['/opt/tools/bin', '/usr/bin'], $options->getSearchPath());
        self::assertSame(['/usr', '/opt/tools'], $options->getSandboxReadOnlyPaths());
        self::assertSame(['bwrap' => null, 'prlimit' => '/opt/tools/bin/prlimit'], $options->getToolPaths());
        self::assertSame([], $options->withSandboxReadOnlyPaths([])->getSandboxReadOnlyPaths());

        self::assertEquals(new ProcessOptions(), $defaults, 'the original is unchanged');
    }

    public function testIsolationArrayFingerprintsWhatChangesIsolation(): void
    {
        $options = new ProcessOptions();
        $fingerprint = $options->toIsolationArray();

        self::assertSame(['resource_limits', 'search_path', 'sandbox_read_only_paths', 'tool_paths'], array_keys($fingerprint));
        self::assertSame($fingerprint, $options->withTimeoutSeconds(1.0)->withEnvironment([])->toIsolationArray());
        self::assertNotSame($fingerprint, $options->withToolPath(ProcessOptions::TOOL_BWRAP, null)->toIsolationArray());
        self::assertNotSame(
            $fingerprint,
            $options->withResourceLimits((new ResourceLimits())->withOpenFiles(64))->toIsolationArray()
        );
    }

    public function testLocateToolHonoursExplicitPathsAndDisabledTools(): void
    {
        $finder = new ExecutableFinder(['/usr/bin', '/bin']);
        $shell = $finder->find('sh');
        self::assertIsString($shell);
        $options = new ProcessOptions();

        self::assertNull($options->withToolPath(ProcessOptions::TOOL_SETSID, null)->locateTool('setsid', $finder));
        self::assertSame($shell, $options->withToolPath(ProcessOptions::TOOL_SETSID, $shell)->locateTool('setsid', $finder));
        self::assertNull(
            $options->withToolPath(ProcessOptions::TOOL_BWRAP, '/nonexistent/bwrap')->locateTool('bwrap', $finder),
            'an explicit path that is not executable is not available'
        );
        self::assertSame($finder->find('setsid'), $options->locateTool('setsid', $finder));
    }

    /**
     * @return array<string, array{callable(ProcessOptions): ProcessOptions}>
     */
    public static function invalidOptions(): array
    {
        return [
            'zero timeout' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withTimeoutSeconds(0.0);
            }],
            'negative timeout' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withTimeoutSeconds(-1.0);
            }],
            'NaN timeout' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withTimeoutSeconds(NAN);
            }],
            'timeout above one day' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withTimeoutSeconds(86400.5);
            }],
            'negative grace' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withKillGraceSeconds(-0.1);
            }],
            'grace above a minute' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withKillGraceSeconds(60.5);
            }],
            'negative output limit' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withMaxOutputBytes(-1);
            }],
            'output limit above 256 MiB' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withMaxOutputBytes(ProcessOptions::MAX_OUTPUT_BYTES + 1);
            }],
            'reserved PATH' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withEnvironment(['PATH' => '/tmp']);
            }],
            'reserved HOME' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withEnvironment(['home' => '/tmp']);
            }],
            'reserved TMPDIR inherited' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withInheritedEnvironment(['TMPDIR']);
            }],
            'reserved PWD' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withEnvironment(['PWD' => '/']);
            }],
            'variable name with "="' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withEnvironment(['A=B' => 'c']);
            }],
            'variable name starting with a digit' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withEnvironment(['1A' => 'c']);
            }],
            'numeric variable name' => [static function (ProcessOptions $options): ProcessOptions {
                // @phpstan-ignore argument.type (a wrong type on purpose: the runtime check is under test)
                return $options->withEnvironment(['value']);
            }],
            'NUL in a value' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withEnvironment(['A' => "b\0c"]);
            }],
            'non-string value' => [static function (ProcessOptions $options): ProcessOptions {
                // @phpstan-ignore argument.type (a wrong type on purpose: the runtime check is under test)
                return $options->withEnvironment(['A' => 1]);
            }],
            'empty inherited name' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withInheritedEnvironment(['']);
            }],
            'non-string inherited name' => [static function (ProcessOptions $options): ProcessOptions {
                // @phpstan-ignore argument.type (a wrong type on purpose: the runtime check is under test)
                return $options->withInheritedEnvironment([42]);
            }],
            'empty search path' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withSearchPath([]);
            }],
            'relative search directory' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withSearchPath(['bin']);
            }],
            'search directory with ".."' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withSearchPath(['/usr/../bin']);
            }],
            'root as sandbox path' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withSandboxReadOnlyPaths(['/']);
            }],
            'sandbox path with trailing slash' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withSandboxReadOnlyPaths(['/usr/']);
            }],
            'sandbox path with NUL' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withSandboxReadOnlyPaths(["/usr\0"]);
            }],
            'unknown tool' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withToolPath('sudo', '/usr/bin/sudo');
            }],
            'relative tool path' => [static function (ProcessOptions $options): ProcessOptions {
                return $options->withToolPath(ProcessOptions::TOOL_BWRAP, 'bwrap');
            }],
        ];
    }

    /**
     * @dataProvider invalidOptions
     *
     * @param callable(ProcessOptions): ProcessOptions $change
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRejected(callable $change): void
    {
        $this->expectException(InvalidArgumentException::class);

        $change(new ProcessOptions());
    }
}
