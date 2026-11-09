<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Concurrency;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * A held flock() lock on one lock file: a Semaphore slot or a KeyedLock key.
 *
 * Released by release(), by the destructor, or by the operating system when the process dies. A lease cannot be
 * cloned or serialized: a copy would release the lock twice, and a lock cannot outlive its process.
 *
 * A lease that reaches a child process created with pcntl_fork() is released in the parent only: the child closes
 * its copy of the descriptor on release() without unlocking, because unlocking would release the parent's lock
 * (the two share one open file description). Processes started with proc_open() or exec() never inherit it.
 */
final class Lease implements LeaseInterface
{
    /**
     * @var LockFile|null null once released
     */
    private $file;

    /**
     * @var bool
     */
    private $removeOnRelease;

    /**
     * @var int|false process that acquired the lease
     */
    private $ownerPid;

    /**
     * @param LockFile $file            locked file, owned by the lease from now on
     * @param bool     $removeOnRelease whether to delete the lock file on release, while still holding the lock
     *                                  (KeyedLock does, so that one file per key does not accumulate)
     *
     * @internal created by Semaphore and KeyedLock
     */
    public function __construct(LockFile $file, bool $removeOnRelease)
    {
        $this->file = $file;
        $this->removeOnRelease = $removeOnRelease;
        $this->ownerPid = getmypid();
    }

    public function __destruct()
    {
        $this->release();
    }

    public function release(): void
    {
        if ($this->file === null) {
            return;
        }
        $file = $this->file;
        $this->file = null;

        if (getmypid() !== $this->ownerPid) {
            $file->close(false);

            return;
        }
        if ($this->removeOnRelease) {
            $file->unlinkIfLinked();
        }
        $file->close();
    }

    public function isReleased(): bool
    {
        return $this->file === null;
    }

    /**
     * @throws InvalidArgumentException always: a lease cannot be serialized
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new InvalidArgumentException('A lease cannot be serialized.');
    }

    /**
     * Neutralises the instance before refusing it, so that its destructor touches no file.
     *
     * @param array<mixed> $data
     *
     * @throws InvalidArgumentException always: a lease cannot be unserialized
     */
    public function __unserialize(array $data): void
    {
        $this->file = null;

        throw new InvalidArgumentException('A lease cannot be unserialized.');
    }

    /**
     * PHP 7.2 and 7.3, which do not call __serialize().
     *
     * @throws InvalidArgumentException always: a lease cannot be serialized
     *
     * @return list<string>
     */
    public function __sleep(): array
    {
        throw new InvalidArgumentException('A lease cannot be serialized.');
    }

    /**
     * PHP 7.2 and 7.3, which do not call __unserialize(): neutralises the instance before refusing it.
     *
     * @throws InvalidArgumentException always: a lease cannot be unserialized
     */
    public function __wakeup(): void
    {
        $this->file = null;

        throw new InvalidArgumentException('A lease cannot be unserialized.');
    }

    private function __clone()
    {
    }
}
