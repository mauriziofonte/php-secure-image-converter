<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Internal;

/**
 * Reads what the glibc dynamic loader prints when it traces an object (LD_TRACE_LOADED_OBJECTS=1, or
 * "<loader> --list <object>") instead of running it: one line per shared library it would load.
 *
 *     linux-vdso.so.1 (0x00007ffd5b9f3000)
 *     libvips.so.42 => /lib/x86_64-linux-gnu/libvips.so.42 (0x00007f2c2a000000)
 *     /lib64/ld-linux-x86-64.so.2 (0x00007f2c2a5b0000)
 *
 * Anything else (a library "not found", a loader that ignores the variable and runs the program, such as musl's)
 * makes the trace unreadable, so that the caller keeps its broader default.
 *
 * @internal
 */
final class LibraryTrace
{
    /**
     * The libraries of a trace and the dynamic loader among them, or null when the output is not a complete trace.
     *
     * @return array{libraries: list<string>, loader: string|null}|null
     */
    public static function parse(string $output): ?array
    {
        $libraries = [];
        $loader = null;
        $lines = 0;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            ++$lines;
            if ($line === 'statically linked') {
                continue;
            }
            if (preg_match('#\A\S+ => (/\S+) \(0x[0-9a-f]+\)\z#', $line, $match) === 1) {
                $libraries[] = $match[1];
            } elseif (preg_match('#\A(/\S+) \(0x[0-9a-f]+\)\z#', $line, $match) === 1) {
                $libraries[] = $match[1];
                $loader = $match[1];
            } elseif (preg_match('#\A[^/\s]+ \(0x[0-9a-f]+\)\z#', $line) !== 1) {
                // Not the virtual DSO: "not found", or no trace at all.
                return null;
            }
        }

        return $lines > 0 ? ['libraries' => array_values(array_unique($libraries)), 'loader' => $loader] : null;
    }
}
