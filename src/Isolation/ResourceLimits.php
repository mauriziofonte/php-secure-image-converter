<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * Kernel resource limits applied with prlimit (util-linux) to every process started at the rlimit or sandbox
 * isolation level. Each limit is set as both the soft and the hard limit, so the process cannot raise it again, and
 * is inherited by everything the process starts.
 *
 * | Limit         | prlimit    | Default  | What happens when it is reached                                         |
 * | ------------- | ---------- | -------- | ----------------------------------------------------------------------- |
 * | address space | `--as`     | 1 GiB    | memory allocations fail: the decoder exits with an error or crashes      |
 * | CPU time      | `--cpu`    | 60 s     | SIGXCPU, then SIGKILL; counts the CPU time of all threads together       |
 * | file size     | `--fsize`  | 256 MiB  | SIGXFSZ when a write would grow a file beyond the limit                  |
 * | open files    | `--nofile` | 128      | open(), socket() and pipe() fail with EMFILE                             |
 * | processes     | `--nproc`  | disabled | fork() fails; see below                                                  |
 * | core size     | `--core`   | 0        | always 0: a crashing decoder never dumps image data to disk (not tunable) |
 *
 * The address space limit bounds virtual memory, which is larger than resident memory: threaded tools reserve
 * address space for every thread stack and malloc arena, so a tool running many threads may need a higher value (or
 * fewer threads, for example VIPS_CONCURRENCY=1 or MALLOC_ARENA_MAX=2 in the process environment).
 *
 * The open files limit caps descriptor numbers, and the process starts with the three standard streams plus every
 * descriptor PHP holds open without close-on-exec (replaced with /dev/null, see ProcessRunner): ProcessRunner refuses
 * to run when these leave too few free descriptors below the limit.
 *
 * The process limit is disabled by default because the kernel counts every process of the user (real user ID), not
 * only the descendants of the command: on a PHP-FPM host whose pool already runs more workers than the limit, the
 * command could not even start. Enable it only when the conversions run under a dedicated user.
 *
 * Immutable value object.
 */
final class ResourceLimits
{
    /**
     * Default address space limit: 1 GiB.
     */
    public const DEFAULT_ADDRESS_SPACE_BYTES = 1073741824;

    /**
     * Default CPU time limit, in seconds, for all threads together.
     */
    public const DEFAULT_CPU_SECONDS = 60;

    /**
     * Default largest file the process may write: 256 MiB.
     */
    public const DEFAULT_FILE_SIZE_BYTES = 268435456;

    /**
     * Default maximum number of open file descriptors.
     */
    public const DEFAULT_OPEN_FILES = 128;

    /**
     * Smallest accepted address space limit (16 MiB): below it no dynamically linked program can start.
     */
    public const MIN_ADDRESS_SPACE_BYTES = 16777216;

    /**
     * Largest accepted byte limit (1 TiB), for the address space and the file size.
     */
    public const MAX_BYTES = 1099511627776;

    /**
     * Largest accepted CPU time limit: one day.
     */
    public const MAX_CPU_SECONDS = 86400;

    /**
     * Smallest accepted open file limit: the three standard streams plus what the dynamic loader and prlimit need.
     */
    public const MIN_OPEN_FILES = 8;

    /**
     * Largest accepted open file and process limit.
     */
    public const MAX_COUNT = 1048576;

    /**
     * @var int
     */
    private $addressSpaceBytes = self::DEFAULT_ADDRESS_SPACE_BYTES;

    /**
     * @var int
     */
    private $cpuSeconds = self::DEFAULT_CPU_SECONDS;

    /**
     * @var int
     */
    private $fileSizeBytes = self::DEFAULT_FILE_SIZE_BYTES;

    /**
     * @var int
     */
    private $openFiles = self::DEFAULT_OPEN_FILES;

    /**
     * @var int|null
     */
    private $processes;

    /**
     * Creates the limits with the documented defaults.
     */
    public function __construct()
    {
    }

    /**
     * Maximum virtual address space of the process, in bytes.
     */
    public function getAddressSpaceBytes(): int
    {
        return $this->addressSpaceBytes;
    }

    /**
     * Maximum CPU time of the process, in seconds, counting all its threads.
     */
    public function getCpuSeconds(): int
    {
        return $this->cpuSeconds;
    }

    /**
     * Largest file the process may create or grow, in bytes.
     */
    public function getFileSizeBytes(): int
    {
        return $this->fileSizeBytes;
    }

    /**
     * Maximum number of file descriptors the process may hold open at once.
     */
    public function getOpenFiles(): int
    {
        return $this->openFiles;
    }

    /**
     * Maximum number of processes of the user that runs the command, or null when not limited (the default).
     */
    public function getProcesses(): ?int
    {
        return $this->processes;
    }

    /**
     * @param int $bytes from MIN_ADDRESS_SPACE_BYTES to MAX_BYTES
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withAddressSpaceBytes(int $bytes): self
    {
        self::assertRange('address space limit', $bytes, self::MIN_ADDRESS_SPACE_BYTES, self::MAX_BYTES);
        $clone = clone $this;
        $clone->addressSpaceBytes = $bytes;

        return $clone;
    }

    /**
     * @param int $seconds from 1 to MAX_CPU_SECONDS
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withCpuSeconds(int $seconds): self
    {
        self::assertRange('CPU time limit', $seconds, 1, self::MAX_CPU_SECONDS);
        $clone = clone $this;
        $clone->cpuSeconds = $seconds;

        return $clone;
    }

    /**
     * @param int $bytes from 1 to MAX_BYTES
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withFileSizeBytes(int $bytes): self
    {
        self::assertRange('file size limit', $bytes, 1, self::MAX_BYTES);
        $clone = clone $this;
        $clone->fileSizeBytes = $bytes;

        return $clone;
    }

    /**
     * @param int $count from MIN_OPEN_FILES to MAX_COUNT
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withOpenFiles(int $count): self
    {
        self::assertRange('open file limit', $count, self::MIN_OPEN_FILES, self::MAX_COUNT);
        $clone = clone $this;
        $clone->openFiles = $count;

        return $clone;
    }

    /**
     * Limits the number of processes of the user that runs the command (see the class description before enabling
     * it), or removes the limit with null.
     *
     * @param int|null $count from 1 to MAX_COUNT, or null for no limit
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withProcesses(?int $count): self
    {
        if ($count !== null) {
            self::assertRange('process limit', $count, 1, self::MAX_COUNT);
        }
        $clone = clone $this;
        $clone->processes = $count;

        return $clone;
    }

    /**
     * The prlimit options that apply these limits, each as one argument ("--as=1073741824"); a single value sets the
     * soft and the hard limit.
     *
     * @return list<string>
     */
    public function toPrlimitArguments(): array
    {
        $arguments = [
            '--as=' . $this->addressSpaceBytes,
            '--cpu=' . $this->cpuSeconds,
            '--fsize=' . $this->fileSizeBytes,
            '--nofile=' . $this->openFiles,
            '--core=0',
        ];
        if ($this->processes !== null) {
            $arguments[] = '--nproc=' . $this->processes;
        }

        return $arguments;
    }

    /**
     * The limits as an array, used to fingerprint a configuration (for example in the detection cache key).
     *
     * @return array{
     *     address_space_bytes: int,
     *     cpu_seconds: int,
     *     file_size_bytes: int,
     *     open_files: int,
     *     processes: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'address_space_bytes' => $this->addressSpaceBytes,
            'cpu_seconds' => $this->cpuSeconds,
            'file_size_bytes' => $this->fileSizeBytes,
            'open_files' => $this->openFiles,
            'processes' => $this->processes,
        ];
    }

    /**
     * @throws InvalidArgumentException when the value is out of range
     */
    private static function assertRange(string $name, int $value, int $minimum, int $maximum): void
    {
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'The %s must be between %d and %d, got %d.',
                $name,
                $minimum,
                $maximum,
                $value
            ));
        }
    }
}
