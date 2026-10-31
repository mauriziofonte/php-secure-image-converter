<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Internal;

use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;

/**
 * The shared object of a PHP extension (such as gd.so) in the extension directory configured with
 * BackendOptions::withPhpExtensionDirectory(), accepted only where no other user can have planted or changed it: a
 * PHP worker loads it, and a shared object runs code as soon as it is loaded.
 *
 * - The directory, with its symbolic links resolved, is owned by root or the effective user and is neither group-
 *   nor world-writable.
 * - Every directory above it is owned by root or the effective user, and is not group- or world-writable unless its
 *   sticky bit is set (as on /tmp), so that nobody else can rename the directory and put another in its place.
 * - The file <extension>.so in it, with its symbolic links resolved, is a regular file owned by root or the effective
 *   user, neither group- nor world-writable, and the directories above it pass the same checks.
 *
 * Without the posix extension the effective user is unknown, and only root counts as an owner. The worker loads the
 * file by its resolved path, and only that file is mounted, read-only, in the sandbox.
 *
 * @internal
 */
final class ExtensionFile
{
    private const WRITABLE_BY_OTHERS = 0022;

    private const STICKY = 01000;

    /**
     * The resolved path of the extension's shared object, or null when it does not exist.
     */
    public static function path(string $directory, string $extension): ?string
    {
        $real = realpath(rtrim($directory, '/') . '/' . $extension . '.so');

        return is_string($real) && is_file($real) ? $real : null;
    }

    /**
     * Why the extension's shared object in the directory must not be loaded, or null when it may: a short English
     * sentence without the path.
     */
    public static function problem(string $directory, string $extension): ?string
    {
        clearstatcache();
        $real = realpath($directory);
        if (!is_string($real) || !is_dir($real)) {
            return 'the PHP extension directory does not exist';
        }
        $problem = self::directoryProblem($real, 'the PHP extension directory');
        if ($problem !== null) {
            return $problem;
        }
        $file = self::path($real, $extension);
        if ($file === null) {
            return null;
        }
        $stat = @lstat($file);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
            return 'the extension file is not a regular file';
        }
        if (!self::isTrustedOwner($stat['uid']) || ($stat['mode'] & self::WRITABLE_BY_OTHERS) !== 0) {
            return 'the extension file is not owned by root or the effective user, or others can write it';
        }

        return \dirname($file) !== $real ? self::directoryProblem(\dirname($file), 'the directory of the extension file') : null;
    }

    /**
     * Checks a canonical directory and the directories above it.
     */
    private static function directoryProblem(string $directory, string $what): ?string
    {
        if (!ExecutableFinder::isAbsolutePath($directory)) {
            return $what . ' is not an absolute path';
        }
        $stat = @lstat($directory);
        if (!is_array($stat) || !self::isTrustedOwner($stat['uid']) || ($stat['mode'] & self::WRITABLE_BY_OTHERS) !== 0) {
            return $what . ' is not owned by root or the effective user, or others can write to it';
        }
        $parent = $directory;
        do {
            $parent = \dirname($parent);
            $stat = @lstat($parent);
            if (
                !is_array($stat)
                || !self::isTrustedOwner($stat['uid'])
                || (($stat['mode'] & self::WRITABLE_BY_OTHERS) !== 0 && ($stat['mode'] & self::STICKY) === 0)
            ) {
                return 'a directory above ' . $what . ' is not owned by root or the effective user, or others can replace its entries';
            }
        } while ($parent !== '/');

        return null;
    }

    private static function isTrustedOwner(int $uid): bool
    {
        return $uid === 0 || (function_exists('posix_geteuid') && $uid === posix_geteuid());
    }
}
