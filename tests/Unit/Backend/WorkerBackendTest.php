<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Backend\AbstractWorkerBackend;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Backend\GdBackend;
use Mfonte\SecureImageConverter\Backend\ImagickBackend;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use Mfonte\SecureImageConverter\Tests\Unit\Backend\Fake\FakeProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
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
        $extensions = $this->fakePrograms();
        $runner = new FakeProcessRunner(
            static function (array $command) use ($extensions): ProcessResult {
                $arguments = implode(' ', $command);
                if (strpos($arguments, ' -n -d extension_dir=' . $extensions . ' ') === false) {
                    return FakeProcessRunner::result(1);
                }
                if (substr($arguments, -2) === '-m') {
                    return FakeProcessRunner::result(0, strpos($arguments, '-d extension=gd') !== false ? "[PHP Modules]\ngd\n" : "[PHP Modules]\nCore\n");
                }

                return FakeProcessRunner::result(0, '{"inputs":["png"],"outputs":["webp"],"lossless":[]}');
            },
            $this->programOptions()
        );
        $backend = new GdBackend($runner, (new BackendOptions())->withPhpBinary($php)->withPhpExtensionDirectory($extensions));

        self::assertSame(['inputs' => 'png', 'load' => '1', 'lossless' => '', 'outputs' => 'webp'], $backend->discoverFacts());
        self::assertSame(
            [$php, '-n', '-d', 'extension_dir=' . $extensions, '-d', 'extension=gd', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'memory_limit=-1'],
            array_slice($backend->commandLine(self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP), ['load' => '1']), 0, 12)
        );
        self::assertContains($extensions, $backend->getSandboxResourcePaths(), 'each run mounts the extension directory');
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
