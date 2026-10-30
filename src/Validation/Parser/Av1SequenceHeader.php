<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;

/**
 * Finds the sequence header OBU at the start of AV1 data (an AVIF image item or the first sample of a track) and
 * reads the largest frame size it allows, which bounds every frame an AV1 decoder will allocate.
 *
 * Only the OBU headers before the sequence header (temporal delimiter, metadata, padding) and the fields of the
 * sequence header up to max_frame_height_minus_1 are read: at most MAX_BYTES bytes and MAX_OBUS OBUs, and every
 * variable-length field is bounded by those bytes. No frame or tile data is touched. A frame that precedes the
 * sequence header, or AV1 data without one, is invalid: an AV1 decoder cannot decode it.
 *
 * @internal
 */
final class Av1SequenceHeader
{
    /**
     * Bytes of AV1 data searched for the sequence header.
     */
    public const MAX_BYTES = 1024;

    /**
     * OBUs read before the sequence header must be found.
     */
    private const MAX_OBUS = 16;

    private const OBU_SEQUENCE_HEADER = 1;

    /**
     * OBU types that may precede the sequence header: temporal delimiter, metadata, padding.
     *
     * @var list<int>
     */
    private const SKIPPABLE_OBUS = [2, 5, 15];

    /**
     * @var string
     */
    private $payload;

    /**
     * Next bit to read in the payload.
     *
     * @var int
     */
    private $bit = 0;

    private function __construct(string $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Largest frame width and height declared by the first sequence header of the AV1 data at $offset.
     *
     * @param int $offset offset of the AV1 data in the image
     * @param int $length bytes of AV1 data from that offset
     *
     * @throws InvalidImageException when no sequence header can be read before the first frame
     *
     * @return array{0: int, 1: int} max_frame_width_minus_1 + 1, max_frame_height_minus_1 + 1
     */
    public static function maxFrameSize(ByteReader $reader, int $offset, int $length): array
    {
        if ($offset < 0 || $length < 1 || $offset > $reader->getSize() || $length > $reader->getSize() - $offset) {
            throw ByteReader::truncated();
        }
        $reader->seek($offset);
        $data = $reader->read(min($length, self::MAX_BYTES));
        $available = strlen($data);

        $position = 0;
        for ($obu = 0; $obu < self::MAX_OBUS && $position < $available; ++$obu) {
            $header = ord($data[$position]);
            $type = ($header >> 3) & 0x0F;
            if (($header & 0x80) !== 0) {
                throw self::invalid('an OBU header has its forbidden bit set');
            }
            $position += ($header & 0x04) !== 0 ? 2 : 1;
            $size = $available - $position;
            if (($header & 0x02) !== 0) {
                list($size, $position) = self::readLeb128($data, $position);
            }
            if ($position > $available) {
                throw self::invalid('an OBU header is cut');
            }

            if ($type === self::OBU_SEQUENCE_HEADER) {
                return (new self((string) substr($data, $position, $size)))->readMaxFrameSize();
            }
            if (!in_array($type, self::SKIPPABLE_OBUS, true)) {
                throw self::invalid('the data has no sequence header before its first frame');
            }
            $position += $size;
        }

        throw self::invalid('the data has no sequence header within its first bytes');
    }

    /**
     * Reads an unsigned LEB128 value of at most 8 bytes.
     *
     * @throws InvalidImageException
     *
     * @return array{0: int, 1: int} the value and the position after it
     */
    private static function readLeb128(string $data, int $position): array
    {
        $value = 0;
        for ($index = 0; $index < 8; ++$index) {
            if ($position >= strlen($data)) {
                throw self::invalid('an OBU size is cut');
            }
            $byte = ord($data[$position++]);
            $value |= ($byte & 0x7F) << (7 * $index);
            if (($byte & 0x80) === 0) {
                return [$value, $position];
            }
        }

        throw self::invalid('an OBU size is longer than 8 bytes');
    }

    /**
     * Reads sequence_header_obu() up to max_frame_height_minus_1 (AV1 specification, section 5.5).
     *
     * @throws InvalidImageException
     *
     * @return array{0: int, 1: int}
     */
    private function readMaxFrameSize(): array
    {
        $this->bits(3); // seq_profile
        $this->bits(1); // still_picture
        $reducedStillPictureHeader = $this->bits(1);
        if ($reducedStillPictureHeader === 1) {
            $this->bits(5); // seq_level_idx[0]
        } else {
            $decoderModelInfoPresent = 0;
            $bufferDelayLength = 0;
            if ($this->bits(1) === 1) { // timing_info_present_flag
                $this->bits(32); // num_units_in_display_tick
                $this->bits(32); // time_scale
                if ($this->bits(1) === 1) { // equal_picture_interval
                    $this->skipUvlc(); // num_ticks_per_picture_minus_1
                }
                $decoderModelInfoPresent = $this->bits(1);
                if ($decoderModelInfoPresent === 1) {
                    $bufferDelayLength = $this->bits(5) + 1;
                    $this->bits(32); // num_units_in_decoding_tick
                    $this->bits(10); // buffer_removal_time_length_minus_1, frame_presentation_time_length_minus_1
                }
            }
            $initialDisplayDelayPresent = $this->bits(1);
            $operatingPoints = $this->bits(5) + 1;
            for ($point = 0; $point < $operatingPoints; ++$point) {
                $this->bits(12); // operating_point_idc
                if ($this->bits(5) > 7) { // seq_level_idx
                    $this->bits(1); // seq_tier
                }
                if ($decoderModelInfoPresent === 1 && $this->bits(1) === 1) {
                    // decoder_buffer_delay, encoder_buffer_delay, low_delay_mode_flag
                    $this->bits($bufferDelayLength);
                    $this->bits($bufferDelayLength);
                    $this->bits(1);
                }
                if ($initialDisplayDelayPresent === 1 && $this->bits(1) === 1) {
                    $this->bits(4); // initial_display_delay_minus_1
                }
            }
        }
        $widthBits = $this->bits(4) + 1;
        $heightBits = $this->bits(4) + 1;

        return [$this->bits($widthBits) + 1, $this->bits($heightBits) + 1];
    }

    /**
     * Skips a uvlc() value: leading zero bits, a one bit, then as many bits as leading zeros (at most 32 are read).
     *
     * @throws InvalidImageException
     */
    private function skipUvlc(): void
    {
        $leadingZeros = 0;
        while ($this->bits(1) === 0) {
            ++$leadingZeros;
        }
        if ($leadingZeros < 32) {
            $this->bits($leadingZeros);
        }
    }

    /**
     * Reads $count bits (0 to 32), most significant first.
     *
     * @phpstan-impure
     *
     * @throws InvalidImageException when the payload ends first
     */
    private function bits(int $count): int
    {
        if ($this->bit + $count > 8 * strlen($this->payload)) {
            throw self::invalid('the sequence header is cut');
        }
        $value = 0;
        for ($index = 0; $index < $count; ++$index, ++$this->bit) {
            $value = ($value << 1) | ((ord($this->payload[$this->bit >> 3]) >> (7 - ($this->bit & 7))) & 1);
        }

        return $value;
    }

    private static function invalid(string $problem): InvalidImageException
    {
        return new InvalidImageException('Invalid AVIF: ' . $problem . '.');
    }
}
