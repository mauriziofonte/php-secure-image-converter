<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Isolation\PrivateCacheDirectory;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The cache directory shared by the isolation detector and the backend capability cache: only a directory and files
 * owned by the effective user and protected from group and others are used.
 *
 * @covers \Mfonte\SecureImageConverter\Isolation\PrivateCacheDirectory
 */
#[CoversClass(PrivateCacheDirectory::class)]
final class PrivateCacheDirectoryTest extends TestCase
{
    /**
     * @var WorkingDirectory|null
     */
    private $parent;

    protected function set_up(): void
    {
        parent::set_up();
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('The private cache directory needs the posix extension.');
        }
        $this->parent = WorkingDirectory::create();
    }

    protected function tear_down(): void
    {
        if ($this->parent !== null) {
            $this->parent->remove();
        }
        parent::tear_down();
    }

    private function path(string $name): string
    {
        self::assertNotNull($this->parent);

        return $this->parent->pathOf($name);
    }

    public function testCreatesTheDirectoryWithMode0700(): void
    {
        $directory = $this->path('cache');
        $previous = umask(0);
        try {
            $resolved = (new PrivateCacheDirectory($directory))->resolve();
        } finally {
            umask($previous);
        }

        self::assertSame($directory, $resolved);
        self::assertSame(0700, fileperms($directory) & 0777);
    }

    public function testADirectoryWritableByOthersIsNotUsed(): void
    {
        $directory = $this->path('cache');
        mkdir($directory, 0700);
        chmod($directory, 0777);

        self::assertNull((new PrivateCacheDirectory($directory))->resolve());
    }

    public function testASymbolicLinkToADirectoryIsNotUsed(): void
    {
        $target = $this->path('target');
        mkdir($target, 0700);
        symlink($target, $this->path('cache'));

        self::assertNull((new PrivateCacheDirectory($this->path('cache')))->resolve());
    }

    public function testTheDefaultDirectoryIsNamedAfterTheEffectiveUser(): void
    {
        $resolved = (new PrivateCacheDirectory())->resolve();

        self::assertSame(
            rtrim(sys_get_temp_dir(), '/') . '/' . PrivateCacheDirectory::DEFAULT_NAME_PREFIX . posix_geteuid(),
            $resolved
        );
    }

    public function testWritesAndReadsAFileWithMode0600(): void
    {
        $store = new PrivateCacheDirectory($this->path('cache'));
        $directory = $store->resolve();
        self::assertNotNull($directory);

        self::assertTrue($store->write($directory . '/entry.json', '{"a":1}'));

        self::assertSame(0600, fileperms($directory . '/entry.json') & 0777);
        self::assertSame('{"a":1}', $store->read($directory . '/entry.json', 1024));
        self::assertSame('{"a"', $store->read($directory . '/entry.json', 4), 'reads at most the given bytes');
        self::assertSame(['entry.json'], array_values(array_diff((array) scandir($directory), ['.', '..'])));
    }

    public function testAMissingFileReadsAsNull(): void
    {
        $store = new PrivateCacheDirectory($this->path('cache'));
        $directory = $store->resolve();
        self::assertNotNull($directory);

        self::assertNull($store->read($directory . '/missing.json', 1024));
    }

    public function testAFileWritableByOthersIsIgnored(): void
    {
        $store = new PrivateCacheDirectory($this->path('cache'));
        $directory = $store->resolve();
        self::assertNotNull($directory);
        file_put_contents($directory . '/entry.json', 'planted');
        chmod($directory . '/entry.json', 0666);

        self::assertNull($store->read($directory . '/entry.json', 1024));
    }

    public function testASymbolicLinkIsNeverFollowed(): void
    {
        $store = new PrivateCacheDirectory($this->path('cache'));
        $directory = $store->resolve();
        self::assertNotNull($directory);
        file_put_contents($this->path('elsewhere.json'), 'planted');
        chmod($this->path('elsewhere.json'), 0600);
        symlink($this->path('elsewhere.json'), $directory . '/entry.json');

        self::assertNull($store->read($directory . '/entry.json', 1024));
    }

    public function testAWriteIntoAMissingDirectoryFails(): void
    {
        self::assertFalse((new PrivateCacheDirectory())->write($this->path('missing') . '/entry.json', 'x'));
    }

    public function testRejectsARelativeDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PrivateCacheDirectory('relative/cache');
    }
}
