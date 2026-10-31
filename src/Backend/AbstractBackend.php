<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Backend\Internal\CapabilityCache;
use Mfonte\SecureImageConverter\Backend\Internal\CapabilityProber;
use Mfonte\SecureImageConverter\Backend\Internal\Facts;
use Mfonte\SecureImageConverter\Backend\Internal\LibraryTrace;
use Mfonte\SecureImageConverter\Backend\Internal\OutputCheck;
use Mfonte\SecureImageConverter\Backend\Internal\OutputFile;
use Mfonte\SecureImageConverter\Backend\Internal\ProbeImages;
use Mfonte\SecureImageConverter\Backend\Internal\ProcessFailure;
use Mfonte\SecureImageConverter\Backend\Internal\SandboxPaths;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\PrivateCacheDirectory;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use Mfonte\SecureImageConverter\Isolation\ProcessRunnerInterface;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;

/**
 * What every backend shares: one private working directory per job, the input linked into it under a fixed name, a
 * memory estimate that admits or refuses the job, a process started through the runner with the memory ceiling (as its
 * address space limit at the rlimit and sandbox levels) and the backend's sandbox allowlist, the output taken without
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
 * - runs the command with the runner's limits, the address space limit set to the memory ceiling (BackendOptions; the
 *   runner applies limits at the rlimit and sandbox levels only), and the backend's sandbox allowlist (SandboxPaths,
 *   with the library directories that probing traced) plus any read-only path the operator added to the runner's
 *   defaults; the input and the library's own files (worker scripts, configuration) are mounted for the run, after the
 *   sandbox's private /tmp;
 * - maps a failure to its exception (ProcessFailure), or copies "output.<extension>" next to the job's output path
 *   (OutputFile), checks the copy (OutputCheck) and only then renames it over the output path, and removes the
 *   directory.
 *
 * Capabilities are probed with CapabilityProber on first use and kept in the capability cache (BackendOptions), keyed
 * by the programs (path, size, modification time, inode), the isolation level and the isolation options. A cache that
 * is enabled but cannot be used (its directory is not private) is logged as a warning.
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
    private const PROBE_VERSION = 4;

    /**
     * Prefix of the facts that hold the directories of the libraries the programs load (library_0 to library_7).
     */
    public const FACT_LIBRARY_PREFIX = 'library_';

    /**
     * Largest number of library directories kept; programs that need more are given the whole library roots.
     */
    private const MAX_LIBRARY_DIRECTORIES = 8;

    /**
     * The margin of every memory estimate (see estimate()): at least this many percent of the largest measured peak
     * of the job, plus ESTIMATE_MARGIN_BYTES, above that peak.
     */
    public const ESTIMATE_MARGIN_PERCENT = 20;

    /**
     * The fixed part of the margin of every memory estimate (see ESTIMATE_MARGIN_PERCENT): 32 MiB.
     */
    public const ESTIMATE_MARGIN_BYTES = 33554432;

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

    /**
     * @var ProcessResult|null
     */
    private $lastDiscoveryResult;

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
        // The estimate only admits the job: at the rlimit and sandbox levels the process runs with the ceiling as its
        // address space limit, because what a job needs depends on the content as well as on the pixels.
        $needed = $this->estimateMemoryBytes($job);
        if ($needed > $this->options->getMemoryCeilingBytes()) {
            throw new ResourceLimitException(sprintf(
                'The %s backend estimates %d MiB for this image, above the memory ceiling of %d MiB.',
                $this->getName(),
                (int) ceil($needed / 1048576),
                intdiv($this->options->getMemoryCeilingBytes(), 1048576)
            ));
        }

        return $this->perform($job, $this->facts, null);
    }

    /**
     * An address space estimate from a backend's measured coefficients, saturated at a quarter of PHP_INT_MAX (far
     * above any memory ceiling):
     *
     * - $fixed bytes;
     * - $perInputPixel bytes per decoded pixel (every frame of an animated output);
     * - $perOutputPixel bytes per encoded pixel of one frame;
     * - $perDeepPixel bytes per pixel of the larger of input and output when the input holds more than 8 bits per
     *   channel (ImageInfo::getBitDepth());
     * - $perFramePixel bytes per encoded pixel of every frame of an animated output;
     * - $perResizedPixel bytes per encoded pixel of one frame when the output size differs from the input size.
     *
     * The estimate admits or refuses a job (convert() refuses it above the memory ceiling); the process then runs with
     * the ceiling as its address space limit at the rlimit and sandbox levels, and without one below them. Each
     * backend's coefficients come from tests/Fixtures/backend/memory-peaks.csv, the peak address space (VmPeak) of its
     * own command line and environment for one job, and MemoryEstimateTest checks two rules on them:
     *
     * - every row is at least ESTIMATE_MARGIN_PERCENT % plus ESTIMATE_MARGIN_BYTES below the estimate of its job;
     * - for rows that differ only in size, the estimate grows between the two largest sizes by at least the growth of
     *   the peak plus ESTIMATE_MARGIN_PERCENT %, so that beyond the largest size measured it grows at least as fast
     *   as each content did between its two largest sizes.
     *
     * The rows cover the content classes of tests/Fixtures/ContentImages (noise, screenshots of flat panels and text,
     * 256-colour screenshots and palette noise, opaque and with transparency) as truecolor, 16-bit and indexed PNG
     * and as GIF, re-encoded as JPEG, CMYK JPEG, BMP (24-bit, and 32-bit bit fields), TIFF and WEBP where the backend
     * reads them, though not every class for every profile. Every profile has rows at 1, 2.1 and 4 megapixels, all
     * but avifenc at effort 5 to 9 at 8.3, most of those that are not AV1 at effort 3 to 9 at 20; there are
     * enlargements from 1 megapixel up to 36, reductions from 8.3 to 2.1, and animations of up to 40 frames. They were
     * measured with the library versions of tests/docker (Ubuntu 24.04). The estimate is therefore an upper bound of
     * what was measured, not of every image. Not measured: other content (photographs among them), larger images, and
     * other library versions, whose encoders can need more. A job that needs more than the ceiling fails: with
     * resource_limit when the limit stops the process, or with conversion_failed when an encoder writes a truncated
     * file and exits with code 0, which the output check rejects.
     */
    final protected static function estimate(
        BackendJob $job,
        int $fixed,
        int $perInputPixel,
        int $perOutputPixel,
        int $perDeepPixel = 0,
        int $perFramePixel = 0,
        int $perResizedPixel = 0
    ): int {
        $input = (float) $job->getInputPixels();
        $frame = (float) $job->getWidth() * $job->getHeight();
        $bytes = $fixed + $perInputPixel * $input + $perOutputPixel * $frame;
        if ($job->getInput()->getBitDepth() > 8) {
            $bytes += $perDeepPixel * max($input, (float) $job->getOutputPixels());
        }
        if ($job->isAnimated()) {
            $bytes += $perFramePixel * (float) $job->getOutputPixels();
        }
        if ($job->isResized()) {
            $bytes += $perResizedPixel * $frame;
        }

        return (int) min(PHP_INT_MAX / 4, $bytes);
    }

    /**
     * The libwebp method (0 to 6) of a job: its effort, from 0 to 9, scaled.
     */
    final protected static function webpMethod(BackendJob $job): int
    {
        return (int) round($job->getEffort() * 6 / 9);
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
     * @throws ConversionFailedException when a program is missing or the job must not run
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
     * The system paths visible in this backend's sandbox for its jobs (the runner's defaults are replaced by them):
     * the programs, the directories of the libraries they load (facts library_N, see probe()), the system files of
     * sandboxData() and the read-only paths the operator added to the runner's defaults. The sandbox mounts them
     * before its private /tmp. Probes the capabilities when they are not loaded yet.
     *
     * @return list<string>
     */
    final public function getSandboxPaths(): array
    {
        return $this->sandboxPathsFor($this->getFacts());
    }

    /**
     * The files of this library that the programs read (worker scripts, configuration), canonical and existing. Each
     * run mounts them read-only after the sandbox's private /tmp, like the input, so that they stay visible when the
     * library is installed below /tmp.
     *
     * @return list<string>
     */
    final public function getSandboxResourcePaths(): array
    {
        $paths = [];
        foreach ($this->sandboxResources() as $path) {
            $real = realpath($path);
            if (is_string($real)) {
                $paths[$real] = $real;
            }
        }

        return array_values($paths);
    }

    /**
     * The runner a job runs with: the memory ceiling as its address space limit (or the given limit, for
     * diagnostics and tests; applied at the rlimit and sandbox levels only), the given environment, this backend's
     * sandbox paths.
     *
     * @param int|null              $memoryBytes address space limit, or null for the memory ceiling
     * @param array<string, string> $environment variables added to the runner's explicit environment
     */
    final public function createJobRunner(?int $memoryBytes = null, array $environment = [], ?float $timeoutSeconds = null): ProcessRunnerInterface
    {
        return $this->runnerFor($memoryBytes, $environment, $timeoutSeconds, $this->getFacts());
    }

    /**
     * The runner of createJobRunner(), with the sandbox paths of the given facts.
     *
     * @param array<string, string> $environment
     * @param array<string, string> $facts
     */
    private function runnerFor(?int $memoryBytes, array $environment, ?float $timeoutSeconds, array $facts): ProcessRunnerInterface
    {
        $options = $this->runner->getOptions();
        $memoryBytes = $memoryBytes !== null ? $memoryBytes : $this->options->getMemoryCeilingBytes();
        $memoryBytes = max(ResourceLimits::MIN_ADDRESS_SPACE_BYTES, min(ResourceLimits::MAX_BYTES, $memoryBytes));
        $options = $options
            ->withResourceLimits($options->getResourceLimits()->withAddressSpaceBytes($memoryBytes))
            ->withEnvironment(array_merge($options->getEnvironment(), $environment))
            ->withSandboxReadOnlyPaths($this->sandboxPathsFor($facts));
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
     * Runs a command in a working directory with the memory ceiling, this backend's environment and its sandbox
     * before the library directories are known (the whole library roots); for discover().
     *
     * @param list<string>               $command
     * @param array<string, string>|null $environment null for environment() without facts
     *
     * @throws ExceptionInterface when the run cannot start
     */
    final protected function execute(array $command, WorkingDirectory $directory, ?array $environment = null): ProcessResult
    {
        return $this->lastDiscoveryResult = $this->runnerFor(
            null,
            $environment !== null ? $environment : $this->environment($directory, []),
            self::PROBE_TIMEOUT_SECONDS,
            []
        )->execute($command, $directory, null, $this->getSandboxResourcePaths());
    }

    /**
     * The result of the last discovery command this backend ran (the failing one when discovery stopped at a failure),
     * or null when it ran none; for diagnostics and tests. Its output can hold paths and messages of the host, which
     * the unavailable reason of the capabilities leaves out.
     */
    final public function getLastDiscoveryResult(): ?ProcessResult
    {
        return $this->lastDiscoveryResult;
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
     * @throws ConversionFailedException when the job must not run (such as a PHP extension that is no longer safe)
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
     * Files and directories of the system the program needs besides itself and the libraries (see getSandboxPaths()).
     *
     * @return list<string>
     */
    protected function sandboxData(): array
    {
        return [];
    }

    /**
     * Files and directories of this library the program needs (see getSandboxResourcePaths()).
     *
     * @return list<string>
     */
    protected function sandboxResources(): array
    {
        return [];
    }

    /**
     * Shared objects the programs load at run time whose own libraries the loader trace must cover, such as a PHP
     * extension; may run commands with execute().
     *
     * @param array<string, string> $programs the located programs, by role
     * @param array<string, string> $facts
     *
     * @throws ExceptionInterface when a run fails to start
     *
     * @return list<string> absolute paths
     */
    protected function tracedObjects(array $programs, WorkingDirectory $directory, array $facts): array
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
            $runner = $this->runnerFor(
                null,
                $this->environment($directory, $facts),
                $timeoutSeconds,
                $facts
            );
            // The command first: it checks the files that the run then mounts (AbstractWorkerBackend).
            $command = $this->command($job, $input, $output, $programs, $facts);
            $result = $runner->execute(
                $command,
                $directory,
                null,
                array_merge([$job->getInputPath()], $this->getSandboxResourcePaths())
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
                    $problem = OutputCheck::problem($job, $copy, $maxBytes);
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
        $unusable = CapabilityCache::unusableReason($this->options);
        if ($unusable !== null) {
            CapabilityCache::warnUnusable($this->options, $unusable, [$this->getName()]);
        }
        $cache = new CapabilityCache(
            $this->options->isCapabilityCacheEnabled() && $unusable === null
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
            $facts = Facts::check(array_merge($facts, $this->libraryFacts($programs, $directory, $facts)));
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
     * The directories of the shared libraries the programs load, as the facts library_0 to library_7: the dynamic
     * loader traces each program (LD_TRACE_LOADED_OBJECTS=1, which loads its libraries without running it) and each
     * object of tracedObjects() ("<loader> --list <object>"). Returns no fact when a trace cannot be read (another C
     * library, a missing library) or holds more than MAX_LIBRARY_DIRECTORIES directories: the sandbox then keeps the
     * whole library roots (SandboxPaths::LIBRARY_DIRECTORIES).
     *
     * @param array<string, string> $programs
     * @param array<string, string> $facts
     *
     * @return array<string, string>
     */
    private function libraryFacts(array $programs, WorkingDirectory $directory, array $facts): array
    {
        if ($this->runner->getLevel() !== IsolationLevel::SANDBOX) {
            // Only the sandbox has an allowlist to narrow.
            return [];
        }
        $trace = ['LD_TRACE_LOADED_OBJECTS' => '1'];
        $directories = [];
        $loader = null;

        try {
            foreach ($programs as $program) {
                $libraries = $this->trace([$program], $directory, $trace);
                if ($libraries === null) {
                    return [];
                }
                $loader = $loader !== null ? $loader : $libraries['loader'];
                foreach ($libraries['libraries'] as $library) {
                    $directories[\dirname($library)] = true;
                }
            }
            $resources = $this->getSandboxResourcePaths();
            foreach ($this->tracedObjects($programs, $directory, $facts) as $object) {
                $libraries = $loader !== null ? $this->trace([$loader, '--list', $object], $directory, []) : null;
                if ($libraries === null) {
                    return [];
                }
                if (!in_array($object, $resources, true)) {
                    // An object that each run mounts by itself does not expose its directory.
                    $directories[\dirname($object)] = true;
                }
                foreach ($libraries['libraries'] as $library) {
                    $directories[\dirname($library)] = true;
                }
            }
        } catch (ExceptionInterface $exception) {
            return [];
        }
        if ($directories === [] || count($directories) > self::MAX_LIBRARY_DIRECTORIES) {
            return [];
        }
        $libraryFacts = [];
        foreach (array_keys($directories) as $index => $path) {
            if (!ExecutableFinder::isAbsolutePath($path) || preg_match('/\A[\x20-\x7E]{1,200}\z/', $path) !== 1) {
                return [];
            }
            $libraryFacts[self::FACT_LIBRARY_PREFIX . $index] = $path;
        }

        return $libraryFacts;
    }

    /**
     * One loader trace, or null when it fails or cannot be read.
     *
     * @param list<string>          $command
     * @param array<string, string> $environment
     *
     * @throws ExceptionInterface when the run cannot start
     *
     * @return array{libraries: list<string>, loader: string|null}|null
     */
    private function trace(array $command, WorkingDirectory $directory, array $environment): ?array
    {
        $result = $this->execute($command, $directory, $environment);

        return $result->isSuccessful() ? LibraryTrace::parse($result->getStdout()) : null;
    }

    /**
     * The sandbox paths of the programs: with the library directories of the facts when there are some, the whole
     * library roots otherwise (discovery, or a trace that could not be read).
     *
     * @param array<string, string> $facts
     *
     * @return list<string>
     */
    private function sandboxPathsFor(array $facts): array
    {
        $programs = array_values(array_filter($this->locatePrograms(), 'is_string'));
        $configured = $this->runner->getOptions()->getSandboxReadOnlyPaths();
        $libraries = [];
        for ($index = 0; isset($facts[self::FACT_LIBRARY_PREFIX . $index]); ++$index) {
            $libraries[] = $facts[self::FACT_LIBRARY_PREFIX . $index];
        }

        return SandboxPaths::forPrograms(
            $programs,
            array_merge(
                $this->sandboxData(),
                array_values(array_diff($configured, ProcessOptions::DEFAULT_SANDBOX_READ_ONLY_PATHS))
            ),
            $libraries !== [] ? $libraries : null
        );
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
            'resources' => $this->sandboxResources(),
        ]);
        if (!is_string($json)) {
            throw new InvalidArgumentException('The backend fingerprint could not be encoded.');
        }

        return $json;
    }
}
