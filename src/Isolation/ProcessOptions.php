<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * How ProcessRunner starts and bounds a process, and how IsolationDetector probes the host. Immutable value object;
 * every with*() method returns a modified copy.
 *
 * | Option                  | Default                         | Meaning                                         |
 * | ----------------------- | ------------------------------- | ----------------------------------------------- |
 * | timeout                 | 30 s                            | wall-clock budget of one run                    |
 * | kill grace              | 1 s                             | SIGTERM to SIGKILL delay after a timeout        |
 * | max output bytes        | 1 MiB                           | stdout and stderr kept, each                    |
 * | resource limits         | see ResourceLimits              | prlimit, at the rlimit and sandbox levels       |
 * | environment             | LANG=C, LC_ALL=C                | variables set explicitly                        |
 * | inherited environment   | none                            | variable names copied from PHP's own            |
 * | search path             | DEFAULT_SEARCH_PATH             | where commands and tools are found; also PATH   |
 * | sandbox read-only paths | DEFAULT_SANDBOX_READ_ONLY_PATHS | host paths visible, read-only, in the sandbox   |
 * | tool paths              | looked up in the search path    | setsid, prlimit and bwrap; null disables a tool |
 *
 * The process environment is built from scratch: nothing is inherited from the PHP process (which may hold
 * database passwords, API keys or proxy credentials) except the variable names explicitly listed with
 * withInheritedEnvironment(). PATH (the search path), HOME, TMPDIR and PWD (the working directory) are always set by
 * the runner and cannot be overridden.
 */
final class ProcessOptions
{
    /**
     * util-linux setsid: starts the command in a new session and process group, so that a timeout kills the
     * command together with every descendant that stayed in its group.
     */
    public const TOOL_SETSID = 'setsid';

    /**
     * util-linux prlimit: applies ResourceLimits before the command starts.
     */
    public const TOOL_PRLIMIT = 'prlimit';

    /**
     * bubblewrap: runs the command in new namespaces (sandbox level).
     */
    public const TOOL_BWRAP = 'bwrap';

    /**
     * Default wall-clock budget of one run, in seconds.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 30.0;

    /**
     * Default delay between SIGTERM and SIGKILL after a timeout, in seconds.
     */
    public const DEFAULT_KILL_GRACE_SECONDS = 1.0;

    /**
     * Default output capture per stream: 1 MiB.
     */
    public const DEFAULT_MAX_OUTPUT_BYTES = 1048576;

    /**
     * Largest accepted timeout: one day.
     */
    public const MAX_TIMEOUT_SECONDS = 86400.0;

    /**
     * Largest accepted delay between SIGTERM and SIGKILL.
     */
    public const MAX_KILL_GRACE_SECONDS = 60.0;

    /**
     * Largest accepted output capture per stream: 256 MiB.
     */
    public const MAX_OUTPUT_BYTES = 268435456;

    /**
     * Default directories where commands and tools are looked up; also the PATH of the process.
     */
    public const DEFAULT_SEARCH_PATH = [
        '/usr/local/bin',
        '/usr/bin',
        '/bin',
        '/usr/local/sbin',
        '/usr/sbin',
        '/sbin',
    ];

    /**
     * System paths mounted read-only in the sandbox when they exist: programs and shared libraries (a top-level
     * symbolic link such as /bin -> usr/bin on merged-/usr systems is recreated as a link) and the dynamic loader
     * configuration. Debian's /etc/alternatives is included because many commands in /usr/bin link through it.
     * Nothing else of the host is visible: no /home, no /var, no other file of /etc, no /tmp.
     */
    public const DEFAULT_SANDBOX_READ_ONLY_PATHS = [
        '/usr',
        '/bin',
        '/sbin',
        '/lib',
        '/lib32',
        '/lib64',
        '/libx32',
        '/etc/alternatives',
        '/etc/ld.so.cache',
        '/etc/ld.so.conf',
        '/etc/ld.so.conf.d',
    ];

    /**
     * Variables that the runner always sets itself.
     */
    public const RESERVED_ENVIRONMENT = ['PATH', 'HOME', 'TMPDIR', 'PWD'];

    /**
     * @var float
     */
    private $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS;

    /**
     * @var float
     */
    private $killGraceSeconds = self::DEFAULT_KILL_GRACE_SECONDS;

    /**
     * @var int
     */
    private $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES;

    /**
     * @var ResourceLimits
     */
    private $resourceLimits;

    /**
     * @var array<string, string>
     */
    private $environment = ['LANG' => 'C', 'LC_ALL' => 'C'];

    /**
     * @var list<string>
     */
    private $inheritedEnvironment = [];

    /**
     * @var list<string>
     */
    private $searchPath = self::DEFAULT_SEARCH_PATH;

    /**
     * @var list<string>
     */
    private $sandboxReadOnlyPaths = self::DEFAULT_SANDBOX_READ_ONLY_PATHS;

    /**
     * Explicitly configured tools: an absolute path, or null when the tool must not be used. Tools not listed are
     * looked up in the search path.
     *
     * @var array<string, string|null>
     */
    private $toolPaths = [];

    /**
     * Creates the options with the documented defaults.
     */
    public function __construct()
    {
        $this->resourceLimits = new ResourceLimits();
    }

    /**
     * Wall-clock budget of one run, in seconds. When it expires the process group receives SIGTERM, then SIGKILL
     * after the kill grace delay.
     */
    public function getTimeoutSeconds(): float
    {
        return $this->timeoutSeconds;
    }

    /**
     * @param float $seconds greater than 0 and at most MAX_TIMEOUT_SECONDS
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withTimeoutSeconds(float $seconds): self
    {
        if (!($seconds > 0.0 && $seconds <= self::MAX_TIMEOUT_SECONDS)) {
            throw new InvalidArgumentException(sprintf(
                'The timeout must be greater than 0 and at most %d seconds.',
                (int) self::MAX_TIMEOUT_SECONDS
            ));
        }
        $clone = clone $this;
        $clone->timeoutSeconds = $seconds;

        return $clone;
    }

    /**
     * Delay between SIGTERM and SIGKILL after a timeout, in seconds.
     */
    public function getKillGraceSeconds(): float
    {
        return $this->killGraceSeconds;
    }

    /**
     * @param float $seconds from 0 (SIGKILL right after SIGTERM) to MAX_KILL_GRACE_SECONDS
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withKillGraceSeconds(float $seconds): self
    {
        if (!($seconds >= 0.0 && $seconds <= self::MAX_KILL_GRACE_SECONDS)) {
            throw new InvalidArgumentException(sprintf(
                'The kill grace delay must be between 0 and %d seconds.',
                (int) self::MAX_KILL_GRACE_SECONDS
            ));
        }
        $clone = clone $this;
        $clone->killGraceSeconds = $seconds;

        return $clone;
    }

    /**
     * Bytes of stdout, and separately of stderr, kept in the result. Further output is read and discarded, so that
     * the process never blocks on a full pipe, and the result reports the truncation.
     */
    public function getMaxOutputBytes(): int
    {
        return $this->maxOutputBytes;
    }

    /**
     * @param int $bytes from 0 to MAX_OUTPUT_BYTES
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withMaxOutputBytes(int $bytes): self
    {
        if ($bytes < 0 || $bytes > self::MAX_OUTPUT_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'The output limit must be between 0 and %d bytes.',
                self::MAX_OUTPUT_BYTES
            ));
        }
        $clone = clone $this;
        $clone->maxOutputBytes = $bytes;

        return $clone;
    }

    public function getResourceLimits(): ResourceLimits
    {
        return $this->resourceLimits;
    }

    public function withResourceLimits(ResourceLimits $limits): self
    {
        $clone = clone $this;
        $clone->resourceLimits = $limits;

        return $clone;
    }

    /**
     * Variables set explicitly in the process environment.
     *
     * @return array<string, string>
     */
    public function getEnvironment(): array
    {
        return $this->environment;
    }

    /**
     * Replaces the explicitly set variables (the defaults LANG=C and LC_ALL=C included).
     *
     * @param array<string, string> $variables names of letters, digits and "_" not starting with a digit, other than
     *                                         RESERVED_ENVIRONMENT; values without NUL bytes
     *
     * @throws InvalidArgumentException when a name or a value is invalid
     */
    public function withEnvironment(array $variables): self
    {
        $clone = clone $this;
        $clone->environment = self::checkEnvironment($variables);

        return $clone;
    }

    /**
     * Names of variables copied from the PHP process environment when they are set there. Explicit variables
     * (getEnvironment()) take precedence.
     *
     * @return list<string>
     */
    public function getInheritedEnvironment(): array
    {
        return $this->inheritedEnvironment;
    }

    /**
     * @param list<string> $names variable names, other than RESERVED_ENVIRONMENT
     *
     * @throws InvalidArgumentException when a name is invalid
     */
    public function withInheritedEnvironment(array $names): self
    {
        $clone = clone $this;
        $clone->inheritedEnvironment = self::checkVariableNames($names);

        return $clone;
    }

    /**
     * Directories where commands and tools given by name are looked up, in order; also the PATH of the process.
     *
     * @return list<string>
     */
    public function getSearchPath(): array
    {
        return $this->searchPath;
    }

    /**
     * @param list<string> $directories one or more absolute, normalised directories
     *
     * @throws InvalidArgumentException when the list is empty or a directory is invalid
     */
    public function withSearchPath(array $directories): self
    {
        $clone = clone $this;
        $clone->searchPath = self::checkPaths($directories, 'search path directories', false);

        return $clone;
    }

    /**
     * Host paths mounted read-only at the same location inside the sandbox, in addition to the working directory
     * (read-write) and the paths passed to a single run. Paths that do not exist on the host are skipped.
     *
     * @return list<string>
     */
    public function getSandboxReadOnlyPaths(): array
    {
        return $this->sandboxReadOnlyPaths;
    }

    /**
     * Replaces the read-only sandbox paths. To add a path to the defaults, pass
     * array_merge(ProcessOptions::DEFAULT_SANDBOX_READ_ONLY_PATHS, [...]). Mounting a directory exposes all of it
     * to the decoder: never add a directory that holds secrets or other users' data.
     *
     * @param list<string> $paths absolute, normalised paths other than the root
     *
     * @throws InvalidArgumentException when a path is invalid
     */
    public function withSandboxReadOnlyPaths(array $paths): self
    {
        $clone = clone $this;
        $clone->sandboxReadOnlyPaths = self::checkPaths($paths, 'sandbox read-only paths', true);

        return $clone;
    }

    /**
     * Sets where a tool is, or disables it with null (the isolation levels that need it then become unavailable).
     *
     * @param string      $tool one of the TOOL_* constants
     * @param string|null $path absolute path of the executable, or null to disable the tool
     *
     * @throws InvalidArgumentException when the tool is unknown or the path is not absolute
     */
    public function withToolPath(string $tool, ?string $path): self
    {
        if (!in_array($tool, [self::TOOL_SETSID, self::TOOL_PRLIMIT, self::TOOL_BWRAP], true)) {
            throw new InvalidArgumentException('The tool must be one of: setsid, prlimit, bwrap.');
        }
        if ($path !== null && !ExecutableFinder::isAbsolutePath($path)) {
            throw new InvalidArgumentException('A tool path must be an absolute, normalised path.');
        }
        $clone = clone $this;
        $clone->toolPaths[$tool] = $path;
        ksort($clone->toolPaths);

        return $clone;
    }

    /**
     * Explicitly configured tools: absolute path, or null for a disabled tool. Tools not listed are looked up in the
     * search path.
     *
     * @return array<string, string|null>
     */
    public function getToolPaths(): array
    {
        return $this->toolPaths;
    }

    /**
     * Absolute path of an executable tool, or null when it is disabled, not found or not executable.
     *
     * @param string $tool one of the TOOL_* constants
     */
    public function locateTool(string $tool, ExecutableFinder $finder): ?string
    {
        if (array_key_exists($tool, $this->toolPaths)) {
            $path = $this->toolPaths[$tool];

            return $path === null ? null : $finder->find($path);
        }

        return $finder->find($tool);
    }

    /**
     * Every option that influences how processes are isolated, used to fingerprint a configuration (for example in
     * the detection cache key). The environment and the output limit are left out.
     *
     * @return array<string, mixed>
     */
    public function toIsolationArray(): array
    {
        return [
            'resource_limits' => $this->resourceLimits->toArray(),
            'search_path' => $this->searchPath,
            'sandbox_read_only_paths' => $this->sandboxReadOnlyPaths,
            'tool_paths' => $this->toolPaths,
        ];
    }

    /**
     * @param array<mixed> $variables
     *
     * @throws InvalidArgumentException when a name or a value is invalid
     *
     * @return array<string, string>
     */
    private static function checkEnvironment(array $variables): array
    {
        $checked = [];
        foreach ($variables as $name => $value) {
            $name = (string) $name;
            self::assertVariableName($name);
            if (!is_string($value) || strpos($value, "\0") !== false) {
                throw new InvalidArgumentException('Environment values must be strings without NUL bytes.');
            }
            $checked[$name] = $value;
        }

        return $checked;
    }

    /**
     * @param array<mixed> $names
     *
     * @throws InvalidArgumentException when a name is invalid
     *
     * @return list<string> the names, without duplicates
     */
    private static function checkVariableNames(array $names): array
    {
        $checked = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Inherited environment variable names must be strings.');
            }
            self::assertVariableName($name);
            $checked[$name] = $name;
        }

        return array_values($checked);
    }

    /**
     * @throws InvalidArgumentException when the name is invalid or reserved
     */
    private static function assertVariableName(string $name): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) !== 1) {
            throw new InvalidArgumentException(
                'Environment variable names must contain letters, digits and "_" only, and not start with a digit.'
            );
        }
        if (in_array(strtoupper($name), self::RESERVED_ENVIRONMENT, true)) {
            throw new InvalidArgumentException(sprintf(
                'The environment variables %s are set by the runner and cannot be configured.',
                implode(', ', self::RESERVED_ENVIRONMENT)
            ));
        }
    }

    /**
     * @param array<mixed> $paths
     *
     * @throws InvalidArgumentException when a path is invalid, or the list is empty and must not be
     *
     * @return list<string>
     */
    private static function checkPaths(array $paths, string $what, bool $allowEmpty): array
    {
        if ($paths === [] && !$allowEmpty) {
            throw new InvalidArgumentException(sprintf('The %s must not be empty.', $what));
        }
        $checked = [];
        foreach ($paths as $path) {
            if (!is_string($path) || !ExecutableFinder::isAbsolutePath($path)) {
                throw new InvalidArgumentException(sprintf(
                    'The %s must be absolute, normalised paths other than the root.',
                    $what
                ));
            }
            $checked[$path] = $path;
        }

        return array_values($checked);
    }
}
