<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;

/**
 * Random-access reader over the bytes of an image held by a seekable stream, for the header parsers.
 *
 * The reader never reads at or beyond the size given to its constructor, whatever the stream holds, so a file that
 * grows while it is inspected cannot make it read more. It reads through a small buffer (BUFFER_SIZE bytes per
 * refill, or the length asked when larger), so that walking many small headers costs few system calls and skipping
 * data costs none. Any structure that points beyond the end of the image, and any short read, is reported as a
 * truncated image.
 *
 * @internal
 */
final class ByteReader
{
    /**
     * Bytes read from the stream per refill of the buffer.
     */
    public const BUFFER_SIZE = 8192;

    /**
     * Bytes read from the stream per step of contains().
     */
    private const SCAN_CHUNK_SIZE = 65536;

    /**
     * @var resource
     */
    private $stream;

    /**
     * @var int
     */
    private $size;

    /**
     * @var string
     */
    private $buffer = '';

    /**
     * Offset in the image of the first byte of the buffer.
     *
     * @var int
     */
    private $bufferOffset = 0;

    /**
     * @var int
     */
    private $position = 0;

    /**
     * @param resource $stream seekable stream holding the image from its offset 0
     * @param int      $size   size of the image in bytes, at least 0; the reader never reads at or beyond it
     *
     * @throws InvalidArgumentException when the stream is not a stream resource or the size is negative
     */
    public function __construct($stream, int $size)
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('ByteReader: expected an open stream resource.');
        }
        if ($size < 0) {
            throw new InvalidArgumentException('ByteReader: the size must be at least 0.');
        }
        $this->stream = $stream;
        $this->size = $size;
    }

    /**
     * The exception for a structure that extends beyond the end of the image.
     */
    public static function truncated(): InvalidImageException
    {
        return new InvalidImageException('The image is truncated: its structure extends beyond the end of the file.');
    }

    /**
     * Size of the image in bytes.
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Offset of the next byte to read.
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * Number of bytes between the position and the end of the image.
     */
    public function getRemaining(): int
    {
        return $this->size - $this->position;
    }

    /**
     * Moves to an absolute offset, from 0 to the size of the image.
     *
     * @throws InvalidImageException when the offset is beyond the end of the image
     */
    public function seek(int $offset): void
    {
        if ($offset < 0 || $offset > $this->size) {
            throw self::truncated();
        }
        $this->position = $offset;
    }

    /**
     * Moves forward without reading.
     *
     * @param int $length number of bytes to skip, at least 0
     *
     * @throws InvalidImageException when the end of the image comes first
     */
    public function skip(int $length): void
    {
        if ($length < 0 || $length > $this->size - $this->position) {
            throw self::truncated();
        }
        $this->position += $length;
    }

    /**
     * Reads exactly $length bytes.
     *
     * @param int $length at least 0
     *
     * @throws InvalidImageException when the end of the image comes first, or the stream returns fewer bytes
     *
     * @phpstan-impure
     */
    public function read(int $length): string
    {
        if ($length < 0 || $length > $this->size - $this->position) {
            throw self::truncated();
        }
        if ($length === 0) {
            return '';
        }

        $offset = $this->position - $this->bufferOffset;
        if ($offset < 0 || $offset + $length > strlen($this->buffer)) {
            $this->fill($length);
            $offset = 0;
        }
        $this->position += $length;

        return substr($this->buffer, $offset, $length);
    }

    /**
     * Reads up to $length bytes from the start of the image, fewer when the image is shorter. Never fails on a short
     * image, so that format detection can look at any file.
     *
     * @throws InvalidImageException when the stream returns fewer bytes than the size announced
     *
     * @phpstan-impure
     */
    public function readHead(int $length): string
    {
        $this->seek(0);

        return $this->read(min(max(0, $length), $this->size));
    }

    /**
     * @throws InvalidImageException
     *
     * @phpstan-impure
     */
    public function readUint8(): int
    {
        return ord($this->read(1));
    }

    /**
     * @throws InvalidImageException
     *
     * @phpstan-impure
     */
    public function readUint16Be(): int
    {
        return self::unpackInt('n', $this->read(2));
    }

    /**
     * @throws InvalidImageException
     *
     * @phpstan-impure
     */
    public function readUint16Le(): int
    {
        return self::unpackInt('v', $this->read(2));
    }

    /**
     * @throws InvalidImageException
     *
     * @phpstan-impure
     */
    public function readUint24Le(): int
    {
        return self::unpackInt('V', $this->read(3) . "\0");
    }

    /**
     * @throws InvalidImageException
     *
     * @phpstan-impure
     */
    public function readUint32Be(): int
    {
        return self::unpackInt('N', $this->read(4));
    }

    /**
     * @throws InvalidImageException
     *
     * @phpstan-impure
     */
    public function readUint32Le(): int
    {
        return self::unpackInt('V', $this->read(4));
    }

    /**
     * Skips a chain of GIF data sub-blocks (a length byte from 1 to 255 followed by that many bytes) up to and
     * including its terminator (a zero length byte), without interpreting the data.
     *
     * @param int $maxBlocks largest number of length bytes to consume, terminator included, at least 1
     *
     * @throws InvalidImageException when the image ends before the terminator
     *
     * @return int number of length bytes consumed, terminator included, or -1 when $maxBlocks were consumed without
     *             reaching the terminator (the position is then unspecified)
     *
     * @phpstan-impure
     */
    public function skipSubBlocks(int $maxBlocks): int
    {
        $count = 0;
        while (true) {
            $offset = $this->position - $this->bufferOffset;
            if ($offset < 0 || $offset >= strlen($this->buffer)) {
                if ($this->position >= $this->size) {
                    throw self::truncated();
                }
                $this->fill(1);
                $offset = 0;
            }

            // Walk the lengths in the buffer without a method call per block: this loop runs once per sub-block.
            $buffer = $this->buffer;
            $available = strlen($buffer);
            while ($offset < $available) {
                if (++$count > $maxBlocks) {
                    return -1;
                }
                $length = ord($buffer[$offset]);
                $offset += $length + 1;
                if ($length === 0) {
                    $this->position = $this->bufferOffset + $offset;

                    return $count;
                }
            }

            $this->position = $this->bufferOffset + $offset;
            if ($this->position > $this->size) {
                throw self::truncated();
            }
        }
    }

    /**
     * Whether $needle occurs between $from and the end of the image. Reads that whole range, in chunks, without
     * keeping it in memory.
     *
     * @param string $needle 1 to 64 bytes
     * @param int    $from   offset where the search starts, from 0 to the size of the image
     *
     * @throws InvalidArgumentException when the needle is empty or too long
     * @throws InvalidImageException    when the offset is beyond the image or the stream returns fewer bytes
     *
     * @phpstan-impure
     */
    public function contains(string $needle, int $from): bool
    {
        $needleLength = strlen($needle);
        if ($needleLength < 1 || $needleLength > 64) {
            throw new InvalidArgumentException('ByteReader: the needle must be 1 to 64 bytes long.');
        }

        $this->seek($from);
        $tail = '';
        while ($this->position < $this->size) {
            $length = min(self::SCAN_CHUNK_SIZE, $this->size - $this->position);
            $chunk = $tail . $this->read($length);
            if (strpos($chunk, $needle) !== false) {
                return true;
            }
            // Keep the last needle length - 1 bytes, so that a match across two chunks is found.
            $tail = $needleLength > 1 ? (string) substr($chunk, 1 - $needleLength) : '';
        }

        return false;
    }

    /**
     * Replaces the buffer with the bytes from the position: at least $length bytes, up to BUFFER_SIZE, never beyond
     * the end of the image.
     *
     * @throws InvalidImageException
     */
    private function fill(int $length): void
    {
        $wanted = min(max($length, self::BUFFER_SIZE), $this->size - $this->position);
        if ($wanted < $length || fseek($this->stream, $this->position) !== 0) {
            throw self::truncated();
        }

        $data = '';
        for ($missing = $wanted; $missing > 0; $missing = $wanted - strlen($data)) {
            $chunk = stream_get_contents($this->stream, $missing);
            if ($chunk === false || $chunk === '') {
                throw self::truncated();
            }
            $data .= $chunk;
        }

        $this->buffer = $data;
        $this->bufferOffset = $this->position;
    }

    private static function unpackInt(string $format, string $bytes): int
    {
        $value = unpack($format, $bytes);

        return is_array($value) && is_int($value[1]) ? $value[1] : 0;
    }
}
