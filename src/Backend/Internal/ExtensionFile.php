<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Internal;

use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;

/**
 * The shared object of a PHP extension (such as gd.so) in the extension directory configured with
 * BackendOptions::withPhpExtensionDirectory(), resolved once and accepted only where no other user can have planted
 * or changed it: a PHP worker loads it, and a shared object runs code as soon as it is loaded.
 *
 * resolve() reads the configured path once: it resolves the directory, then <extension>.so in it, with their
 * symbolic links, and checks what they resolved to.
 *
 * - The directory is owned by root or the effective user and is neither group- nor world-writable.
 * - Every directory above it is owned by root or the effective user, and is not group- or world-writable unless its
 *   sticky bit is set (as on /tmp), so that nobody else can rename the directory and put another in its place.
 * - <extension>.so is in it and resolves to a regular file owned by root or the effective user, neither group- nor
 *   world-writable, and the directories above that file pass the same checks.
 *
 * The resolved file (getPath()) is the one the worker names and the sandbox mounts: its path has no symbolic link
 * left and only trusted users can change the directories along it, so another user who retargets a symbolic link of
 * the configured path afterwards changes nothing. checkAgain() repeats the checks on the resolved directory and file
 * without resolving the configured path again.
 *
 * Without the posix extension the effective user is unknown, and only root counts as an owner.
 *
 * @internal
 */
final class ExtensionFile
{
    private const WRITABLE_BY_OTHERS = 0022;

    private const STICKY = 01000;

    /**
     * @var string|null
     */
    private $directory;

    /**
     * @var string|null
     */
    private $path;

    /**
     * @var string|null
     */
    private $problem;

    private function __construct(?string $directory, ?string $path, ?string $problem)
    {
        $this->directory = $directory;
        $this->path = $path;
        $this->problem = $problem;
    }

    /**
     * Resolves the extension's shared object in the directory, once, and checks it.
     */
    public static function resolve(string $directory, string $extension): self
    {
        clearstatcache();
        $real = realpath($directory);
        if (!is_string($real) || !is_dir($real)) {
            return new self(null, null, 'the PHP extension directory does not exist');
        }
        $file = realpath($real . '/' . $extension . '.so');
        if (!is_string($file)) {
            $problem = self::directoryProblem($real, 'the PHP extension directory');

            return new self($real, null, $problem !== null ? $problem : 'the PHP extension directory does not contain ' . $extension . '.so');
        }

        return self::check($real, $file);
    }

    /**
     * The same resolution, checked again (the owners and modes may have changed); the configured path is not
     * resolved again.
     */
    public function checkAgain(): self
    {
        if ($this->directory === null || $this->path === null) {
            return $this;
        }
        clearstatcache();

        return self::check($this->directory, $this->path);
    }

    /**
     * The resolved path of the shared object, or null when it must not be loaded (see getProblem()).
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * Why the shared object must not be loaded, or null when it may: a short English sentence without the path.
     */
    public function getProblem(): ?string
    {
        return $this->problem;
    }

    /**
     * Checks a resolved directory and the resolved file of the extension in it.
     */
    private static function check(string $directory, string $file): self
    {
        $problem = self::directoryProblem($directory, 'the PHP extension directory');
        if ($problem === null) {
            $stat = @lstat($file);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
                $problem = 'the extension file is not a regular file';
            } elseif (!self::isTrustedOwner($stat['uid']) || ($stat['mode'] & self::WRITABLE_BY_OTHERS) !== 0) {
                $problem = 'the extension file is not owned by root or the effective user, or others can write it';
            } elseif (\dirname($file) !== $directory) {
                $problem = self::directoryProblem(\dirname($file), 'the directory of the extension file');
            }
        }

        return new self($directory, $problem === null ? $file : null, $problem);
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
