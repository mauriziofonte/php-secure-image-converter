<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Internal;

use Mfonte\SecureImageConverter\Backend\Internal\CoverCrop;
use Mfonte\SecureImageConverter\Backend\Internal\SandboxPaths;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\CoverCrop
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\SandboxPaths
 */
#[CoversClass(SandboxPaths::class)]
#[CoversClass(CoverCrop::class)]
final class SandboxPathsTest extends TestCase
{
    public function testTheAllowlistHoldsTheProgramChainAndTheLibrariesOnly(): void
    {
        $directory = WorkingDirectory::create();

        try {
            $root = $directory->getPath();
            mkdir($root . '/opt/tool/bin', 0700, true);
            mkdir($root . '/opt/tool/lib', 0700, true);
            mkdir($root . '/bin', 0700);
            touch($root . '/opt/tool/bin/tool-1.2');
            symlink('tool-1.2', $root . '/opt/tool/bin/tool');
            symlink('../opt/tool/bin/tool', $root . '/bin/tool');
            symlink('opt', $root . '/alias');

            $paths = SandboxPaths::forPrograms([$root . '/bin/tool'], [$root . '/alias/tool/lib', $root . '/missing']);

            self::assertContains($root . '/bin/tool', $paths, 'the link is recreated');
            self::assertContains($root . '/opt/tool/bin/tool', $paths);
            self::assertContains($root . '/opt/tool/bin/tool-1.2', $paths, 'the target is mounted');
            self::assertContains($root . '/opt/tool/lib', $paths, 'the libraries of the prefix');
            self::assertContains($root . '/alias', $paths, 'a link among the ancestors is recreated');
            self::assertNotContains($root . '/missing', $paths);
            self::assertNotContains($root . '/opt/tool/bin', $paths, 'only the program, not its directory');
            foreach ($paths as $path) {
                self::assertStringStartsNotWith('/usr/share', $path);
                self::assertStringStartsNotWith('/usr/bin', $path);
                self::assertNotSame('/usr', $path);
                self::assertNotSame('/etc', $path);
            }
            foreach (SandboxPaths::LIBRARY_DIRECTORIES as $library) {
                if (file_exists($library) && !is_link($library)) {
                    self::assertContains($library, $paths);
                }
            }
        } finally {
            $directory->remove();
        }
    }

    public function testCoverCropTakesTheCentredRegion(): void
    {
        self::assertSame([2, 0, 12, 12], CoverCrop::region(16, 12, 6, 6));
        self::assertSame([0, 3, 16, 6], CoverCrop::region(16, 12, 10, 4));
        self::assertSame([0, 0, 16, 12], CoverCrop::region(16, 12, 8, 6));
        self::assertSame([0, 0, 1, 1], CoverCrop::region(1, 1, 100, 100));
    }
}
