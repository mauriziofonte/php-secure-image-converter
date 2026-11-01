<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Concurrency;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * The private directory that holds the lock files of Semaphore and KeyedLock.
 *
 * The constructor resolves the path one component at a time, as the kernel does, following symbolic links itself
 * (at most 40, as Linux allows), and checks every entry it looks up on the way:
 * - the directory that holds the entry must be owned by root or by the effective user of this process, and must not
 *   be writable by its group or by other users unless its sticky bit is set: otherwise another user could rename the
 *   entry away and put one of their own in its place, so that two processes given the same path would lock files in
 *   different directories;
 * - a symbolic link must be owned by root or by the effective user: in a sticky directory such as /tmp, the owner of
 *   a link can still delete it and create another one that points elsewhere.
 * This covers the path as given and every directory that its symbolic links lead through, so a link kept in a
 * directory of another user, or owned by another user, is refused even when its target is safe.
 *
 * A missing component is created with mode 0700 inside a directory that passed these checks. The lock directory
 * itself is then accepted only when it is owned by the effective user, is readable, writable and searchable by that
 * user, and is not writable by its group or by other users: a user who can create, replace or delete lock files
 * could hold every slot forever, or break mutual exclusion by deleting a held lock file so that the next process
 * locks a new one. The checks run once, in the constructor; the resolved path is kept, so a symbolic link of the path
 * as given is never followed again.
 *
 * Safe locations are directories reserved for the application and its user: /run/<app> (tmpfs, created for example
 * by systemd's RuntimeDirectory=), /var/lib/<app>/locks, or a directory that the application creates itself directly
 * in the system temporary directory, whose sticky bit stops other users from renaming it (another user who creates
 * that name first makes the constructor refuse it, so /run or /var/lib are preferable). Never nest the directory
 * deeper under a shared temporary path such as /tmp/shared/<app>: other users may own or write to the intermediate
 * directories, and the checks refuse them.
 *
 * Limitations, shared by everything built on this class:
 * - Local filesystems only. flock() on NFS is emulated with byte-range locks (Linux) or not supported at all, and
 *   neither its mutual exclusion nor its release on process death can be relied upon; CIFS, FUSE and other network
 *   or user-space filesystems are equally unsupported. Put the directory on a local disk or on tmpfs (/run).
 * - POSIX systems only (Linux, macOS, BSD): the constructor refuses to run on Windows, where neither the ownership
 *   nor the permission checks are meaningful.
 * - Every directory from "/" down to the lock directory must be inspectable: under an open_basedir setting that
 *   excludes any of them, "/" included, the constructor refuses every path and says so.
 * - Only the owner and the permission bits are checked; access control lists are not.
 * - Missing components are created with mkdir() and then set to mode 0700 with chmod(). Under a umask that removes
 *   permissions from the owner (such as 0277), another process can see a new directory between those two calls and
 *   fail, closed (the constructor throws), until the chmod() is done. Create the directory before the workers start,
 *   or keep a usual umask (022, 027, 077), when several processes construct the same new path at once. The umask is
 *   never changed temporarily, because it is shared by all the threads of a multi-threaded server.
 * - Locks coordinate the processes of one host that share the directory and run as the same user. They do not span
 *   hosts or containers that do not share the directory.
 * - Lock files must not be deleted while the application runs: exclude the directory from temporary-file cleaners
 *   (systemd-tmpfiles, tmpreaper). Removing a held lock file lets a second process lock a new file of the same name.
 *
 * Lock files are opened with the close-on-exec flag, so a process started with proc_open() or exec() while a lease
 * is held never inherits the lock.
 */
final class LockDirectory
{
    /**
     * Lock file names produced by Semaphore and KeyedLock: no separator, no leading dot, no "..".
     */
    private const FILE_NAME_PATTERN = '/\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\z/';

    /**
     * Most symbolic links followed while resolving the path, as on Linux (MAXSYMLINKS).
     */
    private const MAX_SYMBOLIC_LINKS = 40;

    /**
     * File type bits of a stat() mode, and the types this class tells apart.
     */
    private const TYPE_MASK = 0170000;

    private const TYPE_DIRECTORY = 0040000;

    private const TYPE_REGULAR_FILE = 0100000;

    private const TYPE_SYMBOLIC_LINK = 0120000;

    /**
     * @var string resolved absolute path: every symbolic link followed, no "." or ".." component
     */
    private $path;

    /**
     * @param string $path absolute path of the directory; missing components are created with mode 0700
     *
     * @throws InvalidArgumentException when the path is not absolute, cannot be inspected, created or resolved, or
     *                                  does not name a directory; when a directory on the way is owned by another
     *                                  user than root or the effective user, or is writable by its group or by other
     *                                  users without the sticky bit; when a symbolic link on the way is owned by
     *                                  another user than root or the effective user; when the directory is not owned
     *                                  by the effective user, lacks owner permissions, or is writable by its group or
     *                                  by other users; or on Windows
     */
    public function __construct(string $path)
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            throw new InvalidArgumentException('Lock directories are supported on POSIX systems only.');
        }
        if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false) {
            throw new InvalidArgumentException('The lock directory must be an absolute path.');
        }

        $effectiveUser = self::effectiveUserId();
        list($resolved, $stat) = self::resolve($path, $effectiveUser);

        $owner = $stat['uid'];
        if ($owner !== $effectiveUser) {
            throw new InvalidArgumentException(sprintf(
                'The lock directory is owned by user %d, not by the effective user %d of this process.',
                $owner,
                $effectiveUser
            ));
        }
        $mode = $stat['mode'] & 07777;
        if (($mode & 0022) !== 0) {
            throw new InvalidArgumentException(sprintf(
                'The lock directory is writable by its group or by other users (mode %04o); it must not be (mode 0700).',
                $mode
            ));
        }
        if (($mode & 0700) !== 0700) {
            throw new InvalidArgumentException(sprintf(
                'The lock directory must be readable, writable and searchable by its owner (mode %04o, expected 0700).',
                $mode
            ));
        }

        $this->path = $resolved;
    }

    /**
     * Resolved absolute path of the directory, every symbolic link followed: without trailing slash, except for "/".
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Opens the lock file of the given name, creating it when missing. Used by Semaphore and KeyedLock.
     *
     * @param string $fileName file name inside this directory, without separators
     *
     * @throws InvalidArgumentException when the name is not a plain file name, an existing entry of that name is not
     *                                  a regular file (for example a symbolic link or a FIFO), or the file cannot be
     *                                  opened
     *
     * @internal
     */
    public function openLockFile(string $fileName): LockFile
    {
        if (preg_match(self::FILE_NAME_PATTERN, $fileName) !== 1) {
            throw new InvalidArgumentException('Invalid lock file name.');
        }

        $path = rtrim($this->path, '/') . '/' . $fileName;
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat !== false && ($stat['mode'] & self::TYPE_MASK) !== self::TYPE_REGULAR_FILE) {
            // A symbolic link could point anywhere, and opening a FIFO for writing blocks until a reader shows up.
            throw new InvalidArgumentException('An entry in the lock directory is not a regular file.');
        }

        // "c": create when missing, never truncate; "e": close-on-exec, so that child processes do not inherit it.
        error_clear_last();
        $handle = @fopen($path, 'ce');
        if ($handle === false) {
            throw new InvalidArgumentException(sprintf(
                'A lock file cannot be opened (%s).',
                self::lastErrorReason()
            ));
        }

        return new LockFile($path, $handle);
    }

    /**
     * Resolves the path one component at a time and checks every entry looked up on the way (see the class
     * description), creating missing components with mode 0700.
     *
     * @return array{string, array<int|string, int>} the resolved path, and lstat() of the directory it names
     *
     * @throws InvalidArgumentException when an entry cannot be inspected, created or read, does not name a directory
     *                                  where one is needed, or fails a check; or when too many symbolic links are met
     */
    private static function resolve(string $path, int $effectiveUser): array
    {
        $pending = self::components($path);
        $current = '/';
        $currentStat = self::inspect($current);
        $links = 0;

        while ($pending !== []) {
            $component = array_shift($pending);
            if ($component === '..') {
                // $current never contains a symbolic link, so its parent is the lexical one.
                $current = \dirname($current);
                $currentStat = self::inspect($current);

                continue;
            }

            self::assertHolderIsSafe($current, $currentStat, $effectiveUser);
            $entry = ($current === '/' ? '' : $current) . '/' . $component;
            $entryStat = self::inspectOrCreate($entry);
            $type = $entryStat['mode'] & self::TYPE_MASK;

            if ($type === self::TYPE_SYMBOLIC_LINK) {
                $linkOwner = $entryStat['uid'];
                if ($linkOwner !== 0 && $linkOwner !== $effectiveUser) {
                    throw new InvalidArgumentException(sprintf(
                        'A symbolic link on the path to the lock directory is owned by user %d, who could replace it.',
                        $linkOwner
                    ));
                }
                if (++$links > self::MAX_SYMBOLIC_LINKS) {
                    throw new InvalidArgumentException('The path to the lock directory has too many levels of symbolic links.');
                }
                $target = @readlink($entry);
                if ($target === false) {
                    throw new InvalidArgumentException('A symbolic link on the path to the lock directory cannot be read.');
                }
                if ($target[0] === '/') {
                    $current = '/';
                    $currentStat = self::inspect($current);
                }
                $pending = array_merge(self::components($target), $pending);

                continue;
            }
            if ($type !== self::TYPE_DIRECTORY) {
                throw new InvalidArgumentException('The lock directory path does not name a directory.');
            }
            $current = $entry;
            $currentStat = $entryStat;
        }

        return [$current, $currentStat];
    }

    /**
     * Non-empty components of a path, without ".".
     *
     * @return list<string>
     */
    private static function components(string $path): array
    {
        $components = [];
        foreach (explode('/', $path) as $component) {
            if ($component !== '' && $component !== '.') {
                $components[] = $component;
            }
        }

        return $components;
    }

    /**
     * Checks a directory in which an entry of the path is looked up: no user other than root or the effective user
     * may rename or replace its entries.
     *
     * @param array<int|string, int> $stat lstat() of the directory
     *
     * @throws InvalidArgumentException when the directory is owned by another user than root or the effective user,
     *                                  or is writable by its group or by other users without the sticky bit
     */
    private static function assertHolderIsSafe(string $directory, array $stat, int $effectiveUser): void
    {
        $depth = $directory === '/' ? 0 : substr_count($directory, '/');
        $owner = $stat['uid'];
        if ($owner !== 0 && $owner !== $effectiveUser) {
            throw new InvalidArgumentException(sprintf(
                'A directory on the path to the lock directory (depth %d) is owned by user %d, who could replace the lock directory.',
                $depth,
                $owner
            ));
        }
        $mode = $stat['mode'] & 07777;
        if (($mode & 0022) !== 0 && ($mode & 01000) === 0) {
            throw new InvalidArgumentException(sprintf(
                'A directory on the path to the lock directory (depth %d) is writable by its group or by other users without the sticky bit (mode %04o), so they could replace the lock directory.',
                $depth,
                $mode
            ));
        }
    }

    /**
     * lstat() of an entry that must exist.
     *
     * @return array<int|string, int>
     *
     * @throws InvalidArgumentException when the entry cannot be inspected
     */
    private static function inspect(string $entry): array
    {
        clearstatcache(true, $entry);
        error_clear_last();
        $stat = @lstat($entry);
        if ($stat === false) {
            throw new InvalidArgumentException(sprintf(
                'A directory on the path to the lock directory cannot be inspected (%s).',
                self::lastErrorReason()
            ));
        }

        return $stat;
    }

    /**
     * lstat() of an entry, which is created as a directory with mode 0700 when missing.
     *
     * @return array<int|string, int>
     *
     * @throws InvalidArgumentException when the entry is missing and cannot be created
     */
    private static function inspectOrCreate(string $entry): array
    {
        clearstatcache(true, $entry);
        $stat = @lstat($entry);
        if ($stat !== false) {
            return $stat;
        }

        // Another process may create the entry at the same time: only a failure that leaves nothing behind is an
        // error, and whatever was created is checked as any existing entry.
        error_clear_last();
        $created = @mkdir($entry, 0700);
        $reason = self::lastErrorReason();
        if ($created) {
            // mkdir() applies the umask; a created directory must be exactly 0700.
            @chmod($entry, 0700);
        }
        clearstatcache(true, $entry);
        $stat = @lstat($entry);
        if ($stat === false) {
            throw new InvalidArgumentException(sprintf('The lock directory cannot be created (%s).', $reason));
        }

        return $stat;
    }

    /**
     * Effective user ID of this process: from ext-posix when available, otherwise the owner of a new temporary file,
     * which the kernel sets to the effective (file system) user ID.
     *
     * @throws InvalidArgumentException when neither source is available
     */
    private static function effectiveUserId(): int
    {
        if (\function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        $probe = @tmpfile();
        $stat = $probe === false ? false : fstat($probe);
        if ($probe !== false) {
            fclose($probe);
        }
        if ($stat === false) {
            throw new InvalidArgumentException('The effective user of this process cannot be determined.');
        }

        return $stat['uid'];
    }

    /**
     * Reason of the last PHP warning, without the file path that PHP puts before it ("mkdir(): Permission denied"
     * becomes "Permission denied"). An open_basedir refusal, whose message names paths, gets a fixed explanation.
     */
    private static function lastErrorReason(): string
    {
        $error = error_get_last();
        if ($error === null) {
            return 'unknown error';
        }
        if (strpos($error['message'], 'open_basedir restriction in effect') !== false) {
            return 'open_basedir restriction in effect: every directory from "/" down to the lock directory is checked,'
                . ' so open_basedir must not exclude any of them';
        }
        $position = strrpos($error['message'], ': ');

        return $position === false ? $error['message'] : substr($error['message'], $position + 2);
    }
}
