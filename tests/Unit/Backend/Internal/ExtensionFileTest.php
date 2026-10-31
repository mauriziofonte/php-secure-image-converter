<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Internal;

use Mfonte\SecureImageConverter\Backend\Internal\ExtensionFile;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A configured extension directory is resolved anew, never from PHP's realpath() cache.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\ExtensionFile
 */
#[CoversClass(ExtensionFile::class)]
final class ExtensionFileTest extends TestCase
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

    public function testALinkRetargetedAfterAResolutionIsFollowedToItsNewTarget(): void
    {
        self::assertNotNull($this->directory);
        $root = $this->directory->getPath();
        foreach (['first', 'second'] as $name) {
            self::assertTrue(mkdir($root . '/' . $name, 0755));
            self::assertTrue(chmod($root . '/' . $name, 0755));
            self::assertSame(3, file_put_contents($root . '/' . $name . '/gd.so', 'elf'));
            self::assertTrue(chmod($root . '/' . $name . '/gd.so', 0644));
        }
        self::assertTrue(symlink($root . '/first', $root . '/extensions'));

        $first = ExtensionFile::resolve($root . '/extensions', 'gd');
        self::assertNull($first->getProblem());
        self::assertSame($root . '/first/gd.so', $first->getPath());

        // realpath() caches the link's target. PHP's own unlink() and symlink() would clear that cache, so the link is
        // retargeted by another process, as an administrator would do it.
        if (!function_exists('exec') || !is_executable('/bin/ln')) {
            self::markTestSkipped('This test needs exec() and /bin/ln.');
        }
        exec('/bin/ln -sfn ' . escapeshellarg($root . '/second') . ' ' . escapeshellarg($root . '/extensions'), $output, $status);
        self::assertSame(0, $status);
        $second = ExtensionFile::resolve($root . '/extensions', 'gd');

        self::assertNull($second->getProblem());
        self::assertSame($root . '/second/gd.so', $second->getPath());
    }
}
