<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Concurrency;

use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * Per-key exclusive locks for the processes of one host, built on flock(): the lock of a key is the lock file
 * "keyed.<name>.<sha256 of the key>.lock" in a LockDirectory. The key is only ever hashed, so any string is a valid
 * key and none can choose the file name or escape the directory; the name separates independent sets of keys that
 * share a directory.
 *
 * A key has a file only while its lock is held: the holder deletes the file on release, before unlocking, so files
 * do not accumulate with the number of keys. A process that locked a file then checks that the path still names it;
 * when it does not, the previous holder released and deleted it in the meantime, and the process opens the path
 * again. Only a holder that dies leaves its file behind, and the next holder of the key reuses and deletes it.
 *
 * acquire() repeats non-blocking attempts with Backoff pauses until its timeout and then throws
 * ConcurrencyLimitException; tryAcquire() makes one attempt. The returned Lease is released explicitly, on
 * destruction, or by the operating system when the holder dies. The lock is not reentrant: a process that holds a
 * key and asks for it again waits for itself until the timeout.
 *
 * The limitations of LockDirectory apply: local filesystems only (flock() on NFS is unreliable), POSIX systems only,
 * one host, and the lock files must not be deleted by anything else while in use.
 */
final class KeyedLock implements KeyedLockInterface
{
    /**
     * Valid lock names: 1 to 64 ASCII letters, digits, "-" or "_".
     */
    public const NAME_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    /**
     * How many times one attempt opens the path again after locking a file that its previous holder had already
     * deleted, before reporting the key as busy.
     */
    private const REOPEN_LIMIT = 8;

    /**
     * @var LockDirectory
     */
    private $directory;

    /**
     * @var string
     */
    private $name;

    /**
     * @var Backoff
     */
    private $backoff;

    /**
     * @param LockDirectory $directory where the lock files live
     * @param string        $name      identifies this set of keys in the directory; see NAME_PATTERN
     * @param Backoff|null  $backoff   pauses between attempts of acquire(); the Backoff defaults when null
     *
     * @throws InvalidArgumentException when the name is not valid
     */
    public function __construct(LockDirectory $directory, string $name, ?Backoff $backoff = null)
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('A lock name must be 1 to 64 ASCII letters, digits, "-" or "_".');
        }

        $this->directory = $directory;
        $this->name = $name;
        $this->backoff = $backoff ?? new Backoff();
    }

    /**
     * {@inheritDoc}
     *
     * @throws ConcurrencyLimitException when the lock was not released in time
     * @throws InvalidArgumentException  when the key is empty, the timeout is out of range, or the lock file cannot
     *                                   be opened or locked
     *
     * @phpstan-impure
     */
    public function acquire(string $key, float $timeout): LeaseInterface
    {
        Backoff::assertTimeout($timeout);
        $fileName = $this->fileName($key);

        $lease = $this->backoff->retryUntil(function () use ($fileName): ?Lease {
            return $this->attempt($fileName);
        }, $timeout);

        if ($lease === null) {
            // The key itself is never part of the message: it may be raw client input.
            throw ConcurrencyLimitException::afterWaiting($timeout, sprintf(
                'A key of lock "%s" is held by another process, and was not released within %s seconds.',
                $this->name,
                self::formatSeconds($timeout)
            ));
        }

        return $lease;
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidArgumentException when the key is empty, or the lock file cannot be opened or locked
     *
     * @phpstan-impure
     */
    public function tryAcquire(string $key): ?LeaseInterface
    {
        return $this->attempt($this->fileName($key));
    }

    /**
     * Name of this set of keys in its directory.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @throws InvalidArgumentException when the key is empty
     */
    private function fileName(string $key): string
    {
        if ($key === '') {
            throw new InvalidArgumentException('A lock key must not be empty.');
        }

        return sprintf('keyed.%s.%s.lock', $this->name, hash('sha256', $key));
    }

    /**
     * One non-blocking attempt.
     *
     * @throws InvalidArgumentException when the lock file cannot be opened or locked
     */
    private function attempt(string $fileName): ?Lease
    {
        for ($opened = 0; $opened < self::REOPEN_LIMIT; ++$opened) {
            $file = $this->directory->openLockFile($fileName);
            try {
                if (!$file->tryLock()) {
                    return null;
                }
                if ($file->isLinked()) {
                    $lease = new Lease($file, true);
                    $file = null;

                    return $lease;
                }
            } finally {
                if ($file !== null) {
                    $file->close();
                }
            }
            // The file was deleted by its previous holder after this process opened it: open the path again.
        }

        return null;
    }

    /**
     * Seconds for messages, independent of the locale: up to three decimals, without trailing zeros.
     */
    private static function formatSeconds(float $seconds): string
    {
        return rtrim(rtrim(sprintf('%.3F', $seconds), '0'), '.');
    }
}
