<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;

/**
 * Runs an external program (a decoder or an encoder) at a fixed isolation level, with a wall-clock timeout, bounded
 * output capture, a sanitised environment and a private working directory.
 *
 * The command is a list of arguments and never goes through a shell: PHP 7.4+ receives the list itself
 * (proc_open() executes it directly); PHP 7.2 and 7.3 can only pass a string to /bin/sh, so every argument is quoted
 * with escapeshellarg() and the line starts with "exec", which replaces the shell with the command. The first
 * argument is resolved to an absolute path with ExecutableFinder before the run.
 *
 * What each level adds (see IsolationLevel and CommandBuilder):
 *
 * - none: proc_open() only. A timeout kills the direct child; programs it started may survive.
 * - process: the command runs in its own session and process group (setsid). A timeout sends SIGTERM to the whole
 *   group, then SIGKILL after the kill grace delay, and whatever is left of the group is killed with SIGKILL after
 *   every run, also a successful one, so that background grandchildren never outlive the run. A descendant that
 *   deliberately starts its own session (setsid()) leaves the group and escapes this; only the sandbox catches it.
 * - rlimit: process, plus ResourceLimits applied with prlimit (address space, CPU time, file size, open files, no
 *   core dumps, optionally processes).
 * - sandbox: rlimit, plus bubblewrap: no network, read-only system, private /tmp, only the working directory
 *   writable, capabilities dropped, and a PID namespace that dies with the run, taking every descendant with it.
 *
 * The runner never runs a command with weaker isolation than its level: when a tool the level needs is missing it
 * throws IsolationUnavailableException instead. IsolationDetector tells which level a host supports, and
 * IsolationPolicy decides which one to use.
 *
 * Standard input is /dev/null, or a file; stdout and stderr are captured through non-blocking pipes polled with
 * stream_select(), up to ProcessOptions::getMaxOutputBytes() each; further output is read and discarded.
 *
 * proc_open() lets the child inherit every descriptor PHP holds open without close-on-exec: in PHP-FPM that can be
 * the FastCGI connection, database and cache sockets or open files, which would give a compromised decoder a way
 * around the sandbox's network isolation. Every descriptor above 2 that is open in PHP when the run starts is
 * therefore replaced with /dev/null in the child.
 *
 * Usage, for a backend:
 *
 *     $workingDirectory = WorkingDirectory::create();
 *     try {
 *         $runner = new ProcessRunner($decision->getLevel(), $options);
 *         $result = $runner->run(['cwebp', '-q', '80', 'in.png', '-o', 'out.webp'], $workingDirectory);
 *         if (!$result->isSuccessful()) { ... }
 *     } finally {
 *         $workingDirectory->remove();
 *     }
 *
 * @phpstan-type ProcStatus array{
 *     command: string,
 *     pid: int,
 *     running: bool,
 *     signaled: bool,
 *     stopped: bool,
 *     exitcode: int,
 *     termsig: int,
 *     stopsig: int
 * }
 */
final class ProcessRunner
{
    private const SIGKILL = 9;

    private const SIGTERM = 15;

    private const READ_CHUNK_BYTES = 65536;

    /**
     * Longest wait in stream_select() before the process status is polled again.
     */
    private const SELECT_SLICE_SECONDS = 0.05;

    /**
     * How long to wait for the process to disappear after SIGKILL.
     */
    private const REAP_TIMEOUT_SECONDS = 5.0;

    /**
     * How long to keep reading output that is still buffered in the pipes after the process ended.
     */
    private const DRAIN_TIMEOUT_SECONDS = 0.2;

    /**
     * Functions the runner needs; any of them may be listed in disable_functions.
     */
    private const REQUIRED_FUNCTIONS = [
        'proc_open',
        'proc_get_status',
        'proc_close',
        'proc_terminate',
        'stream_select',
    ];

    /**
     * @var string
     */
    private $level;

    /**
     * @var ProcessOptions
     */
    private $options;

    /**
     * @var ExecutableFinder
     */
    private $finder;

    /**
     * @var CommandBuilder
     */
    private $builder;

    /**
     * Tools located for the level, or the reason the level is unavailable; computed on first use.
     *
     * @var array{tools: array<string, string>, reason: string|null}|null
     */
    private $requirements;

    /**
     * @param string              $level   IsolationLevel value to enforce on every run
     * @param ProcessOptions|null $options defaults when null
     *
     * @throws InvalidArgumentException when the level is unknown
     */
    public function __construct(string $level, ?ProcessOptions $options = null)
    {
        if (!IsolationLevel::isValid($level)) {
            throw new InvalidArgumentException(sprintf(
                'The isolation level must be one of: %s.',
                implode(', ', IsolationLevel::all())
            ));
        }
        $this->level = $level;
        $this->options = $options === null ? new ProcessOptions() : $options;
        $this->finder = new ExecutableFinder($this->options->getSearchPath());
        $this->builder = new CommandBuilder($this->options);
    }

    /**
     * The IsolationLevel value this runner enforces.
     */
    public function getLevel(): string
    {
        return $this->level;
    }

    public function getOptions(): ProcessOptions
    {
        return $this->options;
    }

    /**
     * Whether PHP can start processes at all: proc_open() and the functions around it are available and not
     * disabled, on a POSIX system.
     */
    public static function isProcOpenAvailable(): bool
    {
        return self::procOpenUnavailableReason() === null;
    }

    /**
     * Why this runner cannot run anything at its level (a fixed English sentence fragment without paths, such as
     * "bubblewrap (bwrap) is not available"), or null when it can.
     */
    public function getUnavailableReason(): ?string
    {
        return $this->requirements()['reason'];
    }

    /**
     * Runs the command and fails when the timeout expires.
     *
     * @param list<string> $command       program and arguments; the program is a name looked up in the search path or
     *                                    an absolute path
     * @param string|null  $stdinPath     readable regular file to use as standard input; /dev/null when null
     * @param list<string> $readOnlyPaths existing absolute paths (typically the input file) mounted read-only in the
     *                                    sandbox; ignored below the sandbox level
     *
     * @throws ConversionTimeoutException    when the wall-clock timeout expired (the process group was killed)
     * @throws IsolationUnavailableException when the level of this runner is not available on the host
     * @throws InvalidArgumentException      when the command, the input or the working directory is invalid
     * @throws ConversionFailedException     when the process cannot be started or its status is lost
     */
    public function run(
        array $command,
        WorkingDirectory $workingDirectory,
        ?string $stdinPath = null,
        array $readOnlyPaths = []
    ): ProcessResult {
        $result = $this->execute($command, $workingDirectory, $stdinPath, $readOnlyPaths);
        if ($result->isTimedOut()) {
            throw new ConversionTimeoutException(sprintf(
                'The process did not finish within its wall-clock timeout of %s seconds and was killed.',
                rtrim(rtrim(sprintf('%.3F', $this->options->getTimeoutSeconds()), '0'), '.')
            ));
        }

        return $result;
    }

    /**
     * Runs the command and reports a timeout in the result instead of throwing. Same parameters as run().
     *
     * @param list<string> $command
     * @param list<string> $readOnlyPaths
     *
     * @throws IsolationUnavailableException when the level of this runner is not available on the host
     * @throws InvalidArgumentException      when the command, the input or the working directory is invalid
     * @throws ConversionFailedException     when the process cannot be started or its status is lost
     */
    public function execute(
        array $command,
        WorkingDirectory $workingDirectory,
        ?string $stdinPath = null,
        array $readOnlyPaths = []
    ): ProcessResult {
        $requirements = $this->requirements();
        if ($requirements['reason'] !== null) {
            throw new IsolationUnavailableException(sprintf(
                'The %s isolation level is unavailable: %s.',
                $this->level,
                $requirements['reason']
            ));
        }
        $command = $this->checkCommand($command);
        if ($stdinPath !== null && (!is_file($stdinPath) || !is_readable($stdinPath))) {
            throw new InvalidArgumentException('The standard input must be a readable regular file.');
        }
        $readOnlyPaths = self::checkReadOnlyPaths($readOnlyPaths);
        $directory = $workingDirectory->getPath();
        if (is_link($directory) || !is_dir($directory)) {
            throw new InvalidArgumentException('The working directory no longer exists.');
        }

        $arguments = $this->builder->build($this->level, $command, $requirements['tools'], $directory, $readOnlyPaths);
        $descriptors = [
            0 => ['file', $stdinPath === null ? '/dev/null' : $stdinPath, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ] + self::inheritedDescriptors();

        return $this->spawn($arguments, $descriptors, $directory, $this->builder->environment($directory));
    }

    /**
     * @param list<string>             $arguments
     * @param array<int, list<string>> $descriptors
     * @param array<string, string>    $environment
     *
     * @throws ConversionFailedException when the process cannot be started or its status is lost
     */
    private function spawn(array $arguments, array $descriptors, string $directory, array $environment): ProcessResult
    {
        $pipes = [];
        $start = self::now();
        // proc_open() warns when fork() fails; the failure is reported by the exception below instead.
        if (PHP_VERSION_ID >= 70400) {
            $process = @proc_open($arguments, $descriptors, $pipes, $directory, $environment);
        } else {
            $command = CommandBuilder::toShellCommand($arguments);
            $process = @proc_open($command, $descriptors, $pipes, $directory, $environment);
        }
        if (!is_resource($process)) {
            throw new ConversionFailedException('The process could not be started.');
        }

        /** @var array<int, resource> $streams */
        $streams = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($streams as $stream) {
            stream_set_blocking($stream, false);
        }
        $output = [1 => '', 2 => ''];
        $truncated = [1 => false, 2 => false];
        $status = null;
        $timedOut = false;
        $pid = 0;
        $deadline = $start + $this->options->getTimeoutSeconds();

        try {
            // Before PHP 8.3 only the call that reaps the process reports its exit status: keep this one.
            $initial = proc_get_status($process);
            $pid = self::pidOf($initial);
            $status = self::interpret($initial);
            if ($status === null) {
                $status = $this->pump($process, $streams, $output, $truncated, $deadline);
            }
            if ($status === null) {
                $timedOut = true;
                $this->signal($process, $pid, self::SIGTERM);
                $status = $this->pump(
                    $process,
                    $streams,
                    $output,
                    $truncated,
                    self::now() + $this->options->getKillGraceSeconds()
                );
                if ($status === null) {
                    $this->signal($process, $pid, self::SIGKILL);
                    $reapDeadline = self::now() + self::REAP_TIMEOUT_SECONDS;
                    $status = $this->pump($process, $streams, $output, $truncated, $reapDeadline);
                }
            }
            // Background descendants that stayed in the process group must not outlive the run.
            $this->killGroup($pid);
            $this->drain($streams, $output, $truncated, self::now() + self::DRAIN_TIMEOUT_SECONDS);
        } finally {
            foreach ($streams as $stream) {
                fclose($stream);
            }
            if ($status === null) {
                // Not reaped (an exception, or a process stuck in the kernel after SIGKILL): kill it again and let
                // the resource go without the blocking wait of proc_close().
                $this->signal($process, $pid, self::SIGKILL);
            } else {
                proc_close($process);
            }
        }
        $duration = max(0.0, self::now() - $start);

        if ($status === null) {
            return $this->result(null, self::SIGKILL, true, $duration, $output, $truncated);
        }
        if ($status['signal'] === null && $status['exit'] === null) {
            throw new ConversionFailedException(
                'The exit status of the process was lost: another part of the application reaped it.'
            );
        }

        return $this->result($status['exit'], $status['signal'], $timedOut, $duration, $output, $truncated);
    }

    /**
     * Descriptor specifications that replace, in the child, every descriptor above 2 open in this PHP process with
     * /dev/null, so that none of them is inherited. The open descriptors are listed from /proc/self/fd (Linux) or
     * /dev/fd.
     *
     * @return array<int, list<string>>
     */
    private static function inheritedDescriptors(): array
    {
        $entries = @scandir('/proc/self/fd');
        if (!is_array($entries)) {
            $entries = @scandir('/dev/fd');
        }
        $descriptors = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (preg_match('/\A[0-9]{1,9}\z/', $entry) === 1 && (int) $entry > 2) {
                $descriptors[(int) $entry] = ['file', '/dev/null', 'r'];
            }
        }

        return $descriptors;
    }

    /**
     * Reads the output and polls the process until it ends or $until passes.
     *
     * @param resource             $process
     * @param array<int, resource> $streams   open output pipes; closed ones are removed
     * @param array<int, string>   $output
     * @param array<int, bool>     $truncated
     *
     * @return array{exit: int|null, signal: int|null}|null how the process ended, or null when it is still running
     */
    private function pump($process, array &$streams, array &$output, array &$truncated, float $until): ?array
    {
        $sleep = 0.001;
        while (true) {
            $status = self::interpret(proc_get_status($process));
            if ($status !== null) {
                return $status;
            }
            $remaining = $until - self::now();
            if ($remaining <= 0.0) {
                return null;
            }
            if ($streams !== []) {
                $this->read($streams, $output, $truncated, min($remaining, self::SELECT_SLICE_SECONDS));
            } else {
                // Both pipes are closed but the process is still running: poll with a short, growing sleep.
                usleep((int) ceil(min($remaining, $sleep) * 1000000));
                $sleep = min($sleep * 2, 0.02);
            }
        }
    }

    /**
     * Reads what is left in the pipes, until they are closed or $until passes.
     *
     * @param array<int, resource> $streams
     * @param array<int, string>   $output
     * @param array<int, bool>     $truncated
     */
    private function drain(array &$streams, array &$output, array &$truncated, float $until): void
    {
        while ($streams !== []) {
            $remaining = $until - self::now();
            if ($remaining <= 0.0) {
                return;
            }
            $this->read($streams, $output, $truncated, min($remaining, self::SELECT_SLICE_SECONDS));
        }
    }

    /**
     * Waits up to $timeout seconds for output, then reads one chunk from each ready pipe.
     *
     * @param array<int, resource> $streams
     * @param array<int, string>   $output
     * @param array<int, bool>     $truncated
     */
    private function read(array &$streams, array &$output, array &$truncated, float $timeout): void
    {
        $ready = $streams;
        $write = null;
        $except = null;
        $seconds = (int) floor($timeout);
        $microseconds = (int) (($timeout - $seconds) * 1000000);
        // stream_select() warns and returns false when a signal interrupts it; the caller simply polls again.
        $count = @stream_select($ready, $write, $except, $seconds, $microseconds);
        if ($count === false) {
            usleep(1000);

            return;
        }
        foreach ($ready as $stream) {
            $key = array_search($stream, $streams, true);
            if (!is_int($key)) {
                continue;
            }
            // A read error (false) counts as the end of the stream.
            $chunk = (string) @fread($stream, self::READ_CHUNK_BYTES);
            if ($chunk === '') {
                if (feof($stream)) {
                    fclose($stream);
                    unset($streams[$key]);
                }
                continue;
            }
            $room = $this->options->getMaxOutputBytes() - strlen($output[$key]);
            if (strlen($chunk) > $room) {
                $truncated[$key] = true;
                $chunk = $room > 0 ? (string) substr($chunk, 0, $room) : '';
            }
            $output[$key] .= $chunk;
        }
    }

    /**
     * @param ProcStatus|false $status what proc_get_status() returned
     *
     * @return array{exit: int|null, signal: int|null}|null how the process ended (both null when its status was
     *                                                      lost), or null while it is running
     */
    private static function interpret($status): ?array
    {
        if (!is_array($status)) {
            return ['exit' => null, 'signal' => null];
        }
        if ($status['running']) {
            return null;
        }
        if ($status['signaled']) {
            return ['exit' => null, 'signal' => (int) $status['termsig']];
        }
        $exit = (int) $status['exitcode'];

        // -1: the process was reaped elsewhere (for example by a SIGCHLD handler), its status is unknown.
        return ['exit' => $exit >= 0 && $exit <= 255 ? $exit : null, 'signal' => null];
    }

    /**
     * @param ProcStatus|false $status what proc_get_status() returned
     */
    private static function pidOf($status): int
    {
        return is_array($status) ? $status['pid'] : 0;
    }

    /**
     * Sends a signal to the process, and to its whole process group from the process level on.
     *
     * @param resource $process
     */
    private function signal($process, int $pid, int $signal): void
    {
        if ($this->usesProcessGroup() && $pid > 1) {
            // setsid made the child the leader of a new process group whose ID is the child's PID: -$pid addresses
            // the child and every descendant that stayed in the group. Should the signal arrive before setsid ran,
            // no group with that ID exists yet (a PID in use is never a process group ID of another process) and
            // only the direct kill below applies.
            posix_kill(-$pid, $signal);
            posix_kill($pid, $signal);

            return;
        }
        proc_terminate($process, $signal);
    }

    /**
     * Kills whatever is left of the process group after the child ended.
     */
    private function killGroup(int $pid): void
    {
        if ($this->usesProcessGroup() && $pid > 1) {
            posix_kill(-$pid, self::SIGKILL);
        }
    }

    private function usesProcessGroup(): bool
    {
        return IsolationLevel::isAtLeast($this->level, IsolationLevel::PROCESS);
    }

    /**
     * @param array<int, string> $output
     * @param array<int, bool>   $truncated
     */
    private function result(
        ?int $exitCode,
        ?int $signal,
        bool $timedOut,
        float $duration,
        array $output,
        array $truncated
    ): ProcessResult {
        return new ProcessResult(
            $exitCode,
            $signal,
            $timedOut,
            $duration,
            $output[1],
            $output[2],
            $truncated[1],
            $truncated[2],
            $this->level
        );
    }

    /**
     * @return array{tools: array<string, string>, reason: string|null}
     */
    private function requirements(): array
    {
        if ($this->requirements !== null) {
            return $this->requirements;
        }

        $reason = self::procOpenUnavailableReason();
        $tools = [];
        $needed = [
            IsolationLevel::PROCESS => ProcessOptions::TOOL_SETSID,
            IsolationLevel::RLIMIT => ProcessOptions::TOOL_PRLIMIT,
            IsolationLevel::SANDBOX => ProcessOptions::TOOL_BWRAP,
        ];
        if ($reason === null && $this->usesProcessGroup() && !function_exists('posix_kill')) {
            $reason = 'the posix extension is not loaded';
        }
        foreach ($needed as $level => $tool) {
            if ($reason !== null || !IsolationLevel::isAtLeast($this->level, $level)) {
                break;
            }
            $path = $this->options->locateTool($tool, $this->finder);
            if ($path === null) {
                $reason = sprintf(
                    '%s is not available (not found, not executable or disabled)',
                    $tool === ProcessOptions::TOOL_BWRAP ? 'bubblewrap (bwrap)' : $tool
                );
                break;
            }
            $tools[$tool] = $path;
        }

        return $this->requirements = ['tools' => $tools, 'reason' => $reason];
    }

    private static function procOpenUnavailableReason(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'process isolation is not supported on Windows';
        }
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
        foreach (self::REQUIRED_FUNCTIONS as $function) {
            if (!function_exists($function) || in_array($function, $disabled, true)) {
                return sprintf('%s() is disabled', $function);
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $command
     *
     * @throws InvalidArgumentException when the command is not a non-empty list of strings or the program is missing
     *
     * @return list<string> the command with an absolute program path
     */
    private function checkCommand(array $command): array
    {
        if ($command === [] || array_values($command) !== $command) {
            throw new InvalidArgumentException('The command must be a non-empty list of arguments.');
        }
        $checked = [];
        foreach ($command as $argument) {
            if (!is_string($argument) || strpos($argument, "\0") !== false) {
                throw new InvalidArgumentException('Command arguments must be strings without NUL bytes.');
            }
            $checked[] = $argument;
        }
        $program = $this->finder->find($checked[0]);
        if ($program === null) {
            $name = basename($checked[0]);

            throw new InvalidArgumentException(sprintf(
                'The program %s was not found or is not executable.',
                preg_match('/\A[A-Za-z0-9._+-]{1,64}\z/', $name) === 1 ? '"' . $name . '"' : 'to run'
            ));
        }
        $checked[0] = $program;

        return $checked;
    }

    /**
     * @param array<mixed> $paths
     *
     * @throws InvalidArgumentException when a path is not an existing absolute, normalised path
     *
     * @return list<string>
     */
    private static function checkReadOnlyPaths(array $paths): array
    {
        $checked = [];
        foreach ($paths as $path) {
            if (!is_string($path) || !ExecutableFinder::isAbsolutePath($path) || !file_exists($path)) {
                throw new InvalidArgumentException('Read-only paths must be existing absolute, normalised paths.');
            }
            $checked[$path] = $path;
        }

        return array_values($checked);
    }

    /**
     * Monotonic time in seconds where available (PHP 7.3+), wall-clock time otherwise.
     */
    private static function now(): float
    {
        $nanoseconds = function_exists('hrtime') ? hrtime(true) : false;
        if (is_int($nanoseconds)) {
            return $nanoseconds / 1e9;
        }

        return microtime(true);
    }
}
