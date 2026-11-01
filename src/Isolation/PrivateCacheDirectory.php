<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * A cache directory private to the effective user, shared by the PHP processes of that user (PHP-FPM workers), and
 * the files in it. IsolationDetector caches the detected level there, and the backends their probed capabilities.
 *
 * The directory must be owned by the effective user and not writable by group or others, and a cache file must be a
 * regular file with the same properties, checked with lstat() so that a symbolic link is never followed. Anything
 * else is ignored, so that another local user cannot plant a cached result (a "sandbox" level that defeats a strict
 * policy, or capabilities that send untrusted input to the wrong decoder). Without the posix extension the owner
 * cannot be checked and the directory is never used. Files are written to a temporary name and renamed, with mode
 * 0600, so that readers never see a partial file.
 *
 * @internal used by IsolationDetector and the backend capability cache; not covered by the backward compatibility
 *           promise
 */
final class PrivateCacheDirectory
{
    /**
     * Name of the default directory below sys_get_temp_dir(), followed by the effective user ID.
     */
    public const DEFAULT_NAME_PREFIX = 'secure-image-converter-';

    /**
     * @var string|null
     */
    private $directory;

    /**
     * @param string|null $directory absolute, normalised path of the directory, created with mode 0700 when missing;
     *                               null for sys_get_temp_dir()/secure-image-converter-<euid>
     *
     * @throws InvalidArgumentException when the directory is not an absolute, normalised path
     */
    public function __construct(?string $directory = null)
    {
        if ($directory !== null && !ExecutableFinder::isAbsolutePath($directory)) {
            throw new InvalidArgumentException('A cache directory must be an absolute, normalised path.');
        }
        $this->directory = $directory;
    }

    /**
     * Path of the directory, created with mode 0700 when it is missing, or null when it cannot be used: the posix
     * extension is missing, or the path is not a directory owned by the effective user and protected from group and
     * others.
     */
    public function resolve(): ?string
    {
        if (!function_exists('posix_geteuid')) {
            return null;
        }
        $euid = posix_geteuid();
        $directory = $this->directory;
        if ($directory === null) {
            $directory = rtrim(sys_get_temp_dir(), '/') . '/' . self::DEFAULT_NAME_PREFIX . $euid;
        }
        if (!file_exists($directory) && !is_link($directory)) {
            @mkdir($directory, 0700, true);
        }
        clearstatcache(true, $directory);

        return self::isOwnedAndProtected($directory, 0040000, $euid) ? $directory : null;
    }

    /**
     * Contents of a cache file, at most $maxBytes, or null when it is missing or is not a regular file owned by the
     * effective user and protected from group and others.
     *
     * @param string $path a file directly inside the directory returned by resolve()
     */
    public function read(string $path, int $maxBytes): ?string
    {
        clearstatcache(true, $path);
        if (!function_exists('posix_geteuid') || !self::isOwnedAndProtected($path, 0100000, posix_geteuid())) {
            return null;
        }
        $contents = @file_get_contents($path, false, null, 0, max(1, $maxBytes));

        return is_string($contents) ? $contents : null;
    }

    /**
     * Replaces a cache file atomically: the contents are written to a new temporary file in the same directory, made
     * mode 0600 and renamed over the path.
     *
     * @param string $path a file directly inside the directory returned by resolve()
     *
     * @return bool whether the file was written
     */
    public function write(string $path, string $contents): bool
    {
        $temporary = dirname($path) . '/.cache-' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            return false;
        }
        $written = fwrite($handle, $contents);
        fclose($handle);
        if ($written !== strlen($contents) || !@chmod($temporary, 0600) || !@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /**
     * Whether a path (never followed if it is a symbolic link) has the given file type, belongs to $uid and is not
     * writable by group or others.
     *
     * @param int $type S_IFDIR (0040000) or S_IFREG (0100000)
     */
    private static function isOwnedAndProtected(string $path, int $type, int $uid): bool
    {
        $stat = @lstat($path);

        return is_array($stat)
            && ($stat['mode'] & 0170000) === $type
            && $stat['uid'] === $uid
            && ($stat['mode'] & 0022) === 0;
    }
}
