<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Validation;

/**
 * Read-only in-memory stream wrapper that counts the bytes and calls of every read, to prove how much of a file the
 * inspector reads. PHP reads a user stream through its own buffer, in chunks of 8 KiB, so the count is what PHP
 * fetched from the file: an upper bound of what the inspector asked for. fstat() may report a size larger than the
 * data, to stand for a huge file. PHP instantiates stream wrappers itself, so the data and the counters are static.
 */
final class CountingStreamWrapper
{
    public const SCHEME = 'counting-test';

    /**
     * Bytes returned by every read since the last open().
     *
     * @var int
     */
    public static $bytesRead = 0;

    /**
     * Read calls since the last open().
     *
     * @var int
     */
    public static $reads = 0;

    /**
     * Set by PHP when a context is given.
     *
     * @var resource|null
     */
    public $context;

    /**
     * @var array<string, array{0: string, 1: int}>
     */
    private static $files = [];

    /**
     * @var string
     */
    private $data = '';

    /**
     * @var int
     */
    private $size = 0;

    /**
     * @var int
     */
    private $position = 0;

    /**
     * Opens a stream over $data and resets the counters.
     *
     * @param int|null $reportedSize size fstat() reports; strlen($data) when null
     *
     * @return resource
     */
    public static function open(string $data, ?int $reportedSize = null)
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        $name = self::SCHEME . '://' . count(self::$files);
        self::$files[$name] = [$data, $reportedSize === null ? strlen($data) : $reportedSize];
        $stream = fopen($name, 'rb');
        if ($stream === false) {
            throw new \LogicException('CountingStreamWrapper: the stream cannot be opened.');
        }
        unset(self::$files[$name]);
        self::$bytesRead = 0;
        self::$reads = 0;

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (!isset(self::$files[$path]) || strpbrk($mode, 'wax+') !== false) {
            return false;
        }
        list($this->data, $this->size) = self::$files[$path];

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = (string) substr($this->data, $this->position, $count);
        $this->position += strlen($chunk);
        self::$bytesRead += strlen($chunk);
        ++self::$reads;

        return $chunk;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $base = [SEEK_SET => 0, SEEK_CUR => $this->position, SEEK_END => $this->size];
        if (!isset($base[$whence]) || $base[$whence] + $offset < 0) {
            return false;
        }
        $this->position = $base[$whence] + $offset;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['mode' => 0100444, 'size' => $this->size];
    }
}
