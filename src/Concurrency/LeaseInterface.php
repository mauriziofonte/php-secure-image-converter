<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Concurrency;

/**
 * A held semaphore slot or key lock, returned by SemaphoreInterface and KeyedLockInterface.
 *
 * The holder calls release() as soon as its work is done, typically in a finally block. Implementations also release
 * on destruction, so a lease that goes out of scope or is dropped by an exception never stays held, and the operating
 * system releases it when the process dies, so a crashed or killed holder never leaks it.
 */
interface LeaseInterface
{
    /**
     * Releases the slot or lock. Idempotent: calls after the first do nothing. Never throws.
     */
    public function release(): void;

    /**
     * Whether release() was called (explicitly or by the destructor).
     */
    public function isReleased(): bool;
}
