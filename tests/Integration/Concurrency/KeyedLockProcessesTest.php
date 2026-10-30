<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Concurrency;

use Mfonte\SecureImageConverter\Concurrency\KeyedLock;
use Mfonte\SecureImageConverter\Concurrency\Lease;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Concurrency\LockFile;
use Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency\ConcurrencyWorker;
use Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency\EventLog;
use Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency\TemporaryDirectory;
use Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency\WorkerPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * KeyedLock across real processes started with proc_open(): mutual exclusion per key, independence across keys, and
 * release by a killed holder.
 *
 * @covers \Mfonte\SecureImageConverter\Concurrency\KeyedLock
 * @covers \Mfonte\SecureImageConverter\Concurrency\Lease
 * @covers \Mfonte\SecureImageConverter\Concurrency\LockDirectory
 * @covers \Mfonte\SecureImageConverter\Concurrency\LockFile
 *
 * @group concurrency
 */
#[CoversClass(KeyedLock::class)]
#[CoversClass(Lease::class)]
#[CoversClass(LockDirectory::class)]
#[CoversClass(LockFile::class)]
#[Group('concurrency')]
final class KeyedLockProcessesTest extends TestCase
{
    private const KEYS = ['alpha.webp', 'beta.webp', 'gamma.webp'];

    private const WORKERS_PER_KEY = 10;

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

    public function testOneHolderPerKeyAndKeysRunInParallel(): void
    {
        self::assertNotNull($this->pool);
        $workers = \count(self::KEYS) * self::WORKERS_PER_KEY;
        for ($id = 0; $id < $workers; ++$id) {
            $this->pool->start([
                'mode' => 'keyed',
                'id' => $id,
                'lockDir' => $this->root . '/locks',
                'name' => 'outputs',
                'key' => self::KEYS[$id % \count(self::KEYS)],
                'timeout' => 60.0,
                'holdMs' => 100,
                'log' => $this->root . '/events.log',
            ], true);
        }
        $this->pool->releaseBarrier($workers);
        $this->pool->waitForAll();

        for ($index = 0; $index < $workers; ++$index) {
            self::assertSame(ConcurrencyWorker::EXIT_ACQUIRED, $this->pool->exitCode($index), $this->pool->errorOutput($index));
        }
        $log = new EventLog($this->root . '/events.log');
        self::assertSame($workers, $log->getStarts());
        self::assertSame($workers, $log->getEnds());
        $perKey = $log->getMaxConcurrencyPerKey();
        ksort($perKey);
        self::assertSame(array_fill_keys(self::KEYS, 1), $perKey, 'two holders of one key overlapped');
        self::assertGreaterThanOrEqual(2, $log->getMaxConcurrency(), 'holders of different keys never ran in parallel');
        // Every holder deleted its file on release.
        self::assertSame(['.', '..'], scandir($this->root . '/locks'));
    }

    public function testKilledHolderReleasesItsKeyAndOtherKeysStayFree(): void
    {
        self::assertNotNull($this->pool);
        $holder = $this->pool->start([
            'mode' => 'hold-keyed',
            'id' => 0,
            'lockDir' => $this->root . '/locks',
            'name' => 'outputs',
            'key' => 'alpha.webp',
            'timeout' => 10.0,
        ]);
        self::assertSame(['outcome' => 'acquired'], $this->pool->waitForOutcome($holder));

        $lock = new KeyedLock(new LockDirectory($this->root . '/locks'), 'outputs');
        self::assertNull($lock->tryAcquire('alpha.webp'), 'the key held by the worker was granted');
        $other = $lock->tryAcquire('beta.webp');
        self::assertNotNull($other, 'a key held by another process blocked a different key');
        $other->release();

        $this->pool->kill($holder);
        self::assertSame(9, $this->pool->signal($holder));
        // The killed holder could not delete its file; the next holder reuses it and deletes it on release.
        self::assertCount(3, scandir($this->root . '/locks') ?: []);
        $lease = $lock->tryAcquire('alpha.webp');
        self::assertNotNull($lease, 'the killed worker kept its key');
        $lease->release();
        self::assertSame(['.', '..'], scandir($this->root . '/locks'));
    }
}
