<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Concurrency;

use Mfonte\SecureImageConverter\Concurrency\KeyedLock;
use Mfonte\SecureImageConverter\Concurrency\Lease;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Concurrency\LockFile;
use Mfonte\SecureImageConverter\Concurrency\Semaphore;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Concurrency\Lease
 * @covers \Mfonte\SecureImageConverter\Concurrency\LockFile
 */
#[CoversClass(Lease::class)]
#[CoversClass(LockFile::class)]
final class LeaseTest extends TestCase
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

    public function testLeaseCannotBeCloned(): void
    {
        $lease = $this->semaphore()->acquire(0.0);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('__clone');

        $copy = clone $lease;
    }

    public function testLeaseCannotBeSerialized(): void
    {
        $lease = $this->semaphore()->acquire(0.0);

        $caught = self::thrownBy(static function () use ($lease): void {
            serialize($lease);
        });
        self::assertInstanceOf(InvalidArgumentException::class, $caught);
        self::assertSame('A lease cannot be serialized.', $caught->getMessage());
        self::assertFalse($lease->isReleased());
        self::assertNull($this->semaphore()->tryAcquire(), 'the failed serialization released the lease');
    }

    /**
     * A crafted string must not turn the destructor of a lease into a file deletion primitive (unserialize gadget).
     */
    public function testCraftedLeaseCannotBeUnserializedAndDeletesNothing(): void
    {
        $victim = $this->root . '/victim';
        touch($victim);
        $file = self::serializedObject(LockFile::class, ['path' => serialize($victim), 'handle' => serialize(0)]);
        $payload = self::serializedObject(Lease::class, [
            'file' => $file,
            'removeOnRelease' => serialize(true),
            'ownerPid' => serialize(getmypid()),
        ]);

        $caught = self::thrownBy(static function () use ($payload): void {
            unserialize($payload);
        });
        self::assertInstanceOf(InvalidArgumentException::class, $caught);
        self::assertSame('A lease cannot be unserialized.', $caught->getMessage());
        gc_collect_cycles();

        self::assertFileExists($victim);
    }

    public function testForkedChildClosesItsCopyWithoutReleasingTheParentLock(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('ext-pcntl and ext-posix are needed to fork.');
        }
        self::assertNotNull($this->directory);
        $lock = new KeyedLock($this->directory, 'outputs');
        $lease = $lock->acquire('key', 0.0);

        $pid = pcntl_fork();
        if ($pid === -1) {
            self::markTestSkipped('fork() failed.');
        }
        if ($pid === 0) {
            // Child: release its copy, then die at once, without running PHPUnit's shutdown code.
            $lease->release();
            posix_kill(posix_getpid(), 9);
            exit(1);
        }
        pcntl_waitpid($pid, $status);

        self::assertFalse($lease->isReleased());
        self::assertNull($lock->tryAcquire('key'), 'the child released the parent\'s lock');
        self::assertFileExists($this->root . '/locks/keyed.outputs.' . hash('sha256', 'key') . '.lock', 'the child deleted the lock file');
        $lease->release();
        self::assertNotNull($lock->tryAcquire('key'));
    }

    private function semaphore(): Semaphore
    {
        self::assertNotNull($this->directory);

        return new Semaphore($this->directory, 'single', 1);
    }

    /**
     * Serialized object of $class with the given private properties.
     *
     * @param class-string          $class
     * @param array<string, string> $properties property name => serialized value
     */
    private static function serializedObject(string $class, array $properties): string
    {
        $body = '';
        foreach ($properties as $name => $value) {
            $body .= serialize("\0" . $class . "\0" . $name) . $value;
        }

        return sprintf('O:%d:"%s":%d:{%s}', \strlen($class), $class, \count($properties), $body);
    }

    /**
     * Runs the operation and returns what it threw. serialize() and unserialize() do not declare what the magic
     * methods they call may throw, so the exception is caught as a Throwable and checked by the caller.
     */
    private static function thrownBy(callable $operation): ?\Throwable
    {
        try {
            $operation();
        } catch (\Throwable $exception) {
            return $exception;
        }

        return null;
    }
}
