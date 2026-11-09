<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Fixtures;

/**
 * Packs bits least significant first, as Deflate, GIF LZW and WEBP lossless streams do.
 */
final class BitWriter
{
    /**
     * @var string
     */
    private $bytes = '';

    /**
     * @var int
     */
    private $current = 0;

    /**
     * @var int
     */
    private $count = 0;

    /**
     * Writes the $length low bits of $value, least significant first.
     */
    public function write(int $value, int $length): void
    {
        for ($bit = 0; $bit < $length; ++$bit) {
            $this->current |= (($value >> $bit) & 1) << $this->count;
            if (++$this->count === 8) {
                $this->bytes .= pack('C', $this->current);
                $this->current = 0;
                $this->count = 0;
            }
        }
    }

    /**
     * Writes a Huffman code of $length bits, most significant first, as Deflate stores codes.
     */
    public function writeCode(int $code, int $length): void
    {
        for ($bit = $length - 1; $bit >= 0; --$bit) {
            $this->write(($code >> $bit) & 1, 1);
        }
    }

    /**
     * Writes $count zero bits, whole bytes at once.
     */
    public function writeZeros(int $count): void
    {
        for (; $count > 0 && $this->count !== 0; --$count) {
            $this->write(0, 1);
        }
        for ($bytes = intdiv($count, 8); $bytes > 0; $bytes -= 65536) {
            $this->bytes .= str_repeat("\0", min($bytes, 65536));
        }
        $this->write(0, $count % 8);
    }

    /**
     * The bytes written so far, the last one padded with zero bits.
     */
    public function getBytes(): string
    {
        return $this->bytes . ($this->count > 0 ? pack('C', $this->current) : '');
    }
}
