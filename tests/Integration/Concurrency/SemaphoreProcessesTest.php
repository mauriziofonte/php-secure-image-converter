<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Concurrency;

use Mfonte\SecureImageConverter\Concurrency\Lease;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Concurrency\LockFile;
use Mfonte\SecureImageConverter\Concurrency\Semaphore;
use Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency\ConcurrencyWorker;
use Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency\EventLog;
use Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency\TemporaryDirectory;
use Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency\WorkerPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Semaphore across real processes: 50 worker processes started with proc_open() compete for N slots, each logging
 * "start" after it acquired and "end" before it releases in a shared log protected by its own lock (see EventLog).
 *
 * @covers \Mfonte\SecureImageConverter\Concurrency\Semaphore
 * @covers \Mfonte\SecureImageConverter\Concurrency\Lease
 * @covers \Mfonte\SecureImageConverter\Concurrency\LockDirectory
 * @covers \Mfonte\SecureImageConverter\Concurrency\LockFile
 *
 * @group concurrency
 */
#[CoversClass(Semaphore::class)]
#[CoversClass(Lease::class)]
#[CoversClass(LockDirectory::class)]
#[CoversClass(LockFile::class)]
#[Group('concurrency')]
final class SemaphoreProcessesTest extends TestCase
{
    private const WORKERS = 50;

    private const STRESS_WORKERS = 8;

    private const STRESS_ITERATIONS = 250;

    /**
     * @var string
     */
    private $root = '';

    /**
     * @var WorkerPool|null
     */
    private $pool;

    protected function set_up(): void
    {
        parent::set_up();
        $this->root = TemporaryDirectory::create();
        $this->pool = new WorkerPool($this->root);
    }

    protected function tear_down(): void
    {
        if ($this->pool !== null) {
            $this->pool->killAll();
            $this->pool = null;
        }
        TemporaryDirectory::remove($this->root);
        parent::tear_down();
    }

    public function testFiftyWorkersNeverExceedTheSlotsAndAllComplete(): void
    {
        $pool = $this->startWorkers('semaphore', 4, 60.0, 150);

        $pool->waitForAll();

        for ($index = 0; $index < self::WORKERS; ++$index) {
            self::assertSame(ConcurrencyWorker::EXIT_ACQUIRED, $pool->exitCode($index), $pool->errorOutput($index));
            self::assertSame(['outcome' => 'acquired'], $pool->outcome($index));
        }
        $log = new EventLog($this->root . '/events.log');
        self::assertSame(self::WORKERS, $log->getStarts());
        self::assertSame(self::WORKERS, $log->getEnds());
        self::assertLessThanOrEqual(4, $log->getMaxConcurrency(), 'more workers held a slot than the semaphore has');
        self::assertSame(4, $log->getMaxConcurrency(), 'the workers did not use every slot');
    }

    public function testWorkersThatWaitLongerThanTheTimeoutFailWithTheLimitException(): void
    {
        $pool = $this->startWorkers('semaphore', 2, 0.5, 400);

        $pool->waitForAll();

        $acquired = 0;
        $limited = 0;
        for ($index = 0; $index < self::WORKERS; ++$index) {
            $exitCode = $pool->exitCode($index);
            $outcome = $pool->outcome($index);
            self::assertNotNull($outcome, $pool->errorOutput($index));
            if ($exitCode === ConcurrencyWorker::EXIT_ACQUIRED) {
                self::assertSame(['outcome' => 'acquired'], $outcome);
                ++$acquired;
                continue;
            }
            self::assertSame(ConcurrencyWorker::EXIT_LIMIT, $exitCode, $pool->errorOutput($index));
            self::assertSame('limit', $outcome['outcome']);
            self::assertSame(1, $outcome['retryAfter']);
            self::assertIsFloat($outcome['waited']);
            self::assertGreaterThanOrEqual(0.5, $outcome['waited'], 'a worker gave up before its timeout');
            self::assertLessThan(5.0, $outcome['waited']);
            ++$limited;
        }

        self::assertSame(self::WORKERS, $acquired + $limited);
        self::assertGreaterThanOrEqual(2, $acquired);
        self::assertGreaterThanOrEqual(1, $limited);
        $log = new EventLog($this->root . '/events.log');
        self::assertSame($acquired, $log->getStarts());
        self::assertSame($acquired, $log->getEnds());
        self::assertLessThanOrEqual(2, $log->getMaxConcurrency(), 'more workers held a slot than the semaphore has');
    }

    /**
     * Negative control: without the semaphore the same workers overlap far beyond the slot count, so the log analysis
     * of the tests above does detect excess concurrency.
     */
    public function testWorkersWithoutTheSemaphoreDoOverlap(): void
    {
        $pool = $this->startWorkers('unlocked', 4, 0.0, 300);

        $pool->waitForAll();

        for ($index = 0; $index < self::WORKERS; ++$index) {
            self::assertSame(ConcurrencyWorker::EXIT_ACQUIRED, $pool->exitCode($index), $pool->errorOutput($index));
        }
        self::assertGreaterThan(4, (new EventLog($this->root . '/events.log'))->getMaxConcurrency());
    }

    /**
     * Exact overlap detection under maximal contention: the workers acquire and release a 2-slot semaphore in a tight
     * loop, with no hold time and a Backoff of 10 to 500 microseconds. Every holder claims one of two marker
     * directories with mkdir() while it holds its slot (ConcurrencyWorker::stress()), so a third simultaneous holder
     * finds both claimed and is counted as a violation.
     */
    public function testNeverMoreHoldersThanSlotsUnderHighContention(): void
    {
        self::assertNotNull($this->pool);
        $markers = $this->root . '/markers';
        mkdir($markers, 0700);
        for ($id = 0; $id < self::STRESS_WORKERS; ++$id) {
            $this->pool->start([
                'mode' => 'semaphore-stress',
                'id' => $id,
                'lockDir' => $this->root . '/locks',
                'name' => 'conversions',
                'slots' => 2,
                'timeout' => 60.0,
                'iterations' => self::STRESS_ITERATIONS,
                'markerDir' => $markers,
                'backoffInitial' => 0.00001,
                'backoffMax' => 0.0005,
            ], true);
        }
        $this->pool->releaseBarrier(self::STRESS_WORKERS);
        $this->pool->waitForAll();

        $iterations = 0;
        $violations = 0;
        for ($index = 0; $index < self::STRESS_WORKERS; ++$index) {
            self::assertSame(ConcurrencyWorker::EXIT_ACQUIRED, $this->pool->exitCode($index), $this->pool->errorOutput($index));
            $outcome = $this->pool->outcome($index);
            self::assertNotNull($outcome);
            self::assertSame('stress', $outcome['outcome']);
            self::assertIsInt($outcome['iterations']);
            self::assertIsInt($outcome['violations']);
            $iterations += $outcome['iterations'];
            $violations += $outcome['violations'];
        }
        self::assertSame(self::STRESS_WORKERS * self::STRESS_ITERATIONS, $iterations);
        self::assertSame(0, $violations, 'more processes held a slot than the semaphore has');
        self::assertSame(['.', '..'], scandir($markers));
    }

    public function testKilledHolderReleasesItsSlot(): void
    {
        self::assertNotNull($this->pool);
        $options = ['mode' => 'hold-semaphore', 'lockDir' => $this->root . '/locks', 'name' => 'conversions', 'slots' => 2, 'timeout' => 10.0];
        $first = $this->pool->start($options + ['id' => 0]);
        self::assertSame(['outcome' => 'acquired'], $this->pool->waitForOutcome($first));
        $second = $this->pool->start($options + ['id' => 1]);
        self::assertSame(['outcome' => 'acquired'], $this->pool->waitForOutcome($second));

        $semaphore = new Semaphore(new LockDirectory($this->root . '/locks'), 'conversions', 2);
        self::assertNull($semaphore->tryAcquire(), 'both slots should be held by the workers');

        $this->pool->kill($first);
        self::assertSame(9, $this->pool->signal($first));
        $freed = $semaphore->tryAcquire();
        self::assertNotNull($freed, 'the killed worker kept its slot');
        self::assertNull($semaphore->tryAcquire(), 'the surviving worker lost its slot');

        $this->pool->kill($second);
        self::assertNotNull($semaphore->tryAcquire());
    }

    public function testOwnershipChecksWorkWithoutExtPosix(): void
    {
        self::assertNotNull($this->pool);
        $settings = ['disable_functions' => 'posix_geteuid'];
        $accepted = $this->pool->start(['mode' => 'directory', 'lockDir' => $this->root . '/locks'], false, $settings);
        $refused = $this->pool->start(['mode' => 'directory', 'lockDir' => TemporaryDirectory::foreignOwned($this->root)], false, $settings);

        $this->pool->waitForAll();

        self::assertSame(0, $this->pool->exitCode($accepted), $this->pool->errorOutput($accepted));
        self::assertSame(['outcome' => 'directory', 'posix' => false], $this->pool->outcome($accepted));
        self::assertSame(ConcurrencyWorker::EXIT_FAILED, $this->pool->exitCode($refused));
        self::assertStringContainsString(
            sprintf('not by the effective user %d of this process', TemporaryDirectory::effectiveUserId()),
            $this->pool->errorOutput($refused)
        );
    }

    /**
     * Starts WORKERS workers behind the start barrier, then releases them together.
     */
    private function startWorkers(string $mode, int $slots, float $timeout, int $holdMs): WorkerPool
    {
        self::assertNotNull($this->pool);
        for ($id = 0; $id < self::WORKERS; ++$id) {
            $this->pool->start([
                'mode' => $mode,
                'id' => $id,
                'lockDir' => $this->root . '/locks',
                'name' => 'conversions',
                'slots' => $slots,
                'timeout' => $timeout,
                'holdMs' => $holdMs,
                'log' => $this->root . '/events.log',
            ], true);
        }
        $this->pool->releaseBarrier(self::WORKERS);

        return $this->pool;
    }
}
