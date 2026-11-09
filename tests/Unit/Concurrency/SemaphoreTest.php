<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Concurrency;

use Mfonte\SecureImageConverter\Concurrency\Backoff;
use Mfonte\SecureImageConverter\Concurrency\Lease;
use Mfonte\SecureImageConverter\Concurrency\LeaseInterface;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Concurrency\LockFile;
use Mfonte\SecureImageConverter\Concurrency\Semaphore;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * In-process behaviour of Semaphore. Every acquisition opens its own files, so the leases of one process exclude
 * each other exactly as the leases of different processes do; tests/Integration/Concurrency covers real processes.
 *
 * @covers \Mfonte\SecureImageConverter\Concurrency\Semaphore
 * @covers \Mfonte\SecureImageConverter\Concurrency\Lease
 * @covers \Mfonte\SecureImageConverter\Concurrency\LockFile
 */
#[CoversClass(Semaphore::class)]
#[CoversClass(Lease::class)]
#[CoversClass(LockFile::class)]
final class SemaphoreTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    /**
     * @var LockDirectory|null
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->root = TemporaryDirectory::create();
        $this->directory = new LockDirectory($this->root . '/locks');
    }

    protected function tear_down(): void
    {
        $this->directory = null;
        TemporaryDirectory::remove($this->root);
        parent::tear_down();
    }

    public function testAtMostSlotsLeasesAreHeldAtOnce(): void
    {
        $semaphore = $this->semaphore('conversions', 3);

        $leases = [$semaphore->tryAcquire(), $semaphore->tryAcquire(), $semaphore->tryAcquire()];
        foreach ($leases as $lease) {
            self::assertInstanceOf(LeaseInterface::class, $lease);
            self::assertFalse($lease->isReleased());
        }
        self::assertNull($semaphore->tryAcquire());

        self::assertNotNull($leases[1]);
        $leases[1]->release();
        self::assertTrue($leases[1]->isReleased());
        $replacement = $semaphore->tryAcquire();
        self::assertNotNull($replacement);
        self::assertNull($semaphore->tryAcquire());
    }

    public function testReleaseIsIdempotent(): void
    {
        $semaphore = $this->semaphore('conversions', 1);
        $lease = $semaphore->acquire(0.0);

        $lease->release();
        $lease->release();
        self::assertTrue($lease->isReleased());

        $other = $semaphore->tryAcquire();
        self::assertNotNull($other);
        $lease->release();
        self::assertNull($semaphore->tryAcquire(), 'releasing a released lease again freed someone else\'s slot');
    }

    public function testDroppedLeaseIsReleasedByItsDestructor(): void
    {
        $semaphore = $this->semaphore('conversions', 1);
        $lease = $semaphore->tryAcquire();
        self::assertNotNull($lease);
        self::assertNull($semaphore->tryAcquire());

        $lease = null;

        self::assertNotNull($semaphore->tryAcquire());
    }

    public function testInstancesWithTheSameNameShareTheSlots(): void
    {
        $first = $this->semaphore('shared', 2);
        $second = $this->semaphore('shared', 2);

        $a = $first->tryAcquire();
        $b = $second->tryAcquire();

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNull($first->tryAcquire());
        self::assertNull($second->tryAcquire());
    }

    public function testSemaphoresWithDifferentNamesAreIndependent(): void
    {
        $conversions = $this->semaphore('conversions', 1);
        $fetches = $this->semaphore('fetches', 1);

        $a = $conversions->tryAcquire();

        self::assertNotNull($a);
        self::assertNotNull($fetches->tryAcquire());
        self::assertNull($conversions->tryAcquire());
    }

    public function testSlotFilesAreNamedAfterTheSemaphoreAndKept(): void
    {
        $semaphore = $this->semaphore('conversions', 3);
        $lease = $semaphore->tryAcquire();
        self::assertNotNull($lease);
        $lease->release();

        $files = scandir($this->root . '/locks');
        self::assertIsArray($files);
        self::assertSame(
            ['.', '..', 'semaphore.conversions.0.lock', 'semaphore.conversions.1.lock', 'semaphore.conversions.2.lock'],
            $files
        );
    }

    public function testFullSemaphoreThrowsTheLimitExceptionAtOnceWithZeroTimeout(): void
    {
        $semaphore = $this->semaphore('conversions', 2);
        $held = [$semaphore->acquire(0.0), $semaphore->acquire(0.0)];

        try {
            $semaphore->acquire(0.0);
            self::fail('A third slot was granted.');
        } catch (ConcurrencyLimitException $exception) {
            self::assertSame(ErrorCode::CONCURRENCY_LIMIT, $exception->getErrorCode());
            self::assertSame(1, $exception->getRetryAfterSeconds());
            self::assertSame(
                'All 2 slots of semaphore "conversions" are busy, and none became free within 0 seconds.',
                $exception->getMessage()
            );
        }
        foreach ($held as $lease) {
            self::assertFalse($lease->isReleased());
        }
    }

    public function testFullSemaphoreWaitsForTheTimeoutBeforeThrowing(): void
    {
        $semaphore = $this->semaphore('conversions', 1, new Backoff(0.001, 0.01));
        $held = $semaphore->acquire(0.0);

        $started = microtime(true);
        try {
            $semaphore->acquire(0.25);
            self::fail('A second slot was granted.');
        } catch (ConcurrencyLimitException $exception) {
            $elapsed = microtime(true) - $started;
            self::assertGreaterThanOrEqual(0.25, $elapsed);
            self::assertLessThan(2.0, $elapsed);
            self::assertSame(1, $exception->getRetryAfterSeconds());
            self::assertStringEndsWith('none became free within 0.25 seconds.', $exception->getMessage());
        }
        self::assertFalse($held->isReleased());
    }

    public function testRetryHintFollowsTheTimeout(): void
    {
        $semaphore = $this->semaphore('conversions', 1, new Backoff(0.01, 0.2));
        $held = $semaphore->acquire(0.0);

        try {
            $semaphore->acquire(1.05);
            self::fail('A second slot was granted.');
        } catch (ConcurrencyLimitException $exception) {
            self::assertSame(2, $exception->getRetryAfterSeconds());
        }
        self::assertFalse($held->isReleased());
    }

    public function testNoDescriptorIsLeakedByAttempts(): void
    {
        if (!is_dir('/proc/self/fd')) {
            self::markTestSkipped('/proc is needed to count open descriptors.');
        }
        $semaphore = $this->semaphore('conversions', 4);
        $before = self::openDescriptors();

        for ($round = 0; $round < 25; ++$round) {
            $leases = [];
            while (($lease = $semaphore->tryAcquire()) !== null) {
                $leases[] = $lease;
            }
            try {
                $semaphore->acquire(0.0);
            } catch (ConcurrencyLimitException $exception) {
                // expected: every slot is held
            }
            self::assertCount(4, $leases);
            $leases = [];
        }

        self::assertSame($before, self::openDescriptors());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function invalidConfigurations(): array
    {
        return [
            'empty name' => ['', 1],
            'name with a dot' => ['a.b', 1],
            'name with a separator' => ['a/b', 1],
            'name with a space' => ['a b', 1],
            'name with traversal' => ['..', 1],
            'non-ASCII name' => ['conversión', 1],
            'name of 65 characters' => [str_repeat('a', 65), 1],
            'zero slots' => ['conversions', 0],
            'negative slots' => ['conversions', -1],
            'too many slots' => ['conversions', 257],
        ];
    }

    /**
     * @dataProvider invalidConfigurations
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRefused(string $name, int $slots): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->semaphore($name, $slots);
    }

    public function testBoundaryConfigurationIsAccepted(): void
    {
        $semaphore = $this->semaphore(str_repeat('a', 64), Semaphore::MAX_SLOTS);

        self::assertSame(256, $semaphore->getSlots());
        self::assertSame(str_repeat('a', 64), $semaphore->getName());
    }

    /**
     * @return array<string, array{float}>
     */
    public static function invalidTimeouts(): array
    {
        return [
            'negative' => [-1.0],
            'above one day' => [86401.0],
            'not a number' => [NAN],
        ];
    }

    /**
     * @dataProvider invalidTimeouts
     */
    #[DataProvider('invalidTimeouts')]
    public function testInvalidTimeoutIsRefusedBeforeAnyFileIsOpened(float $timeout): void
    {
        $semaphore = $this->semaphore('conversions', 1);

        try {
            $semaphore->acquire($timeout);
            self::fail('The timeout was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(['.', '..'], scandir($this->root . '/locks'));
        }
    }

    private function semaphore(string $name, int $slots, ?Backoff $backoff = null): Semaphore
    {
        self::assertNotNull($this->directory);

        return new Semaphore($this->directory, $name, $slots, $backoff);
    }

    private static function openDescriptors(): int
    {
        $entries = scandir('/proc/self/fd');
        self::assertIsArray($entries);

        return \count($entries);
    }
}
