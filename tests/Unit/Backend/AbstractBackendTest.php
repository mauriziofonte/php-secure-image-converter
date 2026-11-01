<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Backend\CwebpBackend;
use Mfonte\SecureImageConverter\Backend\Internal\CapabilityProber;
use Mfonte\SecureImageConverter\Backend\Internal\SandboxPaths;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Tests\Unit\Backend\Fake\FakeProcessRunner;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LogLevel;
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
     * What the fake cwebp does: "copy", "fail", "signal", "timeout", "link", "truncated", "wrong size", or "fail on
     * input" and "crash on input" (an exit code 1 or SIGSEGV for the job of hostileJob(), a copy for every other run).
     *
     * @var string
     */
    private $behaviour = 'copy';

    /**
     * What the fake dynamic loader prints for a trace (LD_TRACE_LOADED_OBJECTS=1), or null when it fails.
     *
     * @var string|null
     */
    private $trace;

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
            function (array $command, WorkingDirectory $directory, ProcessOptions $options): ProcessResult {
                if (isset($options->getEnvironment()['LD_TRACE_LOADED_OBJECTS'])) {
                    return $this->trace !== null ? FakeProcessRunner::result(0, $this->trace) : FakeProcessRunner::result(1);
                }
                $output = $directory->pathOf((string) $command[count($command) - 1]);
                $hostile = $this->files !== null && @readlink($directory->pathOf('input.png')) === $this->files->getPath() . '/hostile.png';
                $behaviour = $this->behaviour;
                if (substr($behaviour, -9) === ' on input') {
                    $behaviour = $hostile ? $behaviour : 'copy';
                }
                switch ($behaviour) {
                    case 'fail on input':
                        return FakeProcessRunner::result(1, '', 'libpng error: IDAT: invalid distance too far back');
                    case 'crash on input':
                        return FakeProcessRunner::result(0, '', '', 11);
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

    /**
     * The job of a copy of the PNG probe image, "hostile.png", which the fake cwebp fails on in the "on input"
     * behaviours.
     */
    private function hostileJob(): BackendJob
    {
        self::assertNotNull($this->files);
        $input = $this->files->getPath() . '/hostile.png';
        copy(__DIR__ . '/../../../resources/probe/still.png', $input);

        return new BackendJob(
            $input,
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

    /**
     * A cache directory that another user could write (here: one open to group and others) is not used: each backend
     * warns once, probes, and stores nothing.
     */
    public function testAnUnusableCacheIsReportedOncePerBackend(): void
    {
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('The capability cache needs the posix extension.');
        }
        self::assertNotNull($this->files);
        $directory = $this->files->getPath() . '/shared';
        mkdir($directory);
        chmod($directory, 0777);
        $logger = new MemoryLogger();
        $options = (new BackendOptions())->withCapabilityCache(true, $directory)->withLogger($logger);
        $runner = $this->runner();
        $backend = new CwebpBackend($runner, $options);

        self::assertTrue($backend->getCapabilities()->isAvailable());
        $backend->getCapabilities();
        $probes = count($runner->getRuns());
        (new CwebpBackend($runner, $options))->getCapabilities();

        self::assertCount(2 * $probes, $runner->getRuns(), 'the second backend probes again');
        self::assertSame([], array_values(array_diff((array) scandir($directory), ['.', '..'])), 'nothing is stored');
        $warnings = $logger->getMessages(LogLevel::WARNING);
        self::assertCount(2, $warnings, 'one warning per backend');
        self::assertStringContainsString('BackendOptions::withCapabilityCache(true, $directory)', $warnings[0]);
        self::assertSame(
            ['reason' => $directory . ' is not a directory owned by user ' . posix_geteuid() . ' and closed to writes by group and others', 'backends' => 'cwebp'],
            $logger->getRecords()[0]['context']
        );
    }

    /**
     * The probe traces the libraries of the program; the jobs then see their directories instead of the whole
     * library roots, which only the discovery runs (and the jobs of a backend whose trace failed) see.
     */
    public function testJobsSeeOnlyTheTracedLibraryDirectories(): void
    {
        self::assertNotNull($this->files);
        $libraries = $this->files->getPath() . '/lib';
        mkdir($libraries);
        touch($libraries . '/libwebp.so.7');
        $this->trace = "\tlinux-vdso.so.1 (0x00007ffd5b9f3000)\n\tlibwebp.so.7 => " . $libraries . "/libwebp.so.7 (0x00007f2c2a000000)\n";
        $runner = $this->runner();
        $backend = new CwebpBackend($runner, $this->options()->withCapabilityCache(false));

        self::assertSame($libraries, $backend->getFacts()['library_0'] ?? null);
        $backend->convert($this->job());

        $runs = $runner->getRuns();
        $job = $runs[count($runs) - 1]['options']->getSandboxReadOnlyPaths();
        self::assertContains($libraries, $job);
        self::assertSame($backend->getSandboxPaths(), $job);
        foreach (SandboxPaths::LIBRARY_DIRECTORIES as $root) {
            self::assertNotContains($root, $job);
        }
        $trace = $runs[0];
        self::assertSame([$this->fakePrograms() . '/cwebp'], $trace['command']);
        self::assertSame('1', $trace['options']->getEnvironment()['LD_TRACE_LOADED_OBJECTS'] ?? null);

        $this->trace = "\tlibwebp.so.7 => not found\n";
        $broad = new CwebpBackend($runner, $this->options()->withCapabilityCache(false));
        self::assertArrayNotHasKey('library_0', $broad->getFacts());
        self::assertSame(SandboxPaths::forPrograms([$this->fakePrograms() . '/cwebp']), $broad->getSandboxPaths(), 'an unreadable trace keeps the library roots');
    }

    public function testAJobWithTimeLimitsReplacesThoseOfTheRunner(): void
    {
        $runner = $this->runner();
        $backend = new CwebpBackend($runner, $this->options());
        $backend->getCapabilities();

        $backend->convert($this->job()->withTimeLimits(900.0, 1800));
        $runs = $runner->getRuns();
        $run = $runs[count($runs) - 1];
        self::assertSame(900.0, $run['options']->getTimeoutSeconds(), 'above the runner default of 30 seconds');
        self::assertSame(1800, $run['options']->getResourceLimits()->getCpuSeconds());

        $backend->convert($this->job()->withTimeLimits(2.5, 3));
        $runs = $runner->getRuns();
        $run = $runs[count($runs) - 1];
        self::assertSame(2.5, $run['options']->getTimeoutSeconds(), 'below it as well');
        self::assertSame(3, $run['options']->getResourceLimits()->getCpuSeconds());

        $backend->convert($this->job());
        $runs = $runner->getRuns();
        $run = $runs[count($runs) - 1];
        self::assertSame(ProcessOptions::DEFAULT_TIMEOUT_SECONDS, $run['options']->getTimeoutSeconds(), 'the runner default without');
    }

    public function testAJobRunsWithItsLimitsAllowlistAndInput(): void
    {
        $runner = $this->runner();
        $ceiling = 3 * 1073741824;
        $backend = new CwebpBackend($runner, $this->options()->withMemoryCeilingBytes($ceiling));
        $backend->getCapabilities();
        foreach ($runner->getRuns() as $probe) {
            self::assertSame($ceiling, $probe['options']->getResourceLimits()->getAddressSpaceBytes(), 'probes and traces run with the ceiling');
        }
        $job = $this->job();

        $result = $backend->convert($job);

        self::assertSame('cwebp', $result->getBackend());
        self::assertFileEquals(__DIR__ . '/../../../resources/probe/still.webp', $job->getOutputPath());
        $runs = $runner->getRuns();
        $run = $runs[count($runs) - 1];
        self::assertSame($backend->commandLine($job), $run['command']);
        self::assertSame([$job->getInputPath()], $run['read_only']);
        self::assertSame($ceiling, $run['options']->getResourceLimits()->getAddressSpaceBytes(), 'the ceiling, not the estimate');
        self::assertLessThan($ceiling, $backend->estimateMemoryBytes($job));
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
            file_put_contents($this->job()->getOutputPath(), 'previous');
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
            self::assertSame('previous', file_get_contents($this->job()->getOutputPath()), $behaviour . ': the destination is left alone');
            self::assertSame([], glob(\dirname($this->job()->getOutputPath()) . '/.sic-*'), $behaviour . ': no copy is left behind');
        }
    }

    /**
     * A program that fails or crashes on the input while it still converts the probe image of the format failed on the
     * input: invalid_image, never conversion_failed. One more run checks the probe image, and nothing is left behind.
     */
    public function testAFailureOnTheInputAloneIsAnInvalidImage(): void
    {
        $runner = $this->runner();
        $backend = new CwebpBackend($runner, $this->options());
        $backend->getCapabilities();
        $expected = [
            'fail on input' => 'The cwebp conversion failed (exit code 1). It converts a valid png image, so this image is malformed or uses something its decoder refuses.',
            'crash on input' => 'The cwebp conversion crashed (signal 11). It converts a valid png image, so this image is malformed or uses something its decoder refuses.',
        ];
        foreach ($expected as $behaviour => $message) {
            $this->behaviour = $behaviour;
            $job = $this->hostileJob();
            file_put_contents($job->getOutputPath(), 'previous');
            $runs = count($runner->getRuns());
            try {
                $backend->convert($job);
                self::fail('The conversion must fail: ' . $behaviour);
            } catch (InvalidImageException $exception) {
                self::assertSame($message, $exception->getMessage(), $behaviour);
                self::assertSame('invalid_image', $exception->getErrorCode());
            }
            self::assertCount($runs + 2, $runner->getRuns(), $behaviour . ': the job, then the probe image');
            self::assertSame('previous', file_get_contents($job->getOutputPath()), $behaviour . ': the destination is left alone');
            self::assertSame([], glob(\dirname($job->getOutputPath()) . '/.sic-*'), $behaviour . ': no copy is left behind');
        }
    }

    /**
     * A program that fails on the probe image too is broken: the failure stays conversion_failed.
     */
    public function testAFailureOnTheProbeImageTooIsAConversionFailure(): void
    {
        $runner = $this->runner();
        $backend = new CwebpBackend($runner, $this->options());
        $backend->getCapabilities();
        $this->behaviour = 'fail';
        $runs = count($runner->getRuns());

        try {
            $backend->convert($this->hostileJob());
            self::fail('The conversion must fail.');
        } catch (ConversionFailedException $exception) {
            self::assertSame('The cwebp conversion failed (exit code 1).', $exception->getMessage());
        }
        self::assertCount($runs + 2, $runner->getRuns());
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
