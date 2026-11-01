<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Backend\AbstractWorkerBackend;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Backend\GdBackend;
use Mfonte\SecureImageConverter\Backend\ImagickBackend;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Tests\Unit\Backend\Fake\FakeProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The GD and Imagick backends: their PHP command line and what their discovery runs learn, with a runner that
 * starts nothing.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\AbstractWorkerBackend
 * @covers \Mfonte\SecureImageConverter\Backend\GdBackend
 * @covers \Mfonte\SecureImageConverter\Backend\ImagickBackend
 */
#[CoversClass(AbstractWorkerBackend::class)]
#[CoversClass(GdBackend::class)]
#[CoversClass(ImagickBackend::class)]
final class WorkerBackendTest extends TestCase
{
    use BackendFixtures;
    use FakePrograms;

    protected function tear_down(): void
    {
        $this->removeFakePrograms();
        parent::tear_down();
    }

    private function program(string $name): string
    {
        return $this->fakePrograms() . '/' . $name;
    }

    private function runner(): FakeProcessRunner
    {
        return new FakeProcessRunner(null, $this->programOptions());
    }

    public function testWorkersRunPhpWithoutIni(): void
    {
        $options = (new BackendOptions())->withPhpBinary($this->program('php'));
        $gd = new GdBackend($this->runner(), $options);
        $imagick = new ImagickBackend($this->runner(), $options);
        $job = self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP);
        $workers = (string) realpath(__DIR__ . '/../../../resources/workers');

        self::assertSame(
            [$this->program('php'), '-n', '-d', 'extension=gd', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'memory_limit=-1', $workers . '/gd.php', 'convert'],
            $gd->commandLine($job, ['load' => '1'])
        );
        self::assertSame(
            [$this->program('php'), '-n', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'memory_limit=-1', $workers . '/imagick.php', 'convert'],
            $imagick->commandLine($job, ['load' => '0'])
        );
        $classes = (string) realpath(__DIR__ . '/../../../src/Backend/Worker');
        self::assertSame([$workers, $classes, ImagickBackend::policyDirectory()], $imagick->getSandboxResourcePaths());
        self::assertSame([$workers, $classes], $gd->getSandboxResourcePaths());
        foreach ([$workers, $classes, ImagickBackend::policyDirectory()] as $resource) {
            self::assertNotContains($resource, $imagick->getSandboxPaths(), 'the library files are mounted per run, after /tmp');
        }
        self::assertContains('/usr/share/zoneinfo', $gd->getSandboxPaths());
        self::assertFileExists(ImagickBackend::policyDirectory() . '/policy.xml');
    }

    /**
     * The policy caps ImageMagick's memory and map limits at the largest memory ceiling, so that the limits the
     * worker sets to the ceiling for each job follow it instead of being capped below it.
     */
    public function testTheImagickPolicyLetsTheMemoryLimitsFollowTheCeiling(): void
    {
        $policy = file_get_contents(ImagickBackend::policyDirectory() . '/policy.xml');
        self::assertIsString($policy);
        self::assertGreaterThan(0, preg_match_all('/<policy domain="resource" name="([a-z-]+)" value="([^"]*)"\/>/', $policy, $matches));
        $limits = array_combine($matches[1], $matches[2]);

        foreach (['memory', 'map'] as $name) {
            self::assertSame(1, preg_match('/^([0-9]+)TiB$/', $limits[$name] ?? '', $value), $name);
            self::assertSame(ResourceLimits::MAX_BYTES, (int) $value[1] * 1024 ** 4, $name . ' is capped at the largest memory ceiling');
        }
        self::assertSame('0', $limits['disk'] ?? null);
        $this->expectException(InvalidArgumentException::class);
        (new BackendOptions())->withMemoryCeilingBytes(ResourceLimits::MAX_BYTES + 1);
    }

    public function testAWorkerLoadsItsExtensionWhenPhpDoesNotIncludeIt(): void
    {
        $php = $this->fakePrograms() . '/php';
        $runner = new FakeProcessRunner(
            static function (array $command): ProcessResult {
                $arguments = implode(' ', $command);
                if (substr($arguments, -5) === '-n -m') {
                    return FakeProcessRunner::result(0, "[PHP Modules]\nCore\ndate\n");
                }
                if (strpos($arguments, '-d extension=gd -m') !== false) {
                    return FakeProcessRunner::result(0, "[PHP Modules]\nCore\ngd\n");
                }
                if (end($command) === 'probe' && strpos($arguments, '-d extension=gd') !== false) {
                    return FakeProcessRunner::result(0, '{"inputs":["png","jpeg","tiff","svg",1],"outputs":["webp"],"lossless":["webp"]}');
                }

                return FakeProcessRunner::result(1);
            },
            $this->programOptions()
        );

        $backend = new GdBackend($runner, (new BackendOptions())->withPhpBinary($php));
        $facts = $backend->discoverFacts();

        self::assertSame(['inputs' => 'jpeg,png,tiff', 'load' => '1', 'lossless' => 'webp', 'outputs' => 'webp'], $facts);
        self::assertCount(3, $runner->getRuns());
        foreach ($runner->getRuns() as $run) {
            self::assertSame($backend->getSandboxResourcePaths(), $run['read_only'], 'each run mounts the worker scripts');
        }
    }

    public function testAWorkerLoadsItsExtensionFromTheConfiguredDirectory(): void
    {
        $php = $this->fakePrograms() . '/php';
        $extensions = $this->fakePrograms(['gd.so']);
        $file = (string) realpath($extensions . '/gd.so');
        $runner = new FakeProcessRunner(
            static function (array $command) use ($file): ProcessResult {
                $arguments = implode(' ', $command);
                if (substr($arguments, -5) === '-n -m') {
                    return FakeProcessRunner::result(0, "[PHP Modules]\nCore\n");
                }
                if (strpos($arguments, ' -n -d extension=' . $file . ' ') === false) {
                    return FakeProcessRunner::result(1);
                }
                if (substr($arguments, -2) === '-m') {
                    return FakeProcessRunner::result(0, "[PHP Modules]\ngd\n");
                }

                return FakeProcessRunner::result(0, '{"inputs":["png"],"outputs":["webp"],"lossless":[]}');
            },
            $this->programOptions()
        );
        $backend = new GdBackend($runner, (new BackendOptions())->withPhpBinary($php)->withPhpExtensionDirectory($extensions));

        self::assertSame(['inputs' => 'png', 'load' => '1', 'lossless' => '', 'outputs' => 'webp'], $backend->discoverFacts());
        self::assertSame(
            [$php, '-n', '-d', 'extension=' . $file, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'memory_limit=-1'],
            array_slice($backend->commandLine(self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP), ['load' => '1']), 0, 10)
        );
        self::assertContains($file, $backend->getSandboxResourcePaths(), 'each run mounts the shared object');
        self::assertNotContains((string) realpath($extensions), $backend->getSandboxResourcePaths(), 'but not the directory');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsafeExtensionDirectories(): array
    {
        $directory = 'the PHP extension directory is not owned by root or the effective user, or others can write to it';
        $file = 'the extension file is not owned by root or the effective user, or others can write it';
        $above = 'a directory above the PHP extension directory is not owned by root or the effective user, or others can replace its entries';
        $target = 'the directory of the extension file is not owned by root or the effective user, or others can write to it';

        return [
            'world-writable directory' => ['chmod 0777 ext', $directory],
            'group-writable directory' => ['chmod 0770 ext', $directory],
            'world-writable directory, sticky' => ['chmod 01777 ext', $directory],
            'world-writable shared object' => ['chmod 0666 ext/gd.so', $file],
            'group-writable shared object' => ['chmod 0620 ext/gd.so', $file],
            'directory above writable by others' => ['chmod 0777 .', $above],
            'directory above writable by its group' => ['chmod 0770 .', $above],
            'link to a shared object in a writable directory' => ['link ext/gd.so planted/gd.so', $target],
            'link to the directory in a writable directory' => ['link ext planted', $directory],
            'missing directory' => ['remove ext', 'the PHP extension directory does not exist'],
            'missing shared object' => ['unlink ext/gd.so', 'the PHP extension directory does not contain gd.so'],
        ];
    }

    /**
     * A shared object that another user could have planted or replaced is never loaded: the backend is unavailable,
     * and no run names the shared object.
     *
     * @dataProvider unsafeExtensionDirectories
     */
    #[DataProvider('unsafeExtensionDirectories')]
    public function testAnExtensionThatOthersCanReplaceIsNotLoaded(string $change, string $problem): void
    {
        $root = $this->fakePrograms(['php']);
        self::assertTrue(mkdir($root . '/ext', 0700) && touch($root . '/ext/gd.so') && chmod($root . '/ext/gd.so', 0600));
        self::assertTrue(mkdir($root . '/planted', 0777) && chmod($root . '/planted', 0777) && touch($root . '/planted/gd.so') && chmod($root . '/planted/gd.so', 0600));
        list($action, $path, $argument) = array_pad(explode(' ', $change), 3, '');
        if ($action === 'chmod') {
            self::assertTrue(chmod($root . '/' . $argument, (int) octdec($path)));
        } elseif ($action === 'link') {
            self::assertTrue(rename($root . '/' . $path, $root . '/old') && symlink($root . '/' . $argument, $root . '/' . $path));
        } elseif ($action === 'unlink') {
            self::assertTrue(unlink($root . '/' . $path));
        } else {
            self::assertTrue(unlink($root . '/ext/gd.so') && rmdir($root . '/ext'));
        }
        $runner = $this->modulesOnlyRunner();

        try {
            $facts = (new GdBackend($runner, (new BackendOptions())->withPhpBinary($root . '/php')->withPhpExtensionDirectory($root . '/ext')))->discoverFacts();
        } finally {
            @chmod($root, 0700);
        }

        self::assertSame([AbstractBackend::FACT_UNAVAILABLE => $problem], $facts);
        self::assertCount(1, $runner->getRuns(), 'only php -n -m ran');
    }

    /**
     * Owned by another user, the directory or the shared object is refused even with the safe mode; changing the
     * owner needs root.
     *
     * @group root
     */
    #[Group('root')]
    public function testAnExtensionOwnedByAnotherUserIsNotLoaded(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Changing the owner of a file needs root.');
        }
        $root = $this->fakePrograms(['php', 'gd.so']);
        $options = (new BackendOptions())->withPhpBinary($root . '/php')->withPhpExtensionDirectory($root);
        self::assertTrue(chown($root . '/gd.so', 65534));
        $file = (new GdBackend($this->modulesOnlyRunner(), $options))->discoverFacts();
        self::assertTrue(chown($root . '/gd.so', 0) && chown($root, 65534));
        $directory = (new GdBackend($this->modulesOnlyRunner(), $options))->discoverFacts();
        self::assertTrue(chown($root, 0));

        self::assertSame([AbstractBackend::FACT_UNAVAILABLE => 'the extension file is not owned by root or the effective user, or others can write it'], $file);
        self::assertSame([AbstractBackend::FACT_UNAVAILABLE => 'the PHP extension directory is not owned by root or the effective user, or others can write to it'], $directory);
    }

    /**
     * A directory that becomes unsafe after probing stops the jobs before they start.
     */
    public function testAJobIsRefusedWhenTheExtensionDirectoryBecomesUnsafe(): void
    {
        $root = $this->fakePrograms(['php', 'gd.so']);
        $backend = new GdBackend($this->modulesOnlyRunner(), (new BackendOptions())->withPhpBinary($root . '/php')->withPhpExtensionDirectory($root));
        $job = self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP);
        self::assertContains('extension=' . realpath($root . '/gd.so'), $backend->commandLine($job, ['load' => '1']));
        self::assertTrue(chmod($root . '/gd.so', 0666));

        $this->expectException(ConversionFailedException::class);
        $this->expectExceptionMessage('The gd backend does not load its extension: the extension file is not owned by root or the effective user, or others can write it.');
        $backend->commandLine($job, ['load' => '1']);
    }

    /**
     * The configured path is resolved once: when another user retargets a symbolic link in it (here, to a directory
     * that others can write to, with a planted gd.so), the backend keeps naming and mounting the shared object it
     * checked, and only a new backend resolves the link again, and refuses it.
     */
    public function testARetargetedLinkToTheExtensionDirectoryIsNotFollowed(): void
    {
        $root = $this->fakePrograms(['php']);
        self::assertTrue(mkdir($root . '/ext', 0700) && touch($root . '/ext/gd.so') && chmod($root . '/ext/gd.so', 0600));
        self::assertTrue(mkdir($root . '/planted', 0777) && chmod($root . '/planted', 0777) && touch($root . '/planted/gd.so'));
        self::assertTrue(symlink($root . '/ext', $root . '/link'));
        $options = (new BackendOptions())->withPhpBinary($root . '/php')->withPhpExtensionDirectory($root . '/link');
        $backend = new GdBackend($this->modulesOnlyRunner(), $options);
        $job = self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP);
        $checked = (string) realpath($root . '/ext/gd.so');
        $planted = (string) realpath($root . '/planted/gd.so');
        self::assertContains('extension=' . $checked, $backend->commandLine($job, ['load' => '1']));

        self::assertTrue(symlink($root . '/planted', $root . '/link.new') && rename($root . '/link.new', $root . '/link'));
        self::assertSame($planted, realpath($root . '/link/gd.so'));

        $command = $backend->commandLine($job, ['load' => '1']);
        self::assertContains('extension=' . $checked, $command);
        self::assertNotContains('extension=' . $planted, $command);
        self::assertContains($checked, $backend->getSandboxResourcePaths());
        self::assertNotContains($planted, $backend->getSandboxResourcePaths());

        $this->expectException(ConversionFailedException::class);
        $this->expectExceptionMessage('The gd backend does not load its extension: the PHP extension directory is not owned by root or the effective user, or others can write to it.');
        (new GdBackend($this->modulesOnlyRunner(), $options))->commandLine($job, ['load' => '1']);
    }

    /**
     * A runner whose PHP lists no gd module and fails every other command.
     */
    private function modulesOnlyRunner(): FakeProcessRunner
    {
        return new FakeProcessRunner(
            static function (array $command): ProcessResult {
                return substr(implode(' ', $command), -5) === '-n -m' ? FakeProcessRunner::result(0, "[PHP Modules]\nCore\n") : FakeProcessRunner::result(1);
            },
            $this->programOptions()
        );
    }

    /**
     * The extension a worker loads is traced like the program: its directory and the directories of its own
     * libraries become the library directories of the jobs.
     */
    public function testTheLibrariesOfTheExtensionAreTraced(): void
    {
        $php = $this->fakePrograms() . '/php';
        $extensions = $this->fakePrograms(['gd.so']);
        $loader = $this->fakePrograms(['ld.so']) . '/ld.so';
        $runner = new FakeProcessRunner(
            static function (array $command, WorkingDirectory $directory, ProcessOptions $options) use ($extensions, $loader): ProcessResult {
                $arguments = implode(' ', $command);
                if (isset($options->getEnvironment()['LD_TRACE_LOADED_OBJECTS'])) {
                    return FakeProcessRunner::result(0, "\tlibc.so.6 => /lib/x86_64-linux-gnu/libc.so.6 (0x1)\n\t" . $loader . " (0x2)\n");
                }
                if ($command[0] === $loader) {
                    return FakeProcessRunner::result(0, "\tlibgd.so.3 => /opt/gd/lib/libgd.so.3 (0x3)\n");
                }
                if (substr($arguments, -5) === '-n -m') {
                    return FakeProcessRunner::result(0, "[PHP Modules]\nCore\n");
                }
                if (substr($arguments, -2) === '-m') {
                    return FakeProcessRunner::result(0, "[PHP Modules]\ngd\n");
                }
                if (end($command) === 'echo ini_get("extension_dir");') {
                    return FakeProcessRunner::result(0, $extensions . "\n");
                }

                return FakeProcessRunner::result(0, '{"inputs":["png"],"outputs":["webp"],"lossless":[]}');
            },
            $this->programOptions()
        );
        $backend = new GdBackend($runner, (new BackendOptions())->withPhpBinary($php)->withCapabilityCache(false));

        $facts = $backend->getFacts();

        // The fake loader and the extension share one directory.
        self::assertSame(['/lib/x86_64-linux-gnu', $extensions, '/opt/gd/lib'], array_values(array_intersect_key($facts, array_flip(['library_0', 'library_1', 'library_2']))));
        self::assertSame(\dirname($loader), $extensions);
        self::assertArrayNotHasKey('library_3', $facts);
        $traces = array_values(array_filter($runner->getRuns(), static function (array $run) use ($loader): bool {
            return $run['command'][0] === $loader;
        }));
        self::assertCount(1, $traces);
        self::assertSame([$loader, '--list', $extensions . '/gd.so'], $traces[0]['command']);
    }

    /**
     * A probe that fails names how it ended in the unavailable reason, and the run stays readable for diagnostics.
     */
    public function testAFailingProbeNamesHowItEnded(): void
    {
        $php = $this->fakePrograms() . '/php';
        $runner = new FakeProcessRunner(
            static function (array $command): ProcessResult {
                return end($command) === 'probe' ? FakeProcessRunner::result(255, '', 'PHP Fatal error: boom') : FakeProcessRunner::result(0, "[PHP Modules]\ngd\n");
            },
            $this->programOptions()
        );
        self::assertNull((new GdBackend($runner, (new BackendOptions())->withPhpBinary($php)))->getLastDiscoveryResult());
        $backend = new GdBackend($runner, (new BackendOptions())->withPhpBinary($php));

        self::assertSame([AbstractBackend::FACT_UNAVAILABLE => 'the gd worker does not run (exit code 255)'], $backend->discoverFacts());
        $result = $backend->getLastDiscoveryResult();
        self::assertNotNull($result);
        self::assertSame('PHP Fatal error: boom', $result->getStderr());
    }

    public function testAWorkerWithoutItsExtensionIsUnavailable(): void
    {
        $php = $this->fakePrograms() . '/php';
        $runner = new FakeProcessRunner(
            static function (): ProcessResult {
                return FakeProcessRunner::result(0, "[PHP Modules]\nCore\n");
            },
            $this->programOptions()
        );

        $facts = (new GdBackend($runner, (new BackendOptions())->withPhpBinary($php)))->discoverFacts();

        self::assertSame([AbstractBackend::FACT_UNAVAILABLE => 'the gd extension is not available to the PHP CLI'], $facts);
    }

    public function testTheImagickEnvironmentPointsToThePolicyAndTheWorkingDirectory(): void
    {
        $runner = new FakeProcessRunner(
            static function (): ProcessResult {
                return FakeProcessRunner::result(0, "[PHP Modules]\nimagick\n");
            },
            $this->programOptions()
        );

        (new ImagickBackend($runner, (new BackendOptions())->withPhpBinary($this->program('php'))))->discoverFacts();

        $runs = $runner->getRuns();
        self::assertCount(2, $runs);
        $environment = $runs[1]['options']->getEnvironment();
        self::assertSame(ImagickBackend::policyDirectory(), $environment['MAGICK_CONFIGURE_PATH'] ?? null);
        self::assertSame($runs[1]['directory'], $environment['MAGICK_TEMPORARY_PATH'] ?? null);
        self::assertSame('1', $environment['MAGICK_THREAD_LIMIT'] ?? null);
        self::assertSame('probe', end($runs[1]['command']));
        self::assertNotContains('extension=imagick', $runs[1]['command'], 'imagick is listed by php -n -m, so it is not loaded again');
    }
}
