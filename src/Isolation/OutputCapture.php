<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;

/**
 * Reads the standard output and standard error pipes of a running process without blocking, and keeps up to a fixed
 * number of bytes of each. Further output is read and discarded, so that the process never blocks on a full pipe,
 * and the truncation is recorded.
 *
 * The pipes are polled with stream_select(), which works only with descriptors below FD_SETSIZE (1024): when PHP
 * holds that many descriptors open, the pipes get higher numbers and cannot be polled. stream_select() then warns,
 * and returns false (PHP 8) or 0 (PHP 7, as if no output were ready). Either way the output would be lost, so the
 * capture fails with ConversionFailedException instead, and also after repeated failures of any other kind: a run
 * whose output was not read is never reported as successful.
 *
 * @internal used by ProcessRunner; not covered by the backward compatibility promise
 */
final class OutputCapture
{
    private const READ_CHUNK_BYTES = 65536;

    /**
     * Consecutive stream_select() failures (for example interruptions by signals) tolerated before the output is
     * considered unreadable.
     */
    private const MAX_SELECT_FAILURES = 10;

    /**
     * Pause after a failed stream_select(), in microseconds.
     */
    private const FAILURE_PAUSE_MICROSECONDS = 1000;

    /**
     * Open pipes, keyed 1 (stdout) and 2 (stderr); closed ones are removed.
     *
     * @var array<int, resource>
     */
    private $streams;

    /**
     * @var int
     */
    private $maxBytes;

    /**
     * @var array<int, string>
     */
    private $output = [1 => '', 2 => ''];

    /**
     * @var array<int, bool>
     */
    private $truncated = [1 => false, 2 => false];

    /**
     * @var int
     */
    private $failures = 0;

    /**
     * The warning raised by the last stream_select() call.
     *
     * @var string|null
     */
    private $selectWarning;

    /**
     * @param array<int, resource> $streams  the read ends of the stdout (1) and stderr (2) pipes; set to
     *                                       non-blocking mode here
     * @param int                  $maxBytes bytes kept of each stream
     */
    public function __construct(array $streams, int $maxBytes)
    {
        $this->streams = [];
        foreach ([1, 2] as $key) {
            if (isset($streams[$key])) {
                stream_set_blocking($streams[$key], false);
                $this->streams[$key] = $streams[$key];
            }
        }
        $this->maxBytes = $maxBytes;
    }

    /**
     * Whether a pipe is still open, that is, more output may come.
     */
    public function isOpen(): bool
    {
        return $this->streams !== [];
    }

    /**
     * Waits up to $timeout seconds for output, then reads one chunk from each ready pipe; a pipe that reached its end
     * is closed.
     *
     * @throws ConversionFailedException when the pipes cannot be polled
     */
    public function read(float $timeout): void
    {
        if ($this->streams === []) {
            return;
        }
        $ready = $this->streams;
        $write = null;
        $except = null;
        $seconds = (int) floor($timeout);
        $microseconds = (int) (($timeout - $seconds) * 1000000);
        // The warning is captured instead of reported: an error handler of the application could turn it into an
        // exception, or hide it from error_get_last().
        $this->selectWarning = null;
        set_error_handler(function (int $severity, string $message): bool {
            $this->selectWarning = $message;

            return true;
        });
        try {
            $count = stream_select($ready, $write, $except, $seconds, $microseconds);
        } finally {
            restore_error_handler();
        }
        $warning = $this->selectWarning();
        if ($warning !== null && stripos($warning, 'FD_SETSIZE') !== false) {
            throw new ConversionFailedException(
                'The output of the process cannot be read: PHP holds more open descriptors than stream_select() '
                . 'supports (FD_SETSIZE).'
            );
        }
        if ($count === false || $warning !== null) {
            if (++$this->failures >= self::MAX_SELECT_FAILURES) {
                throw new ConversionFailedException(
                    'The output of the process cannot be read: stream_select() failed repeatedly.'
                );
            }
            usleep(self::FAILURE_PAUSE_MICROSECONDS);

            return;
        }
        $this->failures = 0;
        foreach ($ready as $stream) {
            $key = array_search($stream, $this->streams, true);
            if (!is_int($key)) {
                continue;
            }
            // A read error (false) counts as the end of the stream.
            $chunk = (string) @fread($stream, self::READ_CHUNK_BYTES);
            if ($chunk === '') {
                if (feof($stream)) {
                    fclose($stream);
                    unset($this->streams[$key]);
                }
                continue;
            }
            $room = $this->maxBytes - strlen($this->output[$key]);
            if (strlen($chunk) > $room) {
                $this->truncated[$key] = true;
                $chunk = $room > 0 ? (string) substr($chunk, 0, $room) : '';
            }
            $this->output[$key] .= $chunk;
        }
    }

    /**
     * Closes the pipes that are still open; the output read so far is kept.
     */
    public function close(): void
    {
        foreach ($this->streams as $stream) {
            fclose($stream);
        }
        $this->streams = [];
    }

    /**
     * The warning raised by the last stream_select() call, if any.
     */
    private function selectWarning(): ?string
    {
        return $this->selectWarning;
    }

    public function getStdout(): string
    {
        return $this->output[1];
    }

    public function getStderr(): string
    {
        return $this->output[2];
    }

    public function isStdoutTruncated(): bool
    {
        return $this->truncated[1];
    }

    public function isStderrTruncated(): bool
    {
        return $this->truncated[2];
    }
}
