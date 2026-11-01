<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Pipeline\Fake;

use Mfonte\SecureImageConverter\Concurrency\LeaseInterface;

/**
 * A lease that only records its release.
 */
final class FakeLease implements LeaseInterface
{
    /**
     * @var bool
     */
    private $released = false;

    public function release(): void
    {
        $this->released = true;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }
}
