<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Backend\Internal\CapabilityCache;
use Mfonte\SecureImageConverter\Backend\Internal\CapabilityProber;
use Mfonte\SecureImageConverter\Backend\Internal\Facts;
use Mfonte\SecureImageConverter\Backend\Internal\OutputFile;
use Mfonte\SecureImageConverter\Backend\Internal\ProbeImages;
use Mfonte\SecureImageConverter\Backend\Internal\ProcessFailure;
use Mfonte\SecureImageConverter\Backend\Internal\SandboxPaths;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\PrivateCacheDirectory;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use Mfonte\SecureImageConverter\Isolation\ProcessRunnerInterface;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use Mfonte\SecureImageConverter\Validation\Limits;

/**
 * What every backend shares: one private working directory per job, the input linked into it under a fixed name,
 * a process started through the runner with the job's own limits and sandbox allowlist, the output taken without
 * trusting the process, and capabilities probed once and cached.
 *
 * Each run:
 *
 * - creates a WorkingDirectory (mode 0700): the current directory, HOME and TMPDIR of the process, and the only
 *   writable place in the sandbox. Every temporary file goes there: MAGICK_TEMPORARY_PATH and the TMPDIR that vips
 *   and libraries honour point to it (glibc's tmpfile() ignores TMPDIR and uses /tmp, which the sandbox replaces with
 *   an empty, read-only file system);
 * - links the input there as "input.<extension>" (the input path is mounted read-only in the sandbox), so that no
 *   path chosen by a client ever reaches a command line;
 * - runs the command with an address space limit equal to estimateMemoryBytes() (at least
 *   ResourceLimits::MIN_ADDRESS_SPACE_BYTES), the runner's other limits, and the backend's sandbox allowlist
 *   (SandboxPaths) plus any read-only path the operator added to the runner's defaults;
 * - maps a failure to its exception (ProcessFailure), or copies "output.<extension>" next to the job's output path
 *   (OutputFile), checks the copy (verifyOutput()) and only then renames it over the output path, and removes the
 *   directory.
 *
 * Capabilities are probed with CapabilityProber on first use and kept in the capability cache (BackendOptions), keyed
 * by the programs (path, size, modification time, inode), the isolation level and the isolation options.
 *
 * Subclasses provide the programs, what to probe, the command line and the memory estimate.
 */
abstract class AbstractBackend implements BackendInterface
{
    /**
     * Base name of the input link and of the output file in the working directory.
     */
    public const INPUT_NAME = 'input';

    public const OUTPUT_NAME = 'output';

    /**
     * Fact set by discover() when the backend cannot run; its value is the reason.
     */
    public const FACT_UNAVAILABLE = 'unavailable';

    /**
     * Wall-clock budget of one probe run.
     */
    private const PROBE_TIMEOUT_SECONDS = 15.0;

    /**
     * Version of the probing logic, part of the cache fingerprint.
     */
    private const PROBE_VERSION = 1;

    /**
     * @var ProcessRunnerInterface
     */
    private $runner;

    /**
     * @var BackendOptions
     */
    private $options;

    /**
     * @var BackendCapabilities|null
     */
    private $capabilities;

    /**
     * @var array<string, string>
     */
    private $facts = [];

    /**
     * @var array<string, string|null>|null
     */
    private $programs;

    public function __construct(ProcessRunnerInterface $runner, ?BackendOptions $options = null)
    {
        $this->runner = $runner;
        $this->options = $options !== null ? $options : new BackendOptions();
    }

    public function getRunner(): ProcessRunnerInterface
    {
        return $this->runner;
    }

    public function getOptions(): BackendOptions
    {
        return $this->options;
    }

    final public function getCapabilities(): BackendCapabilities
    {
        if ($this->capabilities === null) {
            $this->load(true);
        }

        return $this->capabilities !== null ? $this->capabilities : BackendCapabilities::unavailable($this->getName(), 'it was not probed');
    }

    /**
     * Probes again, ignoring the cache, and stores the result in it.
     */
    final public function refreshCapabilities(): BackendCapabilities
    {
        $this->capabilities = null;
        $this->programs = null;
        $this->load(false);

        return $this->getCapabilities();
    }

    final public function convert(BackendJob $job): BackendResult
    {
        $capabilities = $this->getCapabilities();
        if (!$capabilities->isAvailable()) {
            throw new ConversionFailedException(sprintf(
                'The %s backend is unavailable: %s.',
                $this->getName(),
                (string) $capabilities->getUnavailableReason()
            ));
        }
        // A job outside the capabilities is refused, so that no program is asked for what it would do differently
        // (gif2webp would keep every frame of a first-frame job).
        $unsupported = $capabilities->explainUnsupported($job);
        if ($unsupported !== null) {
            throw new ConversionFailedException(sprintf('The %s backend cannot convert this job: %s.', $this->getName(), $unsupported));
        }
        $needed = $this->estimateMemoryBytes($job);
        if ($needed > $this->options->getMemoryCeilingBytes()) {
            throw new ResourceLimitException(sprintf(
                'The %s backend needs about %d MiB for this image, above the memory ceiling of %d MiB.',
                $this->getName(),
                (int) ceil($needed / 1048576),
                intdiv($this->options->getMemoryCeilingBytes(), 1048576)
            ));
        }

        return $this->perform($job, $this->facts, null);
    }

    /**
     * An address space estimate: $base bytes, plus $perInputPixel bytes per decoded pixel and $perOutputPixel per
     * encoded pixel, saturated at a quarter of PHP_INT_MAX (far above any memory ceiling).
     */
    final protected static function addressSpace(int $base, int $perInputPixel, int $inputPixels, int $perOutputPixel, int $outputPixels): int
    {
        return (int) min(PHP_INT_MAX / 4, $base + $perInputPixel * (float) $inputPixels + $perOutputPixel * (float) $outputPixels);
    }

    /**
     * Facts learned while probing that the command lines depend on.
     *
     * @return array<string, string>
     */
    final public function getFacts(): array
    {
        $this->getCapabilities();

        return $this->facts;
    }

    /**
     * The command line convert() runs for a job, with the given facts or, when null, the probed ones; for
     * diagnostics and tests. File names are relative to the job's working directory.
     *
     * @param array<string, string>|null $facts
     *
     * @throws ConversionFailedException when a program is missing
     *
     * @return list<string>
     */
    final public function commandLine(BackendJob $job, ?array $facts = null): array
    {
        return $this->command(
            $job,
            self::INPUT_NAME . '.' . ImageFormat::extension($job->getInputFormat()),
            self::OUTPUT_NAME . '.' . ImageFormat::extension($job->getOutputFormat()),
            $this->requirePrograms(),
            $facts !== null ? $facts : $this->getFacts()
        );
    }

    /**
     * Runs the discovery commands only (no conversion) and returns the facts they establish, with FACT_UNAVAILABLE
     * when the backend cannot run; for diagnostics and tests.
     *
     * @throws ExceptionInterface when a program is missing or a run cannot start
     *
     * @return array<string, string>
     */
    final public function discoverFacts(): array
    {
        $programs = $this->requirePrograms();
        $directory = WorkingDirectory::create($this->options->getWorkingDirectoryParent());

        try {
            return Facts::check($this->discover($programs, $directory));
        } finally {
            $directory->remove();
        }
    }

    /**
     * The read-only paths visible in this backend's sandbox (the runner's defaults are replaced by them).
     *
     * @return list<string>
     */
    final public function getSandboxPaths(): array
    {
        $programs = array_values(array_filter($this->locatePrograms(), 'is_string'));
        $configured = $this->runner->getOptions()->getSandboxReadOnlyPaths();

        return SandboxPaths::forPrograms(
            $programs,
            array_merge(
                $this->sandboxData(),
                array_values(array_diff($configured, ProcessOptions::DEFAULT_SANDBOX_READ_ONLY_PATHS))
            )
        );
    }

    /**
     * The runner a job runs with: the given address space limit and environment, this backend's sandbox paths.
     *
     * @param array<string, string> $environment variables added to the runner's explicit environment
     */
    final public function createJobRunner(int $memoryBytes, array $environment = [], ?float $timeoutSeconds = null): ProcessRunnerInterface
    {
        $options = $this->runner->getOptions();
        $memoryBytes = max(ResourceLimits::MIN_ADDRESS_SPACE_BYTES, min(ResourceLimits::MAX_BYTES, $memoryBytes));
        $options = $options
            ->withResourceLimits($options->getResourceLimits()->withAddressSpaceBytes($memoryBytes))
            ->withEnvironment(array_merge($options->getEnvironment(), $environment))
            ->withSandboxReadOnlyPaths($this->getSandboxPaths());
        if ($timeoutSeconds !== null && $timeoutSeconds < $options->getTimeoutSeconds()) {
            $options = $options->withTimeoutSeconds($timeoutSeconds);
        }

        return $this->runner->withOptions($options);
    }

    /**
     * The programs the backend runs, by role, with their absolute path or null when missing.
     *
     * @return array<string, string|null>
     */
    final protected function locatePrograms(): array
    {
        if ($this->programs === null) {
            $this->programs = $this->programs(new ExecutableFinder($this->runner->getOptions()->getSearchPath()));
        }

        return $this->programs;
    }

    /**
     * Runs a command in a working directory with this backend's sandbox and environment; for discover().
     *
     * @param list<string>               $command
     * @param array<string, string>|null $environment null for environment() without facts
     *
     * @throws ExceptionInterface when the run cannot start
     */
    final protected function execute(array $command, WorkingDirectory $directory, int $memoryBytes, ?array $environment = null): ProcessResult
    {
        return $this->createJobRunner(
            $memoryBytes,
            $environment !== null ? $environment : $this->environment($directory, []),
            self::PROBE_TIMEOUT_SECONDS
        )->execute($command, $directory);
    }

    /**
     * Locates the programs, by role.
     *
     * @return array<string, string|null>
     */
    abstract protected function programs(ExecutableFinder $finder): array;

    /**
     * Learns what the command lines depend on, running the programs where needed (with execute()). Returns the
     * facts, or [FACT_UNAVAILABLE => reason] when the backend cannot run.
     *
     * @param array<string, string> $programs the located programs, by role
     *
     * @throws ExceptionInterface when a run fails to start
     *
     * @return array<string, string>
     */
    abstract protected function discover(array $programs, WorkingDirectory $directory): array;

    /**
     * What the programs claim to support, to be probed (see CapabilityProber::probe()).
     *
     * @param array<string, string> $facts
     *
     * @return array{inputs: list<string>, outputs: list<string>, first_frame: list<string>, animated_inputs: list<string>, animated_outputs: list<string>, fits: list<string>, animated_fits: list<string>, lossless: list<string>, icc_profile: bool, metadata: bool}
     */
    abstract protected function candidates(array $facts): array;

    /**
     * The command line of a job. File names are relative to the working directory, the current directory.
     *
     * @param array<string, string> $programs the located programs, by role
     * @param array<string, string> $facts
     *
     * @return list<string>
     */
    abstract protected function command(BackendJob $job, string $input, string $output, array $programs, array $facts): array;

    /**
     * Variables the program needs in its environment.
     *
     * @param array<string, string> $facts
     *
     * @return array<string, string>
     */
    protected function environment(WorkingDirectory $directory, array $facts): array
    {
        return [];
    }

    /**
     * Files and directories the program needs besides itself and the libraries.
     *
     * @return list<string>
     */
    protected function sandboxData(): array
    {
        return [];
    }

    /**
     * Prepares the working directory before the command runs, such as a job file for a worker.
     *
     * @throws ConversionFailedException when it cannot be prepared
     */
    protected function prepare(BackendJob $job, WorkingDirectory $directory, string $input, string $output): void
    {
    }

    /**
     * Whether the program is a PHP worker that reports resource limits with ProcessFailure::WORKER_RESOURCE_LIMIT_EXIT.
     */
    protected function isWorker(): bool
    {
        return false;
    }

    /**
     * Candidates of a backend that supports nothing, to be completed by subclasses.
     *
     * @return array{inputs: list<string>, outputs: list<string>, first_frame: list<string>, animated_inputs: list<string>, animated_outputs: list<string>, fits: list<string>, animated_fits: list<string>, lossless: list<string>, icc_profile: bool, metadata: bool}
     */
    final protected static function noCandidates(): array
    {
        return [
            'inputs' => [],
            'outputs' => [],
            'first_frame' => [],
            'animated_inputs' => [],
            'animated_outputs' => [],
            'fits' => [],
            'animated_fits' => [],
            'lossless' => [],
            'icc_profile' => false,
            'metadata' => false,
        ];
    }

    /**
     * Runs one job; $timeoutSeconds bounds probe runs below the runner's timeout.
     *
     * @param array<string, string> $facts
     *
     * @throws ExceptionInterface
     */
    private function perform(BackendJob $job, array $facts, ?float $timeoutSeconds): BackendResult
    {
        $programs = $this->requirePrograms();
        $directory = WorkingDirectory::create($this->options->getWorkingDirectoryParent());

        try {
            $input = self::INPUT_NAME . '.' . ImageFormat::extension($job->getInputFormat());
            $output = self::OUTPUT_NAME . '.' . ImageFormat::extension($job->getOutputFormat());
            if (!@symlink($job->getInputPath(), $directory->pathOf($input))) {
                throw new ConversionFailedException('The input could not be linked into the working directory.');
            }
            $this->prepare($job, $directory, $input, $output);
            $runner = $this->createJobRunner(
                $this->estimateMemoryBytes($job),
                $this->environment($directory, $facts),
                $timeoutSeconds
            );
            $result = $runner->execute(
                $this->command($job, $input, $output, $programs, $facts),
                $directory,
                null,
                [$job->getInputPath()]
            );
            if (!$result->isSuccessful()) {
                throw ProcessFailure::exception($this->getName(), $result, $this->isWorker());
            }
            $maxBytes = $runner->getOptions()->getResourceLimits()->getFileSizeBytes();
            $name = $this->getName();
            OutputFile::copy(
                $directory->pathOf($output),
                $job->getOutputPath(),
                $maxBytes,
                static function (string $copy) use ($job, $maxBytes, $name): void {
                    $problem = self::verifyOutput($job, $copy, $maxBytes);
                    if ($problem !== null) {
                        throw new ConversionFailedException(sprintf('The %s conversion produced an unusable output: %s.', $name, $problem));
                    }
                }
            );

            return new BackendResult($this->getName(), $job, $result->getDurationMs(), $result->getIsolationLevel());
        } finally {
            $directory->remove();
        }
    }

    /**
     * Why the copy of the output of a job is unusable, or null: its header must parse as the job's format, size and
     * frame count (at most the job's for an animation: an encoder may merge identical frames). A program can exit
     * with status 0 and leave a truncated file: vips writing AVIF when the AV1 encoder fails to allocate.
     */
    private static function verifyOutput(BackendJob $job, string $path, int $maxBytes): ?string
    {
        $limits = new Limits();
        foreach (Capabilities::limitNames() as $name) {
            $limits = $limits->withLimit($name, Capabilities::LIMIT_VALUE_MAX);
        }
        $limits = $limits->withMaxBytes(max(1, min(Capabilities::LIMIT_VALUE_MAX, $maxBytes)));
        try {
            $info = (new ImageInspector($limits, [$job->getOutputFormat()]))->inspect($path);
        } catch (ExceptionInterface $exception) {
            return sprintf('it is not a valid %s image', $job->getOutputFormat());
        }
        if ($info->getWidth() !== $job->getWidth() || $info->getHeight() !== $job->getHeight()) {
            return sprintf('it is %dx%d instead of %dx%d', $info->getWidth(), $info->getHeight(), $job->getWidth(), $job->getHeight());
        }
        $frames = $info->getFrameCount();
        if ($job->isAnimated() ? $frames > $job->getFrameCount() : $frames !== 1) {
            return sprintf('it has %d frames instead of %d', $frames, $job->getFrameCount());
        }

        return null;
    }

    /**
     * @throws ConversionFailedException when a program is missing
     *
     * @return array<string, string>
     */
    private function requirePrograms(): array
    {
        $programs = [];
        foreach ($this->locatePrograms() as $role => $path) {
            if ($path === null) {
                throw new ConversionFailedException(sprintf('The %s backend is unavailable: %s is not installed.', $this->getName(), $role));
            }
            $programs[$role] = $path;
        }

        return $programs;
    }

    /**
     * Loads the capabilities from the cache, or probes them.
     */
    private function load(bool $useCache): void
    {
        $reason = $this->runner->getUnavailableReason();
        if ($reason !== null) {
            // Not cached: the isolation level may become available, and nothing was probed.
            $this->capabilities = BackendCapabilities::unavailable($this->getName(), 'its isolation level is unavailable');
            $this->facts = [];

            return;
        }
        foreach ($this->locatePrograms() as $role => $path) {
            if ($path === null) {
                $this->capabilities = BackendCapabilities::unavailable($this->getName(), $role . ' is not installed');
                $this->facts = [];

                return;
            }
        }
        $cache = new CapabilityCache(
            $this->options->isCapabilityCacheEnabled()
                ? new PrivateCacheDirectory($this->options->getCapabilityCacheDirectory())
                : null,
            $this->options->getCapabilityCacheTtlSeconds()
        );
        $fingerprint = $this->fingerprint();
        $cached = $useCache ? $cache->load($this->getName(), $fingerprint) : null;
        if ($cached !== null) {
            list($this->capabilities, $this->facts) = $cached;

            return;
        }
        list($this->capabilities, $this->facts) = $this->probe();
        $cache->store($this->getName(), $fingerprint, $this->capabilities, $this->facts);
    }

    /**
     * @return array{0: BackendCapabilities, 1: array<string, string>}
     */
    private function probe(): array
    {
        $programs = $this->requirePrograms();

        try {
            $directory = WorkingDirectory::create($this->options->getWorkingDirectoryParent());
        } catch (ExceptionInterface $exception) {
            return [BackendCapabilities::unavailable($this->getName(), 'no working directory could be created'), []];
        }

        try {
            try {
                $facts = Facts::check($this->discover($programs, $directory));
            } catch (ExceptionInterface $exception) {
                $facts = [self::FACT_UNAVAILABLE => 'its programs could not be run'];
            }
            if (isset($facts[self::FACT_UNAVAILABLE])) {
                return [BackendCapabilities::unavailable($this->getName(), $facts[self::FACT_UNAVAILABLE]), []];
            }
            $prober = new CapabilityProber(
                $this->getName(),
                function (BackendJob $job) use ($facts): void {
                    $this->perform($job, $facts, self::PROBE_TIMEOUT_SECONDS);
                },
                new ProbeImages(),
                $directory
            );

            return [$prober->probe($this->candidates($facts)), $facts];
        } finally {
            $directory->remove();
        }
    }

    /**
     * What the capabilities depend on: the programs, the isolation level and options, the probing logic.
     */
    private function fingerprint(): string
    {
        $programs = [];
        foreach ($this->locatePrograms() as $role => $path) {
            $real = $path !== null ? realpath($path) : false;
            $stat = is_string($real) ? @stat($real) : false;
            $programs[$role] = [
                'path' => $path,
                'real' => $real,
                'size' => is_array($stat) ? $stat['size'] : null,
                'mtime' => is_array($stat) ? $stat['mtime'] : null,
                'ino' => is_array($stat) ? $stat['ino'] : null,
            ];
        }
        $json = json_encode([
            'probe' => self::PROBE_VERSION,
            'backend' => $this->getName(),
            'class' => static::class,
            'programs' => $programs,
            'level' => $this->runner->getLevel(),
            'isolation' => $this->runner->getOptions()->toIsolationArray(),
            'data' => $this->sandboxData(),
        ]);
        if (!is_string($json)) {
            throw new InvalidArgumentException('The backend fingerprint could not be encoded.');
        }

        return $json;
    }
}
