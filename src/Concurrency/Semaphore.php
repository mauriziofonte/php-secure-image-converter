<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Concurrency;

use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * Counting semaphore for the processes of one host, built on flock(): N slots are N lock files in a LockDirectory,
 * named "semaphore.<name>.<slot>.lock", and a process holds a slot while it holds the exclusive lock of its file.
 *
 * An attempt opens every slot file and tries each one with a non-blocking lock, starting from a random slot so that
 * concurrent callers spread over the files; acquire() repeats the attempt with Backoff pauses until its timeout and
 * then throws ConcurrencyLimitException. The returned Lease is released explicitly, on destruction, or by the
 * operating system when the holder dies, so a crashed or killed process never leaks a slot.
 *
 * Every process that shares the semaphore must use the same directory, name and number of slots: a process
 * configured with fewer slots uses a subset of the files, one with more slots adds files the others ignore. The
 * semaphore is not reentrant: each acquire() call takes its own slot, also inside one process.
 *
 * Slots are not handed out in arrival order (see Backoff): a waiter can time out while later callers, or a holder that
 * acquires again right after releasing, take every freed slot. Take one lease per request and hold it for the whole
 * request: a request that converts several images, such as a batch of the API's v1 endpoint, acquires once and
 * converts them all under that lease, instead of releasing and acquiring again for each image, which would let it
 * starve the requests that are waiting.
 *
 * The limitations of LockDirectory apply: local filesystems only (flock() on NFS is unreliable), POSIX systems only,
 * one host, and the lock files must not be deleted while in use.
 */
final class Semaphore implements SemaphoreInterface
{
    /**
     * Largest number of slots. An attempt keeps one file descriptor open per slot.
     */
    public const MAX_SLOTS = 256;

    /**
     * Valid semaphore names: 1 to 64 ASCII letters, digits, "-" or "_".
     */
    public const NAME_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    /**
     * @var LockDirectory
     */
    private $directory;

    /**
     * @var string
     */
    private $name;

    /**
     * @var int
     */
    private $slots;

    /**
     * @var Backoff
     */
    private $backoff;

    /**
     * @param LockDirectory $directory where the slot files live
     * @param string        $name      identifies this semaphore in the directory; see NAME_PATTERN
     * @param int           $slots     number of slots, from 1 to MAX_SLOTS
     * @param Backoff|null  $backoff   pauses between attempts of acquire(); the Backoff defaults when null
     *
     * @throws InvalidArgumentException when the name or the number of slots is not valid
     */
    public function __construct(LockDirectory $directory, string $name, int $slots, ?Backoff $backoff = null)
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('A semaphore name must be 1 to 64 ASCII letters, digits, "-" or "_".');
        }
        if ($slots < 1 || $slots > self::MAX_SLOTS) {
            throw new InvalidArgumentException(sprintf(
                'A semaphore must have from 1 to %d slots, got %d.',
                self::MAX_SLOTS,
                $slots
            ));
        }

        $this->directory = $directory;
        $this->name = $name;
        $this->slots = $slots;
        $this->backoff = $backoff ?? new Backoff();
    }

    /**
     * {@inheritDoc}
     *
     * @throws ConcurrencyLimitException when no slot became free in time
     * @throws InvalidArgumentException  when the timeout is out of range or a slot file cannot be opened or locked
     *
     * @phpstan-impure
     */
    public function acquire(float $timeout): LeaseInterface
    {
        Backoff::assertTimeout($timeout);

        $files = $this->openSlotFiles();
        try {
            $lease = $this->backoff->retryUntil(function () use (&$files): ?Lease {
                return $this->lockAnySlot($files);
            }, $timeout);
        } finally {
            self::closeAll($files);
        }

        if ($lease === null) {
            throw ConcurrencyLimitException::afterWaiting($timeout, sprintf(
                'All %d slots of semaphore "%s" are busy, and none became free within %s seconds.',
                $this->slots,
                $this->name,
                self::formatSeconds($timeout)
            ));
        }

        return $lease;
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidArgumentException when a slot file cannot be opened or locked
     *
     * @phpstan-impure
     */
    public function tryAcquire(): ?LeaseInterface
    {
        $files = $this->openSlotFiles();
        try {
            return $this->lockAnySlot($files);
        } finally {
            self::closeAll($files);
        }
    }

    public function getSlots(): int
    {
        return $this->slots;
    }

    /**
     * Name of the semaphore in its directory.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Opens every slot file, each with its own open file description, so that the locks taken through them exclude
     * every other holder, including another acquire() call of this process.
     *
     * @throws InvalidArgumentException when a slot file cannot be opened
     *
     * @return array<int, LockFile> slot number => open, unlocked file
     */
    private function openSlotFiles(): array
    {
        $files = [];
        try {
            for ($slot = 0; $slot < $this->slots; ++$slot) {
                $files[$slot] = $this->directory->openLockFile(sprintf('semaphore.%s.%d.lock', $this->name, $slot));
            }
        } catch (\Throwable $exception) {
            self::closeAll($files);

            throw $exception;
        }

        return $files;
    }

    /**
     * Tries every slot once, from a random one, and hands the first locked file over to a lease, removing it from
     * $files.
     *
     * @param array<int, LockFile> $files open slot files; the leased one is removed
     *
     * @throws InvalidArgumentException when flock() fails for a reason other than a busy slot
     */
    private function lockAnySlot(array &$files): ?Lease
    {
        $start = random_int(0, $this->slots - 1);
        for ($offset = 0; $offset < $this->slots; ++$offset) {
            $slot = ($start + $offset) % $this->slots;
            if (isset($files[$slot]) && $files[$slot]->tryLock()) {
                $lease = new Lease($files[$slot], false);
                unset($files[$slot]);

                return $lease;
            }
        }

        return null;
    }

    /**
     * Closes files that hold no lock.
     *
     * @param array<int, LockFile> $files
     */
    private static function closeAll(array $files): void
    {
        foreach ($files as $file) {
            $file->close();
        }
    }

    /**
     * Seconds for messages, independent of the locale: up to three decimals, without trailing zeros.
     */
    private static function formatSeconds(float $seconds): string
    {
        return rtrim(rtrim(sprintf('%.3F', $seconds), '0'), '.');
    }
}
