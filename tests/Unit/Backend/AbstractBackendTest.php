<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Backend\CwebpBackend;
use Mfonte\SecureImageConverter\Backend\Internal\CapabilityProber;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Tests\Unit\Backend\Fake\FakeProcessRunner;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The shared run of every backend, with cwebp and a runner that "converts" by copying the WEBP probe image: probing,
 * caching, the working directory, the limits and allowlist of each run, and the failure mapping.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\AbstractBackend
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\CapabilityProber
 */
#[CoversClass(AbstractBackend::class)]
#[CoversClass(CapabilityProber::class)]
final class AbstractBackendTest extends TestCase
{
    use BackendFixtures;
    use FakePrograms;

    /**
     * What the fake cwebp does: "copy", "fail", "signal", "timeout" or "link".
     *
     * @var string
     */
    private $behaviour = 'copy';

    /**
     * @var WorkingDirectory|null
     */
    private $files;

    protected function set_up(): void
    {
        parent::set_up();
        $this->files = WorkingDirectory::create();
    }

    protected function tear_down(): void
    {
        $this->removeFakePrograms();
        if ($this->files !== null) {
            $this->files->remove();
        }
        parent::tear_down();
    }

    private function runner(?ProcessOptions $options = null): FakeProcessRunner
    {
        return new FakeProcessRunner(
            function (array $command, WorkingDirectory $directory): ProcessResult {
                $output = $directory->pathOf((string) $command[count($command) - 1]);
                switch ($this->behaviour) {
                    case 'fail':
                        return FakeProcessRunner::result(1, '', 'Unsupported color conversion request');
                    case 'signal':
                        return FakeProcessRunner::result(0, '', '', 24);
                    case 'timeout':
                        return FakeProcessRunner::result(0, '', '', 9, true);
                    case 'link':
                        symlink('/etc/hostname', $output);

                        return FakeProcessRunner::result(0);
                    case 'truncated':
                        file_put_contents($output, substr((string) file_get_contents(__DIR__ . '/../../../resources/probe/still.webp'), 0, 20));

                        return FakeProcessRunner::result(0);
                    case 'wrong size':
                        copy(__DIR__ . '/../../../resources/probe/anim.webp', $output);

                        return FakeProcessRunner::result(0);
                    default:
                        copy(__DIR__ . '/../../../resources/probe/still.webp', $output);

                        return FakeProcessRunner::result(0);
                }
            },
            $options !== null ? $options : $this->programOptions()
        );
    }

    private function options(): BackendOptions
    {
        self::assertNotNull($this->files);

        return (new BackendOptions())->withCapabilityCache(true, $this->files->getPath() . '/cache');
    }

    private function job(): BackendJob
    {
        self::assertNotNull($this->files);
        $input = __DIR__ . '/../../../resources/probe/still.png';

        return new BackendJob(
            (string) realpath($input),
            (new ImageInspector())->inspect($input),
            $this->files->getPath() . '/converted.webp',
            ImageFormat::WEBP,
            16,
            12
        );
    }

    public function testCapabilitiesAreWhatTheProbesProve(): void
    {
        $backend = new CwebpBackend($this->runner(), $this->options());

        $capabilities = $backend->getCapabilities();

        self::assertTrue($capabilities->isAvailable());
        self::assertSame([ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::WEBP, ImageFormat::TIFF], $capabilities->getInputFormats());
        self::assertSame([ImageFormat::WEBP], $capabilities->getOutputFormats());
        self::assertSame([], $capabilities->getFits(), 'the copied probe image never has the resized size');
        self::assertSame([], $capabilities->getLosslessFormats(), 'the copied probe image is lossy');
        self::assertFalse($capabilities->keepsIccProfile());
        self::assertFalse($capabilities->keepsMetadata());
        self::assertContains('it failed to convert a resize with fit cover', $capabilities->getNotes(), 'the backend refuses an output of the wrong size');
        self::assertContains('its lossless webp output was lossy', $capabilities->getNotes());
    }

    public function testCapabilitiesAreCachedPerFingerprint(): void
    {
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('The capability cache needs the posix extension.');
        }
        $runner = $this->runner();
        $first = (new CwebpBackend($runner, $this->options()))->getCapabilities();
        $probes = count($runner->getRuns());

        $second = new CwebpBackend($runner, $this->options());
        self::assertSame($first->toArray(), $second->getCapabilities()->toArray());
        self::assertCount($probes, $runner->getRuns(), 'nothing runs again');

        $second->refreshCapabilities();
        self::assertCount(2 * $probes, $runner->getRuns());
    }

    public function testAJobRunsWithItsLimitsAllowlistAndInput(): void
    {
        $runner = $this->runner();
        $backend = new CwebpBackend($runner, $this->options());
        $backend->getCapabilities();
        $job = $this->job();

        $result = $backend->convert($job);

        self::assertSame('cwebp', $result->getBackend());
        self::assertFileEquals(__DIR__ . '/../../../resources/probe/still.webp', $job->getOutputPath());
        $runs = $runner->getRuns();
        $run = $runs[count($runs) - 1];
        self::assertSame($backend->commandLine($job), $run['command']);
        self::assertSame([$job->getInputPath()], $run['read_only']);
        self::assertSame($backend->estimateMemoryBytes($job), $run['options']->getResourceLimits()->getAddressSpaceBytes());
        self::assertSame($backend->getSandboxPaths(), $run['options']->getSandboxReadOnlyPaths());
        self::assertContains($this->fakePrograms() . '/cwebp', $run['options']->getSandboxReadOnlyPaths());
        self::assertNotContains('/usr', $run['options']->getSandboxReadOnlyPaths());
        self::assertDirectoryDoesNotExist($run['directory'], 'the working directory is removed');
    }

    public function testFailuresMapToExceptions(): void
    {
        $backend = new CwebpBackend($this->runner(), $this->options());
        $backend->getCapabilities();
        $expected = [
            'fail' => ConversionFailedException::class,
            'signal' => ResourceLimitException::class,
            'timeout' => ConversionTimeoutException::class,
            'link' => ConversionFailedException::class,
            'truncated' => ConversionFailedException::class,
            'wrong size' => ConversionFailedException::class,
        ];
        foreach ($expected as $behaviour => $class) {
            $this->behaviour = $behaviour;
            try {
                $backend->convert($this->job());
                self::fail('The conversion must fail: ' . $behaviour);
            } catch (\Exception $exception) {
                self::assertInstanceOf($class, $exception, $behaviour);
                if ($behaviour === 'truncated') {
                    self::assertSame('The cwebp conversion produced an unusable output: it is not a valid webp image.', $exception->getMessage());
                }
                if ($behaviour === 'wrong size') {
                    self::assertSame('The cwebp conversion produced an unusable output: it has 3 frames instead of 1.', $exception->getMessage());
                }
            }
            self::assertFileDoesNotExist($this->job()->getOutputPath());
        }
    }

    public function testAJobOutsideTheCapabilitiesDoesNotRun(): void
    {
        $runner = $this->runner();
        $backend = new CwebpBackend($runner, $this->options());
        $backend->getCapabilities();
        $runs = count($runner->getRuns());

        try {
            $backend->convert($this->job()->withOutputFormat(ImageFormat::AVIF));
            self::fail('The job must be refused.');
        } catch (ConversionFailedException $exception) {
            self::assertSame('The cwebp backend cannot convert this job: it does not encode avif.', $exception->getMessage());
        }
        self::assertCount($runs, $runner->getRuns());
    }

    public function testAJobAboveTheMemoryCeilingDoesNotRun(): void
    {
        $runner = $this->runner();
        $backend = new CwebpBackend($runner, $this->options()->withMemoryCeilingBytes(33554432));
        $backend->getCapabilities();
        $runs = count($runner->getRuns());

        try {
            $backend->convert($this->job()->withOutputFormat(ImageFormat::WEBP));
            self::fail('The job must be refused.');
        } catch (ResourceLimitException $exception) {
            self::assertStringContainsString('above the memory ceiling of 32 MiB', $exception->getMessage());
        }
        self::assertCount($runs, $runner->getRuns());
    }

    public function testAMissingProgramMakesTheBackendUnavailable(): void
    {
        $runner = $this->runner((new ProcessOptions())->withSearchPath([$this->fakePrograms(['vips'])]));
        $backend = new CwebpBackend($runner, $this->options());

        self::assertSame('cwebp is not installed', $backend->getCapabilities()->getUnavailableReason());
        self::assertSame([], $runner->getRuns());
        $this->expectException(ConversionFailedException::class);
        $this->expectExceptionMessage('The cwebp backend is unavailable: cwebp is not installed.');
        $backend->convert($this->job());
    }
}
