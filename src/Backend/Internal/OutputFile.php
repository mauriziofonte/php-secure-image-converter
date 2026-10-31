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
 * destination, which is renamed over the destination: a process that still holds the original open cannot change
 * the copy.
 *
 * @internal
 */
final class OutputFile
{
    /**
     * Copies the output of a run to its destination.
     *
     * @param string $source      the output file inside the working directory
     * @param string $destination absolute path to write, replaced atomically
     * @param int    $maxBytes    largest accepted size
     *
     * @throws ConversionFailedException when the source is not an acceptable output, or the copy fails
     */
    public static function copy(string $source, string $destination, int $maxBytes): void
    {
        $stream = self::open($source, $maxBytes);
        $temporary = \dirname($destination) . '/.sic-' . bin2hex(random_bytes(8)) . '.tmp';
        $target = @fopen($temporary, 'xb');
        if ($target === false) {
            fclose($stream);

            throw new ConversionFailedException('The output file could not be written.');
        }
        $copied = stream_copy_to_stream($stream, $target);
        $closed = fclose($target);
        fclose($stream);
        if ($copied === false || $copied < 1 || !$closed || !@rename($temporary, $destination)) {
            @unlink($temporary);

            throw new ConversionFailedException('The output file could not be written.');
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
