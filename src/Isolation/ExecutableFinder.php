<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * Locates executables in a fixed list of directories, the way a shell searches PATH, but in PHP: the command that is
 * eventually run is always an absolute path, so neither the inherited PATH nor the current directory can substitute
 * another program.
 *
 * Under an open_basedir, programs outside it are not found: PHP cannot look at them. The checks are silenced, so
 * that an error handler that turns warnings into exceptions (as frameworks install) does not break the search.
 */
final class ExecutableFinder
{
    /**
     * @var list<string>
     */
    private $directories;

    /**
     * @param list<string> $directories absolute, normalised directories, searched in order
     *
     * @throws InvalidArgumentException when a directory is not an absolute, normalised path
     */
    public function __construct(array $directories)
    {
        $this->directories = self::checkDirectories($directories);
    }

    /**
     * Absolute path of an executable regular file, or null when there is none.
     *
     * @param string $name a bare program name (for example "cwebp"), searched in the directories in order, or an
     *                     absolute path, which is checked as it is; a relative path containing "/" is never accepted
     */
    public function find(string $name): ?string
    {
        if ($name === '' || strpos($name, "\0") !== false) {
            return null;
        }
        if (strpos($name, '/') !== false) {
            return self::isAbsolutePath($name) && self::isExecutableFile($name) ? $name : null;
        }
        if ($name === '.' || $name === '..') {
            return null;
        }
        foreach ($this->directories as $directory) {
            $candidate = $directory . '/' . $name;
            if (self::isExecutableFile($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function getDirectories(): array
    {
        return $this->directories;
    }

    /**
     * Whether the path is absolute and normalised: it starts with "/", has no empty, "." or ".." segment, no
     * trailing "/" and no NUL byte, and is not the root directory itself.
     */
    public static function isAbsolutePath(string $path): bool
    {
        if (preg_match('#\A(?:/[^/\x00]+)+\z#', $path) !== 1) {
            return false;
        }
        foreach (explode('/', substr($path, 1)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $directories
     *
     * @throws InvalidArgumentException when a directory is not an absolute, normalised path
     *
     * @return list<string>
     */
    private static function checkDirectories(array $directories): array
    {
        $checked = [];
        foreach ($directories as $directory) {
            if (!is_string($directory) || !self::isAbsolutePath($directory)) {
                throw new InvalidArgumentException(
                    'Executable search directories must be absolute, normalised paths other than the root.'
                );
            }
            $checked[] = $directory;
        }

        return $checked;
    }

    private static function isExecutableFile(string $path): bool
    {
        return @is_file($path) && @is_executable($path);
    }
}
