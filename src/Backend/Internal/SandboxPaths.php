<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Internal;

use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;

/**
 * The read-only allowlist of a backend's sandbox: the paths its programs need, and nothing else of the host.
 *
 * | Part                 | Paths                                                                          |
 * | -------------------- | ------------------------------------------------------------------------------ |
 * | programs             | each program, with every symbolic link on the way to it recreated as a link     |
 * | libraries            | LIBRARY_DIRECTORIES, and lib and lib64 next to the bin directory of a program   |
 * |                      | installed under another prefix (such as /opt/vips/lib)                          |
 * | loader configuration | /etc/ld.so.cache, /etc/ld.so.conf and /etc/ld.so.conf.d                         |
 * | backend data         | what the backend adds: its configuration, its worker scripts                    |
 *
 * Nothing else of /usr is visible: no other program of /usr/bin, no /usr/share (except what a backend adds, such as
 * the time zone database PHP reads at startup), no /usr/local/etc; nor any other file of /etc. Paths that do not
 * exist are left out; links are kept as links, so that a program finds its files at the paths it expects.
 *
 * @internal
 */
final class SandboxPaths
{
    /**
     * Directories of shared libraries, plugins and modules, when they exist.
     */
    public const LIBRARY_DIRECTORIES = [
        '/lib',
        '/lib32',
        '/lib64',
        '/libx32',
        '/usr/lib',
        '/usr/lib32',
        '/usr/lib64',
        '/usr/libx32',
        '/usr/local/lib',
        '/usr/local/lib64',
    ];

    /**
     * Configuration of the dynamic loader.
     */
    public const LOADER_CONFIGURATION = [
        '/etc/ld.so.cache',
        '/etc/ld.so.conf',
        '/etc/ld.so.conf.d',
    ];

    /**
     * Longest chain of symbolic links followed.
     */
    private const MAX_LINKS = 16;

    /**
     * The allowlist for programs and backend data.
     *
     * @param list<string> $programs absolute paths of the programs the backend runs
     * @param list<string> $data     absolute paths of other files and directories the backend needs
     *
     * @return list<string> existing absolute paths, without duplicates
     */
    public static function forPrograms(array $programs, array $data = []): array
    {
        $paths = [];
        foreach (self::LIBRARY_DIRECTORIES as $directory) {
            self::add($directory, $paths);
        }
        foreach (self::LOADER_CONFIGURATION as $file) {
            self::add($file, $paths);
        }
        foreach ($programs as $program) {
            self::add($program, $paths);
            $real = realpath($program);
            if (is_string($real)) {
                $prefix = \dirname($real, 2);
                if (!in_array($prefix, ['/', '/usr', '/usr/local'], true)) {
                    self::add($prefix . '/lib', $paths);
                    self::add($prefix . '/lib64', $paths);
                }
            }
        }
        foreach ($data as $path) {
            self::add($path, $paths);
        }

        return array_keys($paths);
    }

    /**
     * Adds a path: the symbolic links among its ancestors, then the path itself under its real parent directory,
     * following the links it is made of.
     *
     * @param array<string, true> $paths
     */
    private static function add(string $path, array &$paths, int $depth = 0): void
    {
        if ($depth > self::MAX_LINKS || !ExecutableFinder::isAbsolutePath($path)) {
            return;
        }
        $ancestor = '';
        foreach (array_slice(explode('/', substr($path, 1)), 0, -1) as $segment) {
            $ancestor .= '/' . $segment;
            if (is_link($ancestor)) {
                $paths[$ancestor] = true;
                $target = self::linkTarget($ancestor);
                if ($target !== null && is_link($target)) {
                    self::add($target, $paths, $depth + 1);
                }
            }
        }
        $parent = realpath(\dirname($path));
        if (!is_string($parent)) {
            return;
        }
        $canonical = rtrim($parent, '/') . '/' . basename($path);
        if (is_link($canonical)) {
            $paths[$canonical] = true;
            $target = self::linkTarget($canonical);
            if ($target !== null) {
                self::add($target, $paths, $depth + 1);
            }
        } elseif (file_exists($canonical)) {
            $paths[$canonical] = true;
        }
    }

    /**
     * The absolute, normalised target of a symbolic link, or null when it cannot be read.
     */
    private static function linkTarget(string $link): ?string
    {
        $target = function_exists('readlink') ? @readlink($link) : false;
        if (!is_string($target)) {
            return null;
        }
        if ($target[0] !== '/') {
            $target = \dirname($link) . '/' . $target;
        }
        $segments = [];
        foreach (explode('/', $target) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : '/' . implode('/', $segments);
    }
}
