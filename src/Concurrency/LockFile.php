<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Concurrency;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * One open lock file of a LockDirectory, locked with flock().
 *
 * flock() locks belong to the open file description: two handles opened separately on the same file exclude each
 * other even inside one process, and the lock is released when the last descriptor sharing the description is
 * closed, which the kernel does when the process dies. Instances are created by LockDirectory::openLockFile() and
 * owned by one Semaphore or KeyedLock call, then by the Lease that holds the lock.
 *
 * @internal
 */
final class LockFile
{
    /**
     * @var string
     */
    private $path;

    /**
     * @var resource|null null once closed
     */
    private $handle;

    /**
     * @param string   $path   absolute path the handle was opened from
     * @param resource $handle stream opened by LockDirectory::openLockFile()
     */
    public function __construct(string $path, $handle)
    {
        $this->path = $path;
        $this->handle = $handle;
    }

    /**
     * Tries to take the exclusive lock without waiting.
     *
     * @throws InvalidArgumentException when the file is closed, or flock() fails for a reason other than the lock
     *                                  being held (for example a filesystem without flock() support)
     *
     * @return bool true when the lock was taken, false when another open file description holds it
     *
     * @phpstan-impure
     */
    public function tryLock(): bool
    {
        if ($this->handle === null) {
            throw new InvalidArgumentException('The lock file is closed.');
        }

        $wouldBlock = 0;
        if (flock($this->handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            return true;
        }
        if ($wouldBlock !== 0) {
            return false;
        }

        throw new InvalidArgumentException('flock() failed on a lock file; the lock directory must be on a local filesystem.');
    }

    /**
     * Whether the path still names the file this handle has open. It does not once the file was removed, or removed
     * and created again, by another process.
     *
     * @phpstan-impure
     */
    public function isLinked(): bool
    {
        if ($this->handle === null) {
            return false;
        }

        clearstatcache(true, $this->path);
        $onDisk = @stat($this->path);
        $open = fstat($this->handle);

        return $onDisk !== false && $open !== false
            && $onDisk['dev'] === $open['dev'] && $onDisk['ino'] === $open['ino'];
    }

    /**
     * Removes the file from its directory when the path still names the open file; never removes another file. Call
     * it while holding the lock, before close(): a process that locks the removed file afterwards sees, through
     * isLinked(), that it lost the race and opens the path again.
     */
    public function unlinkIfLinked(): void
    {
        if ($this->isLinked()) {
            @unlink($this->path);
        }
    }

    /**
     * Releases the lock (when this handle holds it) and closes the handle. Idempotent; never throws.
     *
     * @param bool $unlock false to close without unlocking, in a forked child process: the child shares the open file
     *                     description with its parent, and unlocking it would release the parent's lock
     */
    public function close(bool $unlock = true): void
    {
        if ($this->handle === null) {
            return;
        }
        $handle = $this->handle;
        $this->handle = null;

        if ($unlock) {
            // Explicit, because a descriptor duplicated into another process (fork) would keep the lock after
            // fclose().
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }

    /**
     * Whether close() was called.
     */
    public function isClosed(): bool
    {
        return $this->handle === null;
    }
}
