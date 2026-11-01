<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Finds the strongest isolation level this host really provides, by running the same commands ProcessRunner would
 * run, never by looking at versions or configuration alone (L-8: user namespaces are often disabled by Docker's
 * default seccomp profile or Ubuntu's AppArmor restriction, and proc_open() is often disabled on shared hosting).
 *
 * | Level   | Probe (each level needs the ones before it)                                                         |
 * | ------- | --------------------------------------------------------------------------------------------------- |
 * | none    | always available                                                                                    |
 * | process | proc_open() and friends enabled, the posix extension loaded, and `setsid true` exits with 0          |
 * | rlimit  | `setsid prlimit <limits> cat /proc/self/limits` exits with 0 and shows every configured limit        |
 * | sandbox | `setsid prlimit <limits> bwrap <sandbox options> true` exits with 0                                  |
 *
 * Probing costs a few process starts, so the result is cached twice: in memory for the lifetime of the detector, and
 * in a small JSON file shared by the PHP processes of the same user (PHP-FPM workers), valid for the cache TTL. The
 * file name and content are keyed by everything that can change the outcome: the PHP version, SAPI and
 * disable_functions, the effective user, the isolation options, the path, inode, size and modification time of
 * setsid, prlimit, bwrap, true and cat, and the kernel settings that control unprivileged user namespaces. Upgrading
 * bubblewrap or toggling kernel.apparmor_restrict_unprivileged_userns therefore invalidates the cache at once; any
 * other change is picked up when the TTL expires, or with refresh().
 *
 * A level whose probe could not be run at all (no working directory could be created, or ProcessRunner threw: fork()
 * failed, or this PHP process holds too many descriptors for the open files limit) is reported as unavailable, but
 * that result is cached neither in memory nor in the file, because the cause belongs to the moment and to this PHP
 * process rather than to the host: the next detect() probes again.
 *
 * The cache directory must be owned by the effective user and not writable by group or others; a cache file must be
 * a regular file with the same properties (see PrivateCacheDirectory). Anything else is ignored (and the detector
 * probes), so that another local user cannot plant a cached "sandbox" result to defeat a strict policy. Without the
 * posix extension the owner cannot be checked and the file cache is not used.
 */
final class IsolationDetector implements IsolationDetectorInterface
{
    public const DEFAULT_CACHE_TTL_SECONDS = 3600;

    /**
     * Largest accepted cache TTL: one week.
     */
    public const MAX_CACHE_TTL_SECONDS = 604800;

    private const CACHE_SCHEMA = 1;

    private const CACHE_MAX_BYTES = 65536;

    /**
     * Timeout of one probe; starting a sandbox on a loaded host can take a moment.
     */
    private const PROBE_TIMEOUT_SECONDS = 10.0;

    /**
     * Kernel settings that decide whether an unprivileged process may create user namespaces (bubblewrap).
     */
    private const KERNEL_SETTINGS = [
        '/proc/sys/kernel/unprivileged_userns_clone',
        '/proc/sys/user/max_user_namespaces',
        '/proc/sys/kernel/apparmor_restrict_unprivileged_userns',
    ];

    /**
     * Name of the tool each level adds, as used in reasons.
     */
    private const TOOL_NAMES = [
        IsolationLevel::PROCESS => 'setsid',
        IsolationLevel::RLIMIT => 'prlimit',
        IsolationLevel::SANDBOX => 'bubblewrap (bwrap)',
    ];

    /**
     * Rows of /proc/self/limits checked by the rlimit probe.
     */
    private const LIMIT_ROWS = [
        'address_space_bytes' => 'Max address space',
        'cpu_seconds' => 'Max cpu time',
        'file_size_bytes' => 'Max file size',
        'open_files' => 'Max open files',
        'processes' => 'Max processes',
    ];

    /**
     * @var ProcessOptions
     */
    private $options;

    /**
     * @var PrivateCacheDirectory
     */
    private $cacheStore;

    /**
     * @var int
     */
    private $cacheTtlSeconds;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var DetectionResult|null
     */
    private $result;

    /**
     * Whether a probe of the current detection could not be run (see the class description).
     *
     * @var bool
     */
    private $probeFailed = false;

    /**
     * @param ProcessOptions|null  $options         the options the conversions will run with; the probes use them, so
     *                                              that a level is reported only if it works with these limits, tools
     *                                              and sandbox paths
     * @param string|null          $cacheDirectory  directory of the file cache, created with mode 0700 when missing;
     *                                              null for sys_get_temp_dir()/secure-image-converter-<euid>
     * @param int                  $cacheTtlSeconds validity of a cached result, from 0 (no file cache) to
     *                                              MAX_CACHE_TTL_SECONDS
     * @param LoggerInterface|null $logger          receives the detected level (info) and the probe and cache details
     *                                              (debug), including probe output
     *
     * @throws InvalidArgumentException when the TTL is out of range or the directory is not an absolute path
     */
    public function __construct(
        ?ProcessOptions $options = null,
        ?string $cacheDirectory = null,
        int $cacheTtlSeconds = self::DEFAULT_CACHE_TTL_SECONDS,
        ?LoggerInterface $logger = null
    ) {
        if ($cacheTtlSeconds < 0 || $cacheTtlSeconds > self::MAX_CACHE_TTL_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'The isolation cache TTL must be between 0 and %d seconds.',
                self::MAX_CACHE_TTL_SECONDS
            ));
        }
        if ($cacheDirectory !== null && !ExecutableFinder::isAbsolutePath($cacheDirectory)) {
            throw new InvalidArgumentException('The isolation cache directory must be an absolute, normalised path.');
        }
        $this->options = $options === null ? new ProcessOptions() : $options;
        $this->cacheStore = new PrivateCacheDirectory($cacheDirectory);
        $this->cacheTtlSeconds = $cacheTtlSeconds;
        $this->logger = $logger === null ? new NullLogger() : $logger;
    }

    /**
     * The strongest available level: from memory, else from the file cache, else by probing (and caching).
     */
    public function detect(): DetectionResult
    {
        if ($this->result !== null) {
            return $this->result;
        }
        $cacheFile = $this->cacheFile();
        if ($cacheFile !== null) {
            $cached = $this->readCache($cacheFile['path'], $cacheFile['key']);
            if ($cached !== null) {
                return $this->result = $cached;
            }
        }

        return $this->probeAndStore($cacheFile);
    }

    /**
     * Probes again, ignoring both caches, and stores the new result in them.
     */
    public function refresh(): DetectionResult
    {
        return $this->probeAndStore($this->cacheFile());
    }

    /**
     * @param array{path: string, key: string}|null $cacheFile
     */
    private function probeAndStore(?array $cacheFile): DetectionResult
    {
        $result = $this->probe();
        $this->logger->info('Isolation level detected: {level}.', [
            'level' => $result->getLevel(),
            'reasons' => $result->getReasons(),
        ]);
        if ($this->probeFailed) {
            $this->result = null;
            $this->logger->debug('The isolation level {level} is not cached: a probe could not be run.', [
                'level' => $result->getLevel(),
            ]);

            return $result;
        }
        $this->result = $result;
        if ($cacheFile !== null) {
            $this->writeCache($cacheFile['path'], $cacheFile['key'], $result);
        }

        return $result;
    }

    private function probe(): DetectionResult
    {
        $this->probeFailed = false;
        $level = IsolationLevel::NONE;
        $reasons = [];
        $workingDirectory = null;
        try {
            foreach ([IsolationLevel::PROCESS, IsolationLevel::RLIMIT, IsolationLevel::SANDBOX] as $candidate) {
                if ($workingDirectory === null) {
                    try {
                        $workingDirectory = WorkingDirectory::create();
                    } catch (ExceptionInterface $exception) {
                        $this->probeFailed = true;
                        $reasons[$candidate] = 'no private working directory could be created for the probe';
                        break;
                    }
                }
                $reason = $this->probeLevel($candidate, $workingDirectory);
                if ($reason !== null) {
                    $reasons[$candidate] = $reason;
                    break;
                }
                $level = $candidate;
            }
        } finally {
            if ($workingDirectory !== null) {
                $workingDirectory->remove();
            }
        }

        $previous = null;
        foreach (IsolationLevel::all() as $candidate) {
            if (IsolationLevel::rank($candidate) <= IsolationLevel::rank($level)) {
                continue;
            }
            if (!isset($reasons[$candidate])) {
                $reasons[$candidate] = sprintf('it requires the %s level, which is unavailable', (string) $previous);
            }
            $previous = $candidate;
        }

        return new DetectionResult($level, $reasons, time());
    }

    /**
     * Runs the probe of one level.
     *
     * @return string|null why the level is unavailable, or null when it works
     */
    private function probeLevel(string $level, WorkingDirectory $workingDirectory): ?string
    {
        $runner = new ProcessRunner(
            $level,
            $this->options->withTimeoutSeconds(self::PROBE_TIMEOUT_SECONDS)->withMaxOutputBytes(self::CACHE_MAX_BYTES)
        );
        $reason = $runner->getUnavailableReason();
        if ($reason !== null) {
            return $reason;
        }
        $command = $level === IsolationLevel::RLIMIT ? ['cat', '/proc/self/limits'] : ['true'];
        try {
            $result = $runner->execute($command, $workingDirectory);
        } catch (ExceptionInterface $exception) {
            $this->probeFailed = true;
            $this->logger->debug('The {level} isolation probe could not run: {message}', [
                'level' => $level,
                'message' => $exception->getMessage(),
            ]);

            return sprintf('the probe command "%s" could not be run', $command[0]);
        }
        $tool = self::TOOL_NAMES[$level];
        if (!$result->isSuccessful()) {
            $this->logger->debug('The {level} isolation probe failed.', [
                'level' => $level,
                'exit_code' => $result->getExitCode(),
                'signal' => $result->getSignal(),
                'timed_out' => $result->isTimedOut(),
                'stderr' => $result->getStderr(),
            ]);
            if ($result->isTimedOut()) {
                return sprintf(
                    '%s did not run a test command within %d seconds',
                    $tool,
                    (int) self::PROBE_TIMEOUT_SECONDS
                );
            }
            if ($result->getSignal() !== null) {
                return sprintf('%s failed to run a test command (signal %d)', $tool, $result->getSignal());
            }

            return sprintf('%s failed to run a test command (exit code %d)', $tool, (int) $result->getExitCode());
        }
        if ($level === IsolationLevel::RLIMIT) {
            $mismatch = $this->findLimitMismatch($result->getStdout());
            if ($mismatch !== null) {
                $this->logger->debug('The rlimit isolation probe found unexpected limits.', [
                    'limit' => $mismatch,
                    'limits' => $result->getStdout(),
                ]);

                return sprintf('prlimit did not apply the configured limits (%s)', $mismatch);
            }
        }

        return null;
    }

    /**
     * Compares /proc/self/limits, as seen by a process started at the rlimit level, with the configured limits.
     *
     * @return string|null the first limit that differs, or null when all are applied (soft and hard)
     */
    private function findLimitMismatch(string $limits): ?string
    {
        $expected = $this->options->getResourceLimits()->toArray();
        $expected['core_file_bytes'] = 0;
        $rows = self::LIMIT_ROWS + ['core_file_bytes' => 'Max core file size'];
        foreach ($rows as $name => $row) {
            if ($expected[$name] === null) {
                continue;
            }
            $pattern = '/^' . preg_quote($row, '/') . '\s+(\S+)\s+(\S+)/m';
            $value = (string) $expected[$name];
            if (preg_match($pattern, $limits, $match) !== 1 || $match[1] !== $value || $match[2] !== $value) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Where the cached result for the current configuration lives, or null when the file cache is not usable.
     *
     * @return array{path: string, key: string}|null
     */
    private function cacheFile(): ?array
    {
        if ($this->cacheTtlSeconds === 0 || !function_exists('posix_geteuid')) {
            return null;
        }
        $directory = $this->cacheStore->resolve();
        if ($directory === null) {
            $this->logger->debug('The isolation cache directory is not usable: it must be a directory owned by the '
                . 'current user and not writable by group or others.');

            return null;
        }
        $key = $this->cacheKey(posix_geteuid());

        return ['path' => $directory . '/isolation-' . substr($key, 0, 16) . '.json', 'key' => $key];
    }

    private function cacheKey(int $euid): string
    {
        $finder = new ExecutableFinder($this->options->getSearchPath());
        $tools = [];
        foreach ([ProcessOptions::TOOL_SETSID, ProcessOptions::TOOL_PRLIMIT, ProcessOptions::TOOL_BWRAP] as $tool) {
            $tools[$tool] = self::fingerprint($this->options->locateTool($tool, $finder));
        }
        foreach (['true', 'cat'] as $program) {
            $tools[$program] = self::fingerprint($finder->find($program));
        }
        $kernel = [];
        foreach (self::KERNEL_SETTINGS as $setting) {
            $value = @is_readable($setting) ? @file_get_contents($setting, false, null, 0, 64) : false;
            $kernel[$setting] = is_string($value) ? trim($value) : null;
        }

        return hash('sha256', serialize([
            'schema' => self::CACHE_SCHEMA,
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'disable_functions' => (string) ini_get('disable_functions'),
            'posix' => function_exists('posix_kill'),
            'euid' => $euid,
            'options' => $this->options->toIsolationArray(),
            'tools' => $tools,
            'kernel' => $kernel,
        ]));
    }

    /**
     * @return array<string, int|string|false>|null
     */
    private static function fingerprint(?string $path): ?array
    {
        if ($path === null) {
            return null;
        }
        $stat = @stat($path);

        return [
            'path' => $path,
            'real_path' => realpath($path),
            'inode' => is_array($stat) ? $stat['ino'] : false,
            'size' => is_array($stat) ? $stat['size'] : false,
            'mtime' => is_array($stat) ? $stat['mtime'] : false,
        ];
    }

    private function readCache(string $path, string $key): ?DetectionResult
    {
        $contents = $this->cacheStore->read($path, self::CACHE_MAX_BYTES);
        if ($contents === null) {
            return null;
        }
        $data = json_decode($contents, true);
        if (!is_array($data) || ($data['schema'] ?? null) !== self::CACHE_SCHEMA || ($data['key'] ?? null) !== $key
            || !isset($data['result']) || !is_array($data['result'])) {
            $this->logger->debug('The isolation cache file is invalid or belongs to another configuration.');

            return null;
        }
        try {
            $result = DetectionResult::fromArray($data['result'], true);
        } catch (InvalidArgumentException $exception) {
            $this->logger->debug('The isolation cache file holds an invalid result.');

            return null;
        }
        $age = time() - $result->getDetectedAt();
        if ($age < -60 || $age >= $this->cacheTtlSeconds) {
            $this->logger->debug('The isolation cache file has expired.');

            return null;
        }
        $this->logger->debug('Isolation level {level} read from the cache.', ['level' => $result->getLevel()]);

        return $result;
    }

    private function writeCache(string $path, string $key, DetectionResult $result): void
    {
        $json = json_encode(['schema' => self::CACHE_SCHEMA, 'key' => $key, 'result' => $result->toArray()]);
        if (!is_string($json) || !$this->cacheStore->write($path, $json)) {
            $this->logger->debug('The isolation cache file could not be written.');
        }
    }
}
