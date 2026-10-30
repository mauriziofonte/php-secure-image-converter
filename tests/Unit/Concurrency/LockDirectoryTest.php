<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Concurrency;

use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Concurrency\LockFile;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Concurrency\LockDirectory
 * @covers \Mfonte\SecureImageConverter\Concurrency\LockFile
 */
#[CoversClass(LockDirectory::class)]
#[CoversClass(LockFile::class)]
final class LockDirectoryTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    protected function set_up(): void
    {
        parent::set_up();
        $this->root = TemporaryDirectory::create();
    }

    protected function tear_down(): void
    {
        TemporaryDirectory::remove($this->root);
        parent::tear_down();
    }

    public function testMissingDirectoryIsCreatedWithParentsAndModeSevenHundred(): void
    {
        $path = $this->root . '/a/b/locks';

        $directory = new LockDirectory($path);

        self::assertSame($path, $directory->getPath());
        self::assertDirectoryExists($path);
        self::assertSame(0700, self::mode($path));
    }

    public function testCreatedDirectoryIsSevenHundredWhateverTheUmask(): void
    {
        $previous = umask(0277);
        try {
            new LockDirectory($this->root . '/locks');
        } finally {
            umask($previous);
        }

        self::assertSame(0700, self::mode($this->root . '/locks'));
    }

    public function testExistingPrivateDirectoryIsAcceptedAndKeptAsIs(): void
    {
        mkdir($this->root . '/locks', 0700);
        touch($this->root . '/locks/other-file');

        $directory = new LockDirectory($this->root . '/locks/');

        self::assertSame($this->root . '/locks', $directory->getPath());
        self::assertFileExists($this->root . '/locks/other-file');
    }

    public function testSymbolicLinkIsResolvedAndItsTargetChecked(): void
    {
        mkdir($this->root . '/target', 0700);
        symlink($this->root . '/target', $this->root . '/link');

        self::assertSame($this->root . '/target', (new LockDirectory($this->root . '/link'))->getPath());

        chmod($this->root . '/target', 0777);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('writable by its group or by other users (mode 0777)');

        new LockDirectory($this->root . '/link');
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function unsafeModes(): array
    {
        return [
            'world-writable' => [0777, 'writable by its group or by other users (mode 0777)'],
            'world-writable with sticky bit' => [01777, 'writable by its group or by other users (mode 1777)'],
            'other-writable only' => [0702, 'writable by its group or by other users (mode 0702)'],
            'group-writable' => [0770, 'writable by its group or by other users (mode 0770)'],
            'not writable by the owner' => [0500, 'must be readable, writable and searchable by its owner (mode 0500'],
            'not searchable by the owner' => [0600, 'must be readable, writable and searchable by its owner (mode 0600'],
        ];
    }

    /**
     * @dataProvider unsafeModes
     */
    #[DataProvider('unsafeModes')]
    public function testUnsafeModeIsRefused(int $mode, string $message): void
    {
        mkdir($this->root . '/locks', 0700);
        chmod($this->root . '/locks', $mode);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new LockDirectory($this->root . '/locks');
    }

    public function testDirectoryOwnedByAnotherUserIsRefused(): void
    {
        $foreign = TemporaryDirectory::foreignOwned($this->root);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('not by the effective user %d of this process', TemporaryDirectory::effectiveUserId()));

        new LockDirectory($foreign);
    }

    /**
     * @return array<string, array{int, string|null}>
     */
    public static function parentModes(): array
    {
        return [
            'private parent' => [0700, null],
            'parent readable by all' => [0755, null],
            'sticky world-writable parent, as /tmp' => [01777, null],
            'sticky group-writable parent' => [01770, null],
            'world-writable parent without sticky bit' => [0777, 'writable by its group or by other users without the sticky bit (mode 0777)'],
            'group-writable parent without sticky bit' => [0770, 'writable by its group or by other users without the sticky bit (mode 0770)'],
            'other-writable parent without sticky bit' => [0703, 'writable by its group or by other users without the sticky bit (mode 0703)'],
        ];
    }

    /**
     * @dataProvider parentModes
     */
    #[DataProvider('parentModes')]
    public function testParentThatLetsOthersReplaceTheDirectoryIsRefused(int $mode, ?string $message): void
    {
        mkdir($this->root . '/parent', 0700);
        chmod($this->root . '/parent', $mode);

        if ($message !== null) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage(self::holderMessage($this->root . '/parent', $message));
        }

        $directory = new LockDirectory($this->root . '/parent/locks');

        self::assertSame($this->root . '/parent/locks', $directory->getPath());
    }

    public function testEveryAncestorIsChecked(): void
    {
        mkdir($this->root . '/shared/private', 0700, true);
        chmod($this->root . '/shared', 0777);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(self::holderMessage($this->root . '/shared', 'writable by its group or by other users without the sticky bit (mode 0777)'));

        new LockDirectory($this->root . '/shared/private/locks');
    }

    /**
     * @group root
     */
    #[Group('root')]
    public function testParentOwnedByAnotherUserIsRefused(): void
    {
        if (TemporaryDirectory::effectiveUserId() !== 0) {
            self::markTestSkipped('Only root can give a directory to another user.');
        }
        mkdir($this->root . '/foreign', 0755);
        chown($this->root . '/foreign', 65534);
        mkdir($this->root . '/foreign/locks', 0700);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(self::holderMessage($this->root . '/foreign', 'owned by user 65534, who could replace the lock directory.'));

        new LockDirectory($this->root . '/foreign/locks');
    }

    /**
     * Another user creates a world-writable directory in a sticky shared directory (as /tmp), and the victim nests its
     * lock directory in it: the other user could swap the lock directory for their own and hold every key forever.
     *
     * @group root
     */
    #[Group('root')]
    public function testLockDirectoryNestedInAnotherUsersDirectoryUnderASharedTmpIsRefused(): void
    {
        if (TemporaryDirectory::effectiveUserId() !== 0) {
            self::markTestSkipped('Only root can give a directory to another user.');
        }
        mkdir($this->root . '/shared', 0700);
        chmod($this->root . '/shared', 01777);
        mkdir($this->root . '/shared/sic', 0700);
        chmod($this->root . '/shared/sic', 0777);
        chown($this->root . '/shared/sic', 65534);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(self::holderMessage($this->root . '/shared/sic', 'owned by user 65534'));

        new LockDirectory($this->root . '/shared/sic/locks');
    }

    /**
     * The link is safe to follow only while nobody else can replace it: another user who owns the directory that
     * holds it could point it at a second directory, and two processes given the same path would then lock files in
     * different directories, both holding the only slot.
     *
     * @group root
     */
    #[Group('root')]
    public function testLinkInAnotherUsersDirectoryIsRefusedEvenWhenItsTargetIsSafe(): void
    {
        if (TemporaryDirectory::effectiveUserId() !== 0) {
            self::markTestSkipped('Only root can give a directory to another user.');
        }
        mkdir($this->root . '/victim/a', 0700, true);
        mkdir($this->root . '/shared', 0700);
        chmod($this->root . '/shared', 01777);
        mkdir($this->root . '/shared/sic', 0755);
        chown($this->root . '/shared/sic', 65534);
        symlink($this->root . '/victim/a', $this->root . '/shared/sic/locks');
        self::assertSame($this->root . '/victim/a', (new LockDirectory($this->root . '/victim/a'))->getPath());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(self::holderMessage($this->root . '/shared/sic', 'owned by user 65534, who could replace the lock directory.'));

        new LockDirectory($this->root . '/shared/sic/locks');
    }

    /**
     * In a sticky directory such as /tmp, another user can create the link before the application does, and can
     * later delete it and create another one that points elsewhere.
     *
     * @group root
     */
    #[Group('root')]
    public function testLinkOwnedByAnotherUserInAStickyDirectoryIsRefused(): void
    {
        if (TemporaryDirectory::effectiveUserId() !== 0) {
            self::markTestSkipped('Only root can give a symbolic link to another user.');
        }
        mkdir($this->root . '/victim', 0700);
        mkdir($this->root . '/sticky', 0700);
        chmod($this->root . '/sticky', 01777);
        symlink($this->root . '/victim', $this->root . '/sticky/app-locks');
        self::assertTrue(lchown($this->root . '/sticky/app-locks', 65534));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A symbolic link on the path to the lock directory is owned by user 65534, who could replace it.');

        new LockDirectory($this->root . '/sticky/app-locks');
    }

    public function testLinkOwnedByTheEffectiveUserInAStickyDirectoryIsAccepted(): void
    {
        mkdir($this->root . '/target', 0700);
        mkdir($this->root . '/sticky', 0700);
        chmod($this->root . '/sticky', 01777);
        symlink($this->root . '/target', $this->root . '/sticky/app-locks');

        self::assertSame($this->root . '/target', (new LockDirectory($this->root . '/sticky/app-locks'))->getPath());
    }

    public function testLinkInADirectoryOthersCanWriteIsRefusedEvenWhenItsTargetIsSafe(): void
    {
        mkdir($this->root . '/target', 0700);
        mkdir($this->root . '/open', 0700);
        chmod($this->root . '/open', 0777);
        symlink($this->root . '/target', $this->root . '/open/locks');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(self::holderMessage($this->root . '/open', 'writable by its group or by other users without the sticky bit (mode 0777)'));

        new LockDirectory($this->root . '/open/locks');
    }

    public function testEveryLinkOfAChainIsChecked(): void
    {
        mkdir($this->root . '/target', 0700);
        mkdir($this->root . '/open', 0700);
        chmod($this->root . '/open', 0777);
        symlink($this->root . '/target', $this->root . '/open/second');
        symlink($this->root . '/open/second', $this->root . '/first');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(self::holderMessage($this->root . '/open', 'writable by its group or by other users without the sticky bit (mode 0777)'));

        new LockDirectory($this->root . '/first');
    }

    public function testRelativeLinksAndDotDotAreResolvedAsTheKernelDoes(): void
    {
        mkdir($this->root . '/a/b', 0700, true);
        mkdir($this->root . '/target', 0700);
        symlink('../../target', $this->root . '/a/b/up');

        // ".." after a link leaves the link's target, not the directory that holds the link.
        $physical = new LockDirectory($this->root . '/a/b/up/../a/./b//');
        $created = new LockDirectory($this->root . '/a/b/up/new/locks');

        self::assertSame($this->root . '/a/b', $physical->getPath());
        self::assertSame($this->root . '/target/new/locks', $created->getPath());
        self::assertSame(0700, self::mode($this->root . '/target/new'));
        self::assertSame(0700, self::mode($this->root . '/target/new/locks'));
    }

    public function testSymbolicLinkLoopIsRefused(): void
    {
        symlink($this->root . '/loop', $this->root . '/loop');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The path to the lock directory has too many levels of symbolic links.');

        new LockDirectory($this->root . '/loop/locks');
    }

    public function testOpenBasedirThatHidesADirectoryOnTheWayIsNamed(): void
    {
        $project = \dirname(__DIR__, 3);
        $script = 'require $argv[1];'
            . ' try { new ' . LockDirectory::class . '($argv[2]); echo "accepted"; }'
            . ' catch (' . InvalidArgumentException::class . ' $e) { echo $e->getMessage(); }';
        $command = implode(' ', array_map('escapeshellarg', [
            \PHP_BINARY,
            '-d',
            'open_basedir=' . $project . ':' . $this->root,
            '-r',
            $script,
            $project . '/vendor/autoload.php',
            $this->root . '/locks',
        ]));

        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame(
            ['A directory on the path to the lock directory cannot be inspected (open_basedir restriction in effect: every directory from "/" down to the lock directory is checked, so open_basedir must not exclude any of them).'],
            $output
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPaths(): array
    {
        return [
            'empty' => [''],
            'relative' => ['locks'],
            'dot-relative' => ['./locks'],
            'NUL byte' => ["/tmp/locks\0x"],
        ];
    }

    /**
     * @dataProvider invalidPaths
     */
    #[DataProvider('invalidPaths')]
    public function testPathMustBeAbsolute(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The lock directory must be an absolute path.');

        new LockDirectory($path);
    }

    public function testRegularFileIsRefused(): void
    {
        touch($this->root . '/file');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The lock directory path does not name a directory.');

        new LockDirectory($this->root . '/file');
    }

    public function testMessagesNeverContainThePath(): void
    {
        mkdir($this->root . '/locks', 0777);
        chmod($this->root . '/locks', 0777);

        try {
            new LockDirectory($this->root . '/locks');
            self::fail('The world-writable directory was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString($this->root, $exception->getMessage());
        }
    }

    public function testLockFileIsCreatedAndLockedExclusivelyPerOpenFile(): void
    {
        $directory = new LockDirectory($this->root . '/locks');

        $first = $directory->openLockFile('semaphore.test.0.lock');
        $second = $directory->openLockFile('semaphore.test.0.lock');

        self::assertFileExists($this->root . '/locks/semaphore.test.0.lock');
        self::assertTrue($first->tryLock());
        self::assertTrue($first->tryLock(), 'locking again through the same open file is a no-op');
        self::assertFalse($second->tryLock(), 'a second open file of this process is excluded');
        $first->close();
        self::assertTrue($first->isClosed());
        self::assertTrue($second->tryLock());
        $second->close();
        $second->close();
        self::assertTrue($second->isClosed());
    }

    public function testClosedLockFileCannotBeLocked(): void
    {
        $file = (new LockDirectory($this->root . '/locks'))->openLockFile('x.lock');
        $file->close();

        self::assertFalse($file->isLinked());
        $this->expectException(InvalidArgumentException::class);

        $file->tryLock();
    }

    public function testUnlinkRemovesOnlyTheOpenFile(): void
    {
        $directory = new LockDirectory($this->root . '/locks');
        $path = $this->root . '/locks/x.lock';
        $file = $directory->openLockFile('x.lock');
        self::assertTrue($file->isLinked());

        // Another file now has the name: it must survive.
        unlink($path);
        touch($path);
        self::assertFalse($file->isLinked());
        $file->unlinkIfLinked();
        self::assertFileExists($path);

        $current = $directory->openLockFile('x.lock');
        $current->unlinkIfLinked();
        self::assertFileDoesNotExist($path);
        $file->close();
        $current->close();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFileNames(): array
    {
        return [
            'empty' => [''],
            'dot' => ['.'],
            'dot dot' => ['..'],
            'parent traversal' => ['../escape.lock'],
            'separator' => ['a/b.lock'],
            'hidden' => ['.hidden'],
            'empty segment' => ['a..lock'],
            'trailing dot' => ['a.'],
            'NUL byte' => ["a\0.lock"],
            'space' => ['a b.lock'],
        ];
    }

    /**
     * @dataProvider invalidFileNames
     */
    #[DataProvider('invalidFileNames')]
    public function testLockFileNameMustBePlain(string $fileName): void
    {
        $directory = new LockDirectory($this->root . '/locks');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid lock file name.');

        $directory->openLockFile($fileName);
    }

    public function testSymbolicLinkInPlaceOfALockFileIsRefused(): void
    {
        $directory = new LockDirectory($this->root . '/locks');
        touch($this->root . '/victim');
        symlink($this->root . '/victim', $this->root . '/locks/x.lock');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An entry in the lock directory is not a regular file.');

        $directory->openLockFile('x.lock');
    }

    public function testDanglingSymbolicLinkIsRefusedAndNothingIsCreated(): void
    {
        $directory = new LockDirectory($this->root . '/locks');
        symlink($this->root . '/created-through-link', $this->root . '/locks/x.lock');

        try {
            $directory->openLockFile('x.lock');
            self::fail('The dangling link was followed.');
        } catch (InvalidArgumentException $exception) {
            self::assertFileDoesNotExist($this->root . '/created-through-link');
        }
    }

    public function testDirectoryInPlaceOfALockFileIsRefused(): void
    {
        $directory = new LockDirectory($this->root . '/locks');
        mkdir($this->root . '/locks/x.lock');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An entry in the lock directory is not a regular file.');

        $directory->openLockFile('x.lock');
    }

    public function testFifoInPlaceOfALockFileIsRefusedWithoutBlocking(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('ext-posix is needed to create a FIFO.');
        }
        $directory = new LockDirectory($this->root . '/locks');
        self::assertTrue(posix_mkfifo($this->root . '/locks/x.lock', 0600));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An entry in the lock directory is not a regular file.');

        $directory->openLockFile('x.lock');
    }

    public function testLockFileThatCannotBeOpenedIsReportedWithoutItsPath(): void
    {
        if (TemporaryDirectory::effectiveUserId() === 0) {
            self::markTestSkipped('root ignores file permissions.');
        }
        $directory = new LockDirectory($this->root . '/locks');
        touch($this->root . '/locks/x.lock');
        chmod($this->root . '/locks/x.lock', 0400);

        try {
            $directory->openLockFile('x.lock');
            self::fail('A read-only lock file was opened for writing.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('A lock file cannot be opened (Permission denied).', $exception->getMessage());
        }
    }

    public function testLockFilesAreClosedOnExec(): void
    {
        if (!is_dir('/proc/self/fd')) {
            self::markTestSkipped('/proc is needed to read descriptor flags.');
        }
        $directory = new LockDirectory($this->root . '/locks');
        $file = $directory->openLockFile('x.lock');

        $flags = null;
        foreach (scandir('/proc/self/fd') ?: [] as $fd) {
            if (preg_match('/\A\d+\z/', $fd) === 1 && @readlink('/proc/self/fd/' . $fd) === $this->root . '/locks/x.lock') {
                $info = (string) file_get_contents('/proc/self/fdinfo/' . $fd);
                if (preg_match('/^flags:\s+([0-7]+)$/m', $info, $match) === 1) {
                    $flags = (int) octdec($match[1]);
                }
            }
        }
        $file->close();

        self::assertNotNull($flags, 'the lock file descriptor was not found');
        self::assertSame(02000000, $flags & 02000000, 'O_CLOEXEC is not set');
    }

    /**
     * Message for a directory on the way that lets other users replace its entries.
     */
    private static function holderMessage(string $directory, string $problem): string
    {
        return sprintf('A directory on the path to the lock directory (depth %d) is %s', substr_count($directory, '/'), $problem);
    }

    private static function mode(string $path): int
    {
        clearstatcache(true, $path);
        $permissions = fileperms($path);
        self::assertIsInt($permissions);

        return $permissions & 07777;
    }
}
