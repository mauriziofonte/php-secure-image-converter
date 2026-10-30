<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;

/**
 * Outcome of one process run: how it ended, how long it took and what it wrote. Immutable value object.
 *
 * A process either exited (getExitCode() is set) or was terminated by a signal (getSignal() is set), never both.
 * Resource limits show up as follows: the address space limit makes allocations fail, so the program usually exits
 * with an error code or is killed by SIGSEGV or SIGABRT; the CPU limit kills it with SIGXCPU (24) or SIGKILL (9); the
 * file size limit with SIGXFSZ (25). In the sandbox, bubblewrap reports a signal death of the command as exit code
 * 128 plus the signal number.
 */
final class ProcessResult
{
    /**
     * @var int|null
     */
    private $exitCode;

    /**
     * @var int|null
     */
    private $signal;

    /**
     * @var bool
     */
    private $timedOut;

    /**
     * @var float
     */
    private $durationSeconds;

    /**
     * @var string
     */
    private $stdout;

    /**
     * @var string
     */
    private $stderr;

    /**
     * @var bool
     */
    private $stdoutTruncated;

    /**
     * @var bool
     */
    private $stderrTruncated;

    /**
     * @var string
     */
    private $isolationLevel;

    /**
     * @param int|null $exitCode        exit status from 0 to 255, or null when a signal terminated the process
     * @param int|null $signal          number of the terminating signal from 1 to 64, or null when it exited
     * @param bool     $timedOut        whether the wall-clock timeout expired and the process was killed
     * @param float    $durationSeconds wall-clock duration from start to end, at least 0
     * @param string   $isolationLevel  IsolationLevel value the process ran under
     *
     * @throws InvalidArgumentException when a value is outside its documented domain
     */
    public function __construct(
        ?int $exitCode,
        ?int $signal,
        bool $timedOut,
        float $durationSeconds,
        string $stdout,
        string $stderr,
        bool $stdoutTruncated,
        bool $stderrTruncated,
        string $isolationLevel
    ) {
        if (($exitCode === null) === ($signal === null)) {
            throw new InvalidArgumentException('A process result has either an exit code or a signal, not both.');
        }
        if ($exitCode !== null && ($exitCode < 0 || $exitCode > 255)) {
            throw new InvalidArgumentException('An exit code must be between 0 and 255.');
        }
        if ($signal !== null && ($signal < 1 || $signal > 64)) {
            throw new InvalidArgumentException('A signal number must be between 1 and 64.');
        }
        if (!($durationSeconds >= 0.0) || is_infinite($durationSeconds)) {
            throw new InvalidArgumentException('A duration must be a finite number of seconds, at least 0.');
        }
        if (!IsolationLevel::isValid($isolationLevel)) {
            throw new InvalidArgumentException(sprintf(
                'The isolation level must be one of: %s.',
                implode(', ', IsolationLevel::all())
            ));
        }

        $this->exitCode = $exitCode;
        $this->signal = $signal;
        $this->timedOut = $timedOut;
        $this->durationSeconds = $durationSeconds;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->stdoutTruncated = $stdoutTruncated;
        $this->stderrTruncated = $stderrTruncated;
        $this->isolationLevel = $isolationLevel;
    }

    /**
     * Exit status from 0 to 255, or null when a signal terminated the process.
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * Number of the signal that terminated the process (9 for SIGKILL, 15 for SIGTERM, ...), or null when it exited.
     */
    public function getSignal(): ?int
    {
        return $this->signal;
    }

    /**
     * Whether the wall-clock timeout expired and the process group was killed.
     */
    public function isTimedOut(): bool
    {
        return $this->timedOut;
    }

    /**
     * Whether the process exited with status 0 before its timeout.
     */
    public function isSuccessful(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }

    /**
     * Wall-clock duration from the start of the process to its end, in seconds.
     */
    public function getDurationSeconds(): float
    {
        return $this->durationSeconds;
    }

    /**
     * Wall-clock duration in whole milliseconds, rounded up.
     */
    public function getDurationMs(): int
    {
        return (int) ceil($this->durationSeconds * 1000.0);
    }

    /**
     * Captured standard output, at most ProcessOptions::getMaxOutputBytes() bytes.
     */
    public function getStdout(): string
    {
        return $this->stdout;
    }

    /**
     * Captured standard error, at most ProcessOptions::getMaxOutputBytes() bytes.
     */
    public function getStderr(): string
    {
        return $this->stderr;
    }

    /**
     * Whether the process wrote more to stdout than was kept.
     */
    public function isStdoutTruncated(): bool
    {
        return $this->stdoutTruncated;
    }

    /**
     * Whether the process wrote more to stderr than was kept.
     */
    public function isStderrTruncated(): bool
    {
        return $this->stderrTruncated;
    }

    /**
     * IsolationLevel value the process ran under.
     */
    public function getIsolationLevel(): string
    {
        return $this->isolationLevel;
    }
}
