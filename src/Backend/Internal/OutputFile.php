<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Internal;

use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;

/**
 * Takes the output a process left in its working directory, without trusting the process.
 *
 * The process could have replaced its output with a symbolic link (to make the caller read or overwrite another
 * file), a FIFO (to make it block), a directory or a hard link to a file it does not own. The file is accepted only
 * when lstat() shows a regular, non-empty file with a single link, owned by this process's effective user (when the
 * posix extension can tell), and the descriptor opened on it (non-blocking, so that a FIFO swapped in cannot block)
 * shows the same file again. Its bytes are then copied through that descriptor into a new file next to the
 * destination: a process that still holds the original open cannot change the copy. The copy is checked (the
 * caller's verifier) before it is renamed over the destination, so that an output that fails the check never
 * replaces the destination, nor appears at its path. A copy that stops short (a full filesystem, a file size limit)
 * is a ConversionFailedException whatever the error handler does with PHP's warning, and its temporary file is
 * removed.
 *
 * @internal
 */
final class OutputFile
{
    /**
     * Copies the output of a run to its destination.
     *
     * @param string                        $source      the output file inside the working directory
     * @param string                        $destination absolute path to write, replaced atomically
     * @param int                           $maxBytes    largest accepted size
     * @param (callable(string): void)|null $verify      called with the path of the copy before it replaces the
     *                                                   destination; it throws to refuse the copy, which is removed
     *
     * @throws ConversionFailedException when the source is not an acceptable output, or the copy fails
     * @throws \Throwable                what the verifier throws, the destination left as it was
     */
    public static function copy(string $source, string $destination, int $maxBytes, ?callable $verify = null): void
    {
        $stream = self::open($source, $maxBytes);
        $temporary = \dirname($destination) . '/.sic-' . bin2hex(random_bytes(8)) . '.tmp';
        $target = false;
        $created = false;
        $renamed = false;

        try {
            $stat = @fstat($stream);
            $target = @fopen($temporary, 'xb');
            $created = $target !== false;
            if (!is_array($stat) || $target === false || !self::transfer($stream, $target, $stat['size'])) {
                throw new ConversionFailedException('The output file could not be written.');
            }
            $closed = self::close($target);
            $target = false;
            if (!$closed) {
                throw new ConversionFailedException('The output file could not be written.');
            }
            if ($verify !== null) {
                $verify($temporary);
            }
            $renamed = self::silently(static function () use ($temporary, $destination): bool {
                return @rename($temporary, $destination);
            });
            if (!$renamed) {
                throw new ConversionFailedException('The output file could not be written.');
            }
        } finally {
            if ($target !== false) {
                self::close($target);
            }
            self::close($stream);
            if ($created && !$renamed) {
                self::silently(static function () use ($temporary): bool {
                    return @unlink($temporary);
                });
            }
        }
    }

    /**
     * Copies a stream to another, all of it: exactly $size bytes. False when a read or a write fails (a full
     * filesystem, a file size limit) or the source does not hold $size bytes, whatever the error handler does with
     * the warning PHP raises: the calls are silenced, and an exception the handler throws for them anyway (one that
     * ignores the @ operator) is taken as the failure.
     *
     * @param resource $from
     * @param resource $to
     */
    public static function transfer($from, $to, int $size): bool
    {
        return self::silently(static function () use ($from, $to, $size): bool {
            $copied = @stream_copy_to_stream($from, $to);

            return $copied !== false && $copied === $size && $size > 0 && @fflush($to);
        });
    }

    /**
     * Closes a stream; false when the close fails (the last buffered write) or raises an error.
     *
     * @param resource $stream
     */
    public static function close($stream): bool
    {
        return self::silently(static function () use ($stream): bool {
            return @fclose($stream);
        });
    }

    /**
     * Runs a file system call; false when it throws an \ErrorException (an error handler that turns the warnings of
     * silenced calls into exceptions).
     *
     * @param callable(): bool $call
     */
    private static function silently(callable $call): bool
    {
        try {
            return $call();
        } catch (\ErrorException $exception) {
            return false;
        }
    }

    /**
     * Opens an output for reading after the checks of the class description.
     *
     * @throws ConversionFailedException when the file is missing or not acceptable
     *
     * @return resource
     */
    public static function open(string $path, int $maxBytes)
    {
        clearstatcache(true, $path);
        $named = @lstat($path);
        if (!is_array($named)) {
            throw new ConversionFailedException('The conversion produced no output file.');
        }
        self::check($named, $maxBytes);
        $stream = @fopen($path, 'rbne');
        if ($stream === false) {
            throw new ConversionFailedException('The output file could not be opened.');
        }
        $opened = @fstat($stream);
        if (!is_array($opened) || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']) {
            fclose($stream);

            throw new ConversionFailedException('The output file was replaced while it was opened.');
        }

        try {
            self::check($opened, $maxBytes);
        } catch (ConversionFailedException $exception) {
            fclose($stream);

            throw $exception;
        }
        // The descriptor was opened non-blocking; reads from a regular file never block anyway.
        stream_set_blocking($stream, true);

        return $stream;
    }

    /**
     * @param array<int|string, int> $stat
     *
     * @throws ConversionFailedException
     */
    private static function check(array $stat, int $maxBytes): void
    {
        if (($stat['mode'] & 0170000) !== 0100000) {
            throw new ConversionFailedException('The conversion output is not a regular file.');
        }
        if ($stat['nlink'] !== 1) {
            throw new ConversionFailedException('The conversion output has other links.');
        }
        if (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) {
            throw new ConversionFailedException('The conversion output is owned by another user.');
        }
        if ($stat['size'] < 1) {
            throw new ConversionFailedException('The conversion output is empty.');
        }
        if ($stat['size'] > $maxBytes) {
            throw new ConversionFailedException('The conversion output is larger than allowed.');
        }
    }
}
