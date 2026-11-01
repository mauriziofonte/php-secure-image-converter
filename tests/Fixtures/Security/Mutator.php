<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Fixtures\Security;

/**
 * Deterministic mutations of corpus files for the fuzz tests: the same seed gives the same inputs on every machine
 * and every PHP version, so that a failure found in CI is reproduced by running the test again with its seed.
 *
 * The random numbers come from SHA-256 in counter mode over the seed (no global generator is seeded or consumed).
 * Each mutation applies one to four of these operators, half of them within the first 64 bytes, where the headers
 * the inspector parses are:
 *
 * | Operator   | Effect                                                                                  |
 * | ---------- | --------------------------------------------------------------------------------------- |
 * | flip       | inverts one to eight random bits                                                        |
 * | byte       | sets a byte to 0x00, 0x01, 0x7F, 0x80 or 0xFF                                           |
 * | integer    | writes a 16- or 32-bit boundary value (0, 1, 0x7FFF, 0xFFFF, 0x7FFFFFFF, 0xFFFFFFFF), in |
 * |            | either byte order: the lengths, offsets, sizes and counts of the headers                 |
 * | truncate   | cuts the file at a random offset                                                         |
 * | insert     | inserts up to 64 random bytes                                                            |
 * | duplicate  | repeats a block of up to 1 KiB in place: a second header, chunk or frame                 |
 * | delete     | removes a block of up to 1 KiB                                                           |
 * | splice     | joins the start of the file with the end of another corpus file                          |
 */
final class Mutator
{
    private const HEADER_BYTES = 64;

    private const OPERATORS = ['flip', 'byte', 'integer', 'truncate', 'insert', 'duplicate', 'delete', 'splice'];

    private const BYTES = ["\x00", "\x01", "\x7F", "\x80", "\xFF"];

    private const INTEGERS = [0, 1, 0x7FFF, 0xFFFF, 0x7FFFFFFF, 0xFFFFFFFF];

    /**
     * @var string
     */
    private $seed;

    /**
     * @var int
     */
    private $counter = 0;

    /**
     * Unused random bytes of the last block.
     *
     * @var string
     */
    private $pool = '';

    public function __construct(string $seed)
    {
        $this->seed = $seed;
    }

    /**
     * The seed of the fuzz tests: SIC_FUZZ_SEED, or a fixed default so that CI runs are repeatable.
     */
    public static function seedFromEnvironment(string $default = '20261101'): string
    {
        $seed = getenv('SIC_FUZZ_SEED');

        return is_string($seed) && $seed !== '' ? $seed : $default;
    }

    /**
     * A positive count from an environment variable, or the default when it is unset or not a positive integer.
     */
    public static function countFromEnvironment(string $name, int $default): int
    {
        $value = getenv($name);

        return is_string($value) && preg_match('/\A[1-9][0-9]{0,8}\z/', $value) === 1 ? (int) $value : $default;
    }

    /**
     * A random integer from 0 to $max - 1.
     */
    public function below(int $max): int
    {
        if ($max <= 1) {
            return 0;
        }
        if (strlen($this->pool) < 4) {
            $this->pool .= hash('sha256', $this->seed . ':' . $this->counter++, true);
        }
        $value = unpack('N', substr($this->pool, 0, 4));
        $this->pool = (string) substr($this->pool, 4);

        return (is_array($value) && is_int($value[1]) ? $value[1] : 0) % $max;
    }

    /**
     * $count random bytes.
     */
    private function bytes(int $count): string
    {
        while (strlen($this->pool) < $count) {
            $this->pool .= hash('sha256', $this->seed . ':' . $this->counter++, true);
        }
        $bytes = (string) substr($this->pool, 0, $count);
        $this->pool = (string) substr($this->pool, $count);

        return $bytes;
    }

    /**
     * A random element of a non-empty list.
     *
     * @template T
     *
     * @param non-empty-list<T> $items
     *
     * @return T
     */
    public function pick(array $items)
    {
        return $items[$this->below(count($items))];
    }

    /**
     * Mutates $bytes with one to four operators; $other is the file a splice joins.
     *
     * @return array{0: string, 1: string} the mutated bytes and a description of the operators applied
     */
    public function mutate(string $bytes, string $other): array
    {
        $applied = [];
        for ($count = 1 + $this->below(4); $count > 0; --$count) {
            $operator = $this->pick(self::OPERATORS);
            list($bytes, $description) = $this->apply($operator, $bytes, $other);
            $applied[] = $description;
        }

        return [$bytes, implode(', ', $applied)];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function apply(string $operator, string $bytes, string $other): array
    {
        $length = strlen($bytes);
        $offset = $this->offset($length);
        switch ($operator) {
            case 'flip':
                for ($flips = 1 + $this->below(8), $done = 0; $done < $flips && $length > 0; ++$done) {
                    $at = $this->offset($length);
                    $bytes[$at] = $bytes[$at] ^ "\x01\x02\x04\x08\x10\x20\x40\x80"[$this->below(8)];
                }

                return [$bytes, sprintf('flip bits near %d', $offset)];
            case 'byte':
                if ($length === 0) {
                    return [$bytes, 'byte (empty)'];
                }
                $value = $this->pick(self::BYTES);
                $bytes[$offset] = $value;

                return [$bytes, sprintf('byte 0x%02X at %d', ord($value), $offset)];
            case 'integer':
                $value = $this->pick(self::INTEGERS);
                $wide = $this->below(2) === 1;
                $big = $this->below(2) === 1;
                $packed = $wide ? pack($big ? 'N' : 'V', $value) : pack($big ? 'n' : 'v', $value & 0xFFFF);

                return [
                    substr($bytes, 0, $offset) . $packed . (string) substr($bytes, $offset + strlen($packed)),
                    sprintf('%d-bit %s 0x%X at %d', $wide ? 32 : 16, $big ? 'big-endian' : 'little-endian', $wide ? $value : $value & 0xFFFF, $offset),
                ];
            case 'truncate':
                return [(string) substr($bytes, 0, $offset), sprintf('truncate at %d', $offset)];
            case 'insert':
                $inserted = $this->bytes(1 + $this->below(64));

                return [substr($bytes, 0, $offset) . $inserted . (string) substr($bytes, $offset), sprintf('insert %d bytes at %d', strlen($inserted), $offset)];
            case 'duplicate':
                $block = (string) substr($bytes, $offset, 1 + $this->below(1024));

                return [substr($bytes, 0, $offset) . $block . (string) substr($bytes, $offset), sprintf('duplicate %d bytes at %d', strlen($block), $offset)];
            case 'delete':
                $size = 1 + $this->below(1024);

                return [substr($bytes, 0, $offset) . (string) substr($bytes, $offset + $size), sprintf('delete %d bytes at %d', $size, $offset)];
            default:
                $from = $this->offset(strlen($other));

                return [substr($bytes, 0, $offset) . (string) substr($other, $from), sprintf('splice at %d with another file from %d', $offset, $from)];
        }
    }

    /**
     * An offset within the file, in its first HEADER_BYTES bytes half of the time.
     */
    private function offset(int $length): int
    {
        if ($length === 0) {
            return 0;
        }

        return $this->below(2) === 0 ? $this->below(min($length, self::HEADER_BYTES)) : $this->below($length);
    }
}
