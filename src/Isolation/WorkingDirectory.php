<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * A private, freshly created directory for one job: the current directory, HOME and TMPDIR of the processes the job
 * runs, and the only writable location inside the sandbox. It is created with mode 0700 under a random name, so
 * that other users can neither read the files nor plant links in it.
 *
 * The owner removes it with remove() when the job ends, normally in a finally block; nothing removes it
 * automatically.
 */
final class WorkingDirectory
{
    /**
     * @var string
     */
    private $path;

    private function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Creates a new private directory.
     *
     * @param string|null $parentDirectory existing directory to create it in; sys_get_temp_dir() when null
     *
     * @throws InvalidArgumentException  when the parent is not an existing directory
     * @throws ConversionFailedException when the directory cannot be created
     */
    public static function create(?string $parentDirectory = null): self
    {
        $parent = realpath($parentDirectory === null ? sys_get_temp_dir() : $parentDirectory);
        if ($parent === false || !is_dir($parent)) {
            throw new InvalidArgumentException('The parent of a working directory must be an existing directory.');
        }

        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $path = rtrim($parent, '/') . '/sic-' . bin2hex(random_bytes(12));
            // The mode is applied again with chmod(): mkdir() is subject to the umask.
            if (@mkdir($path, 0700) && @chmod($path, 0700)) {
                return new self($path);
            }
            if (!file_exists($path)) {
                break;
            }
        }

        throw new ConversionFailedException('A private working directory could not be created.');
    }

    /**
     * Absolute path of the directory, without symbolic links. Never expose it to untrusted clients.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Path of an entry directly inside the directory.
     *
     * @param string $name a file name: no "/", no NUL byte, not "." or ".."
     *
     * @throws InvalidArgumentException when the name is not a plain file name
     */
    public function pathOf(string $name): string
    {
        if ($name === '' || $name === '.' || $name === '..' || strpbrk($name, "/\0") !== false) {
            throw new InvalidArgumentException('A working directory entry must be a plain file name.');
        }

        return $this->path . '/' . $name;
    }

    /**
     * Removes the directory and everything in it, never following symbolic links, and also when a process left
     * entries without write or search permission. Calling it again does nothing.
     *
     * @return bool whether the directory no longer exists
     */
    public function remove(): bool
    {
        if (!is_link($this->path) && is_dir($this->path)) {
            self::removeTree($this->path);
        }
        clearstatcache();

        return !file_exists($this->path) && !is_link($this->path);
    }

    private static function removeTree(string $directory): void
    {
        @chmod($directory, 0700);
        $entries = @scandir($directory);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . '/' . $entry;
                if (!is_link($path) && is_dir($path)) {
                    self::removeTree($path);
                } else {
                    @unlink($path);
                }
            }
        }
        @rmdir($directory);
    }
}
