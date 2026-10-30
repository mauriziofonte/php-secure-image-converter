<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Concurrency;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * The private directory that holds the lock files of Semaphore and KeyedLock.
 *
 * A missing directory is created, with its missing parents, and set to mode 0700. The directory is then accepted
 * only when it is owned by the effective user of this process, is readable, writable and searchable by that user,
 * and is not writable by its group or by other users: a user who can create, replace or delete lock files could hold
 * every slot forever, or break mutual exclusion by deleting a held lock file so that the next process locks a new
 * one. Every ancestor of the directory, up to "/", must in turn be owned by root or by the effective user, and must
 * not be writable by its group or by other users unless its sticky bit is set: otherwise another user could rename
 * the lock directory away and put a directory of their own in its place. The checks run once, in the constructor,
 * on the resolved path (symbolic links followed).
 *
 * Safe locations are directories reserved for the application and its user: /run/<app> (tmpfs, created for example
 * by systemd's RuntimeDirectory=), /var/lib/<app>/locks, or a directory that the application creates itself directly
 * in the system temporary directory, whose sticky bit stops other users from renaming it (another user who creates
 * that name first makes the constructor refuse it, so /run or /var/lib are preferable). Never nest the directory
 * deeper under a shared temporary path such as /tmp/shared/<app>: other users may own or write to the intermediate
 * directories, and the ancestor check refuses them.
 *
 * Limitations, shared by everything built on this class:
 * - Local filesystems only. flock() on NFS is emulated with byte-range locks (Linux) or not supported at all, and
 *   neither its mutual exclusion nor its release on process death can be relied upon; CIFS, FUSE and other network
 *   or user-space filesystems are equally unsupported. Put the directory on a local disk or on tmpfs (/run).
 * - POSIX systems only (Linux, macOS, BSD): the constructor refuses to run on Windows, where neither the ownership
 *   nor the permission checks are meaningful.
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
     * @var string resolved absolute path, as realpath() returns it
     */
    private $path;

    /**
     * @param string $path absolute path of the directory; created with mode 0700 when missing
     *
     * @throws InvalidArgumentException when the path is not absolute, cannot be created or resolved, is not a
     *                                  directory, is not owned by the effective user, lacks owner permissions, or is
     *                                  writable by its group or by other users; when an ancestor is owned by another
     *                                  user than root or the effective user, or is writable by its group or by other
     *                                  users without the sticky bit; or on Windows
     */
    public function __construct(string $path)
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            throw new InvalidArgumentException('Lock directories are supported on POSIX systems only.');
        }
        if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false) {
            throw new InvalidArgumentException('The lock directory must be an absolute path.');
        }

        if (!is_dir($path)) {
            // Another process may create the directory at the same time: only a failure that leaves no directory
            // behind is an error.
            error_clear_last();
            $created = @mkdir($path, 0700, true);
            if (!$created && !is_dir($path)) {
                throw new InvalidArgumentException(sprintf(
                    'The lock directory cannot be created (%s).',
                    self::lastErrorReason()
                ));
            }
            if ($created) {
                // mkdir() applies the umask; the mode of the directory itself must be exactly 0700.
                @chmod($path, 0700);
            }
        }

        $resolved = realpath($path);
        if ($resolved === false) {
            throw new InvalidArgumentException('The lock directory cannot be resolved.');
        }
        clearstatcache(true, $resolved);
        $stat = @stat($resolved);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
            throw new InvalidArgumentException('The lock directory path does not name a directory.');
        }

        $owner = $stat['uid'];
        $effectiveUser = self::effectiveUserId();
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

        self::assertAncestorsAreSafe($resolved, $effectiveUser);

        $this->path = $resolved;
    }

    /**
     * Resolved absolute path of the directory, as realpath() returns it: without trailing slash, except for "/".
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
        if ($stat !== false && ($stat['mode'] & 0170000) !== 0100000) {
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
     * Checks every ancestor of the resolved lock directory, up to "/": none may let a user other than root or the
     * effective user rename or replace its entries.
     *
     * @throws InvalidArgumentException when an ancestor cannot be inspected, is owned by another user than root or the
     *                                  effective user, or is writable by its group or by other users without the
     *                                  sticky bit
     */
    private static function assertAncestorsAreSafe(string $resolved, int $effectiveUser): void
    {
        $ancestor = $resolved;
        for ($level = 1; $ancestor !== '/'; ++$level) {
            $ancestor = \dirname($ancestor);
            clearstatcache(true, $ancestor);
            $stat = @stat($ancestor);
            if ($stat === false) {
                throw new InvalidArgumentException(sprintf(
                    'A parent directory of the lock directory (%d level(s) up) cannot be inspected.',
                    $level
                ));
            }
            $owner = $stat['uid'];
            if ($owner !== 0 && $owner !== $effectiveUser) {
                throw new InvalidArgumentException(sprintf(
                    'A parent directory of the lock directory (%d level(s) up) is owned by user %d, who could replace the lock directory.',
                    $level,
                    $owner
                ));
            }
            $mode = $stat['mode'] & 07777;
            if (($mode & 0022) !== 0 && ($mode & 01000) === 0) {
                throw new InvalidArgumentException(sprintf(
                    'A parent directory of the lock directory (%d level(s) up) is writable by its group or by other users without the sticky bit (mode %04o), so they could replace the lock directory.',
                    $level,
                    $mode
                ));
            }
        }
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
     * becomes "Permission denied").
     */
    private static function lastErrorReason(): string
    {
        $error = error_get_last();
        if ($error === null) {
            return 'unknown error';
        }
        $position = strrpos($error['message'], ': ');

        return $position === false ? $error['message'] : substr($error['message'], $position + 2);
    }
}
