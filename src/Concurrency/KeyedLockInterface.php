<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Concurrency;

use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;

/**
 * Exclusive locks shared by processes, one per key: at most one lease per key is held at a time, and leases of
 * different keys never wait for each other. Used for single-flight work, such as one conversion per output file or
 * one origin fetch per cache key.
 */
interface KeyedLockInterface
{
    /**
     * Takes the lock of $key, waiting up to $timeout seconds for its holder to release it.
     *
     * @param string $key     any non-empty string
     * @param float  $timeout longest wait in seconds, from 0 (a single attempt) to Backoff::MAX_TIMEOUT
     *
     * @throws ConcurrencyLimitException when the lock was not released in time; its retry hint is derived from
     *                                   $timeout (see ConcurrencyLimitException::afterWaiting())
     *
     * @phpstan-impure
     */
    public function acquire(string $key, float $timeout): LeaseInterface;

    /**
     * Takes the lock of $key without waiting.
     *
     * @param string $key any non-empty string
     *
     * @return LeaseInterface|null null when another holder has the lock
     *
     * @phpstan-impure
     */
    public function tryAcquire(string $key): ?LeaseInterface;
}
