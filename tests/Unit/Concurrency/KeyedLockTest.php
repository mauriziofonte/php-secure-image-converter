<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Concurrency;

use Mfonte\SecureImageConverter\Concurrency\Backoff;
use Mfonte\SecureImageConverter\Concurrency\KeyedLock;
use Mfonte\SecureImageConverter\Concurrency\Lease;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Concurrency\LockFile;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * In-process behaviour of KeyedLock. Every acquisition opens its own file, so the leases of one process exclude each
 * other exactly as the leases of different processes do; tests/Integration/Concurrency covers real processes.
 *
 * @covers \Mfonte\SecureImageConverter\Concurrency\KeyedLock
 * @covers \Mfonte\SecureImageConverter\Concurrency\Lease
 * @covers \Mfonte\SecureImageConverter\Concurrency\LockFile
 */
#[CoversClass(KeyedLock::class)]
#[CoversClass(Lease::class)]
#[CoversClass(LockFile::class)]
final class KeyedLockTest extends TestCase
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

    public function testOneHolderPerKeyAndKeysAreIndependent(): void
    {
        $lock = $this->lock('outputs');

        $a = $lock->tryAcquire('a.webp');
        self::assertNotNull($a);
        self::assertNull($lock->tryAcquire('a.webp'), 'the lock is not reentrant, even inside one process');
        $b = $lock->tryAcquire('b.webp');
        self::assertNotNull($b, 'a held key blocked another key');

        $a->release();
        $again = $lock->tryAcquire('a.webp');
        self::assertNotNull($again);
        self::assertFalse($b->isReleased());
    }

    public function testLocksWithDifferentNamesAreIndependent(): void
    {
        $outputs = $this->lock('outputs');
        $fetches = $this->lock('fetches');

        $held = $outputs->tryAcquire('same key');

        self::assertNotNull($held);
        self::assertNotNull($fetches->tryAcquire('same key'));
        self::assertNull($this->lock('outputs')->tryAcquire('same key'));
    }

    public function testLockFileExistsOnlyWhileHeld(): void
    {
        $lock = $this->lock('outputs');

        $lease = $lock->acquire('key', 0.0);
        self::assertSame([self::fileName('outputs', 'key')], $this->lockFiles());

        $lease->release();
        self::assertSame([], $this->lockFiles());
    }

    public function testDroppedLeaseIsReleasedAndItsFileRemoved(): void
    {
        $lock = $this->lock('outputs');
        $lease = $lock->tryAcquire('key');
        self::assertNotNull($lease);

        $lease = null;

        self::assertSame([], $this->lockFiles());
        self::assertNotNull($lock->tryAcquire('key'));
    }

    public function testFileLeftByADeadHolderIsReusedAndRemoved(): void
    {
        touch($this->root . '/locks/' . self::fileName('outputs', 'key'));

        $lease = $this->lock('outputs')->acquire('key', 0.0);
        $lease->release();

        self::assertSame([], $this->lockFiles());
    }

    public function testReleaseNeverRemovesAFileThatReplacedTheLockFile(): void
    {
        $lease = $this->lock('outputs')->acquire('key', 0.0);
        $path = $this->root . '/locks/' . self::fileName('outputs', 'key');
        unlink($path);
        touch($path);

        $lease->release();

        self::assertFileExists($path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileKeys(): array
    {
        return [
            'parent traversal' => ['../../../../etc/passwd'],
            'absolute path' => ['/etc/passwd'],
            'separator' => ['a/b'],
            'NUL byte' => ["a\0b"],
            'newline' => ["a\nb"],
            'dot' => ['.'],
            'non-UTF-8 bytes' => ["\xff\xfe"],
            'very long' => [str_repeat('x', 100000)],
        ];
    }

    /**
     * @dataProvider hostileKeys
     */
    #[DataProvider('hostileKeys')]
    public function testAnyKeyMapsToAHashedFileInsideTheDirectory(string $key): void
    {
        $lease = $this->lock('outputs')->acquire($key, 0.0);

        self::assertSame([self::fileName('outputs', $key)], $this->lockFiles());
        self::assertMatchesRegularExpression('/\Akeyed\.outputs\.[0-9a-f]{64}\.lock\z/', $this->lockFiles()[0]);
        self::assertSame(['.', '..', 'locks'], scandir($this->root));
        $lease->release();
    }

    public function testHeldKeyThrowsTheLimitExceptionAfterTheTimeout(): void
    {
        $lock = $this->lock('outputs', new Backoff(0.001, 0.01));
        $held = $lock->acquire('secret/../key', 0.0);

        $started = microtime(true);
        try {
            $lock->acquire('secret/../key', 0.2);
            self::fail('The held key was granted twice.');
        } catch (ConcurrencyLimitException $exception) {
            self::assertGreaterThanOrEqual(0.2, microtime(true) - $started);
            self::assertSame(ErrorCode::CONCURRENCY_LIMIT, $exception->getErrorCode());
            self::assertSame(1, $exception->getRetryAfterSeconds());
            self::assertSame(
                'A key of lock "outputs" is held by another process, and was not released within 0.2 seconds.',
                $exception->getMessage()
            );
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
        self::assertFalse($held->isReleased());
    }

    public function testEmptyKeyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A lock key must not be empty.');

        $this->lock('outputs')->tryAcquire('');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'dot' => ['a.b'],
            'separator' => ['a/b'],
            'traversal' => ['..'],
            '65 characters' => [str_repeat('n', 65)],
        ];
    }

    /**
     * @dataProvider invalidNames
     */
    #[DataProvider('invalidNames')]
    public function testInvalidNameIsRefused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->lock($name);
    }

    public function testNameIsReported(): void
    {
        self::assertSame('Outputs_2-x', $this->lock('Outputs_2-x')->getName());
    }

    public function testInvalidTimeoutIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->lock('outputs')->acquire('key', -1.0);
    }

    private function lock(string $name, ?Backoff $backoff = null): KeyedLock
    {
        self::assertNotNull($this->directory);

        return new KeyedLock($this->directory, $name, $backoff);
    }

    private static function fileName(string $name, string $key): string
    {
        return sprintf('keyed.%s.%s.lock', $name, hash('sha256', $key));
    }

    /**
     * @return list<string>
     */
    private function lockFiles(): array
    {
        $entries = scandir($this->root . '/locks');
        self::assertIsArray($entries);

        return array_values(array_diff($entries, ['.', '..']));
    }
}
