<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Pipeline\Fake;

use Mfonte\SecureImageConverter\Concurrency\LeaseInterface;
use Mfonte\SecureImageConverter\Concurrency\SemaphoreInterface;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;

/**
 * A semaphore of one slot held by the test: acquire() hands out FakeLease objects, or throws as the real semaphore does
 * after waiting when the slot is taken.
 */
final class FakeSemaphore implements SemaphoreInterface
{
    /**
     * @var bool
     */
    public $busy = false;

    /**
     * @var list<float>
     */
    public $waits = [];

    /**
     * @var list<FakeLease>
     */
    public $leases = [];

    public function acquire(float $timeout): LeaseInterface
    {
        $this->waits[] = $timeout;
        if ($this->busy) {
            throw ConcurrencyLimitException::afterWaiting($timeout, 'Every conversion slot is busy.');
        }
        $lease = new FakeLease();
        $this->leases[] = $lease;

        return $lease;
    }

    public function tryAcquire(): ?LeaseInterface
    {
        return $this->busy ? null : $this->acquire(0.0);
    }

    public function getSlots(): int
    {
        return 1;
    }
}
