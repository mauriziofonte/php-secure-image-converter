<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Isolation\WorkingDirectory
 */
#[CoversClass(WorkingDirectory::class)]
final class WorkingDirectoryTest extends TestCase
{
    /**
     * @var list<WorkingDirectory>
     */
    private $created = [];

    protected function tear_down(): void
    {
        foreach ($this->created as $directory) {
            $directory->remove();
        }
        parent::tear_down();
    }

    private function create(?string $parent = null): WorkingDirectory
    {
        $directory = WorkingDirectory::create($parent);
        $this->created[] = $directory;

        return $directory;
    }

    public function testCreatesAPrivateDirectoryWithARandomName(): void
    {
        $previous = umask(0);
        try {
            $first = $this->create();
        } finally {
            umask($previous);
        }
        $second = $this->create();
        $path = $first->getPath();

        self::assertDirectoryExists($path);
        self::assertFalse(is_link($path));
        self::assertSame(0700, fileperms($path) & 0777, 'mode 0700 whatever the umask');
        self::assertSame(realpath(sys_get_temp_dir()), dirname($path));
        self::assertMatchesRegularExpression('/\Asic-[0-9a-f]{24}\z/', basename($path));
        self::assertNotSame($path, $second->getPath());
    }

    public function testCreatesInTheGivenParent(): void
    {
        $parent = $this->create();
        $child = $this->create($parent->getPath());

        self::assertSame($parent->getPath(), dirname($child->getPath()));
    }

    public function testRejectsAMissingParent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkingDirectory::create('/nonexistent/parent/directory');
    }

    public function testRejectsAFileAsParent(): void
    {
        $directory = $this->create();
        file_put_contents($directory->pathOf('file'), '');

        $this->expectException(InvalidArgumentException::class);

        WorkingDirectory::create($directory->pathOf('file'));
    }

    public function testPathOfNamesAnEntry(): void
    {
        $directory = $this->create();

        self::assertSame($directory->getPath() . '/out.webp', $directory->pathOf('out.webp'));
        self::assertSame($directory->getPath() . '/.hidden', $directory->pathOf('.hidden'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'dot' => ['.'],
            'dot dot' => ['..'],
            'slash' => ['a/b'],
            'traversal' => ['../etc/passwd'],
            'NUL byte' => ["a\0b"],
        ];
    }

    /**
     * @dataProvider invalidNames
     */
    #[DataProvider('invalidNames')]
    public function testPathOfRejectsEverythingButAPlainName(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->create()->pathOf($name);
    }

    public function testRemoveDeletesEverythingWithoutFollowingLinks(): void
    {
        $outside = $this->create();
        file_put_contents($outside->pathOf('keep.txt'), 'must survive');
        $directory = $this->create();
        $path = $directory->getPath();

        mkdir($path . '/nested/deeper', 0700, true);
        file_put_contents($path . '/nested/deeper/file', 'x');
        file_put_contents($path . '/readonly', 'x');
        chmod($path . '/readonly', 0400);
        mkdir($path . '/locked', 0700);
        file_put_contents($path . '/locked/file', 'x');
        chmod($path . '/locked', 0000);
        symlink($outside->getPath(), $path . '/link-to-directory');
        symlink($outside->pathOf('keep.txt'), $path . '/link-to-file');

        self::assertTrue($directory->remove());
        self::assertFileDoesNotExist($path);
        self::assertTrue($directory->remove(), 'removing again does nothing');
        self::assertStringEqualsFile($outside->pathOf('keep.txt'), 'must survive');
    }
}
