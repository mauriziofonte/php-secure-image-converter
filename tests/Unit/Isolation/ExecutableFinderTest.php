<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Isolation\ExecutableFinder
 */
#[CoversClass(ExecutableFinder::class)]
final class ExecutableFinderTest extends TestCase
{
    /**
     * @var WorkingDirectory|null
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = WorkingDirectory::create();
    }

    protected function tear_down(): void
    {
        if ($this->directory !== null) {
            $this->directory->remove();
        }
        parent::tear_down();
    }

    private function directory(): WorkingDirectory
    {
        self::assertNotNull($this->directory);

        return $this->directory;
    }

    public function testFindsProgramsInTheDirectoriesInOrder(): void
    {
        $first = $this->directory()->pathOf('first');
        $second = $this->directory()->pathOf('second');
        foreach ([$first, $second] as $directory) {
            self::assertTrue(mkdir($directory, 0700));
            file_put_contents($directory . '/tool', "#!/bin/sh\n");
            chmod($directory . '/tool', 0700);
        }
        file_put_contents($first . '/data', 'not executable');
        chmod($first . '/data', 0600);
        mkdir($first . '/folder', 0700);
        file_put_contents($second . '/data', "#!/bin/sh\n");
        chmod($second . '/data', 0700);

        $finder = new ExecutableFinder([$first, $second]);

        self::assertSame([$first, $second], $finder->getDirectories());
        self::assertSame($first . '/tool', $finder->find('tool'));
        self::assertSame($second . '/data', $finder->find('data'), 'a file without execute permission is skipped');
        self::assertNull($finder->find('folder'), 'a directory is not a program');
        self::assertNull($finder->find('missing'));
    }

    public function testAbsolutePathsAreCheckedAsTheyAre(): void
    {
        $finder = new ExecutableFinder([]);
        $shell = (new ExecutableFinder(['/usr/bin', '/bin']))->find('sh');
        self::assertIsString($shell);

        self::assertSame($shell, $finder->find($shell));
        self::assertNull($finder->find('/nonexistent/program'));
        self::assertNull($finder->find($shell . '/'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeNames(): array
    {
        return [
            'empty' => [''],
            'dot' => ['.'],
            'dot dot' => ['..'],
            'relative path' => ['bin/sh'],
            'current directory' => ['./sh'],
            'parent segment' => ['/usr/bin/../bin/sh'],
            'NUL byte' => ["sh\0"],
        ];
    }

    /**
     * @dataProvider unsafeNames
     */
    #[DataProvider('unsafeNames')]
    public function testUnsafeNamesAreNeverResolved(string $name): void
    {
        self::assertNull((new ExecutableFinder(['/usr/bin', '/bin']))->find($name));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function paths(): array
    {
        return [
            'absolute' => ['/usr/bin', true],
            'deep' => ['/a/b-c/d_e/f.g', true],
            'dotfile' => ['/home/.config', true],
            'root' => ['/', false],
            'empty' => ['', false],
            'relative' => ['usr/bin', false],
            'trailing slash' => ['/usr/bin/', false],
            'double slash' => ['/usr//bin', false],
            'dot segment' => ['/usr/./bin', false],
            'dot dot segment' => ['/usr/../bin', false],
            'final dot dot' => ['/usr/..', false],
            'NUL byte' => ["/usr\0/bin", false],
        ];
    }

    /**
     * @dataProvider paths
     */
    #[DataProvider('paths')]
    public function testIsAbsolutePath(string $path, bool $expected): void
    {
        self::assertSame($expected, ExecutableFinder::isAbsolutePath($path));
    }

    public function testInvalidDirectoriesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExecutableFinder(['/usr/bin', 'relative']);
    }
}
