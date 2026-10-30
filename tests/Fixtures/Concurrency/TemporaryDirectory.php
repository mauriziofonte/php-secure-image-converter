<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency;

/**
 * Temporary directories for the concurrency tests, and the user identities their ownership checks need.
 */
final class TemporaryDirectory
{
    private function __construct()
    {
    }

    /**
     * Creates a new, empty directory with mode 0700 under the system temporary directory.
     *
     * @return string its absolute, resolved path
     */
    public static function create(): string
    {
        $base = realpath(sys_get_temp_dir());
        if ($base === false) {
            throw new \RuntimeException('The system temporary directory does not exist.');
        }
        $path = sprintf('%s/sic-concurrency-%s', rtrim($base, '/'), bin2hex(random_bytes(8)));
        if (!mkdir($path, 0700)) {
            throw new \RuntimeException('Cannot create a temporary directory.');
        }

        return $path;
    }

    /**
     * Removes a directory tree created by a test. Symbolic links are removed, never followed; directories whose
     * mode a test restricted are made accessible to their owner first.
     */
    public static function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }

            return;
        }
        @chmod($path, 0700);
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    /**
     * Effective user ID of this process.
     */
    public static function effectiveUserId(): int
    {
        if (\function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        $probe = tmpfile();
        if ($probe === false) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }
        $stat = fstat($probe);
        fclose($probe);
        if ($stat === false) {
            throw new \RuntimeException('Cannot stat a temporary file.');
        }

        return $stat['uid'];
    }

    /**
     * A directory that exists and is not owned by the effective user: as root, a new directory under $parent given
     * to user 65534 (nobody); otherwise the root directory, which belongs to root.
     */
    public static function foreignOwned(string $parent): string
    {
        if (self::effectiveUserId() !== 0) {
            return '/';
        }
        $path = $parent . '/foreign';
        if (!mkdir($path, 0700) || !chown($path, 65534)) {
            throw new \RuntimeException('Cannot create a directory owned by another user.');
        }

        return $path;
    }
}
