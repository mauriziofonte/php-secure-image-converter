<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Concurrency;

use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;

/**
 * A counting semaphore shared by processes: at most getSlots() leases are held at the same time.
 */
interface SemaphoreInterface
{
    /**
     * Takes a free slot, waiting up to $timeout seconds for one to become free.
     *
     * @param float $timeout longest wait in seconds, from 0 (a single attempt) to Backoff::MAX_TIMEOUT
     *
     * @throws ConcurrencyLimitException when no slot became free in time; its retry hint is derived from $timeout
     *                                   (see ConcurrencyLimitException::afterWaiting())
     *
     * @phpstan-impure
     */
    public function acquire(float $timeout): LeaseInterface;

    /**
     * Takes a free slot without waiting.
     *
     * @return LeaseInterface|null null when every slot is busy
     *
     * @phpstan-impure
     */
    public function tryAcquire(): ?LeaseInterface;

    /**
     * Number of slots: the largest number of leases held at the same time.
     */
    public function getSlots(): int;
}
