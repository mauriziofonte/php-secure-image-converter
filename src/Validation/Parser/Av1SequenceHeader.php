<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;

/**
 * Walks the OBUs of AV1 data (an AVIF image item or the first sample of a track, and the configuration OBUs of its
 * av1C box) and reads, from every sequence header among them, the largest frame size it allows: an AV1 decoder accepts
 * a later sequence header that differs from the first one, so each of them bounds the frames it will allocate.
 *
 * Only OBU headers and sizes are read to skip from one OBU to the next, and the fields of each sequence header up to
 * max_frame_height_minus_1 (a sequence header may hold at most MAX_SEQUENCE_HEADER_BYTES): no frame or tile data is
 * touched. The caller counts the OBUs and sequence headers across all the AV1 data of a file, at most MAX_OBUS and
 * MAX_SEQUENCE_HEADERS, so that items or tracks sharing the same data cannot multiply the cost. Every OBU must fit in
 * the data, and only temporal delimiter, metadata and padding OBUs may precede the first sequence header: AV1 data
 * without one, or with a frame before it, is invalid, as an AV1 decoder cannot decode it. A sequence header whose
 * uvlc() field has 32 leading zeros or more is invalid too: decoders disagree on how many bits such a value takes.
 *
 * Not covered, because it needs the frame headers: a frame header can override the frame size with values up to
 * 2^frame_width_bits by 2^frame_height_bits, beyond max_frame_width and max_frame_height, unless the sequence header
 * is a reduced still picture header (as AV1 encoders write for still images). Frames are bounded there only by the
 * limits that the conversion backends give their decoders and processes.
 *
 * @internal
 */
final class Av1SequenceHeader
{
    /**
     * Largest number of OBUs walked in all the AV1 data of a file; real data holds a few per image or track.
     */
    public const MAX_OBUS = 8192;

    /**
     * Largest number of sequence headers read in all the AV1 data of a file; real data holds one per image or track,
     * and one in its av1C box: at most 4 x AvifParser::MAX_IMAGES for the items and the tracks of a file.
     */
    public const MAX_SEQUENCE_HEADERS = 64;

    /**
     * Leading zeros from which uvlc() values are refused.
     */
    private const UVLC_LEADING_ZEROS_MAX = 32;

    /**
     * Largest size of a sequence header OBU payload; real ones hold a few dozen bytes.
     */
    public const MAX_SEQUENCE_HEADER_BYTES = 1024;

    private const OBU_SEQUENCE_HEADER = 1;

    /**
     * OBU types that may precede the first sequence header: temporal delimiter, metadata, padding.
     *
     * @var list<int>
     */
    private const SKIPPABLE_OBUS = [2, 5, 15];

    /**
     * Bytes that hold any OBU header and size: header, extension and an 8-byte LEB128 size.
     */
    private const OBU_HEADER_MAX_BYTES = 10;

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
     * Largest frame width and height that any sequence header of the AV1 data at $offset declares.
     *
     * @param int $offset          offset of the AV1 data in the image
     * @param int $length          bytes of AV1 data from that offset
     * @param int $obus            OBUs already walked in the other AV1 data of the file, updated
     * @param int $sequenceHeaders sequence headers already read in the other AV1 data of the file, updated
     *
     * @throws InvalidImageException when an OBU does not fit in the data, there are too many, or no sequence header
     *                               comes before the first frame
     *
     * @return array{0: int, 1: int} the largest max_frame_width_minus_1 + 1 and max_frame_height_minus_1 + 1
     */
    public static function maxFrameSize(ByteReader $reader, int $offset, int $length, int &$obus = 0, int &$sequenceHeaders = 0): array
    {
        $size = self::walk($reader, $offset, $length, false, $obus, $sequenceHeaders);
        if ($size[0] === 0) {
            throw self::invalid('the data has no sequence header');
        }

        return $size;
    }

    /**
     * Largest frame width and height that any sequence header among the configuration OBUs of an av1C box declares,
     * or [0, 0] when there is none. Some decoders (libheif among them) feed these OBUs to the AV1 decoder before the
     * data of the image, so they are AV1 data like any other: a frame among them must follow a sequence header. Each
     * OBU must have a size field, as the av1C box requires: without one, it would run into the image data.
     *
     * @param int $offset          offset of the configuration OBUs in the image
     * @param int $length          bytes of configuration OBUs from that offset, 0 when there are none
     * @param int $obus            OBUs already walked in the other AV1 data of the file, updated
     * @param int $sequenceHeaders sequence headers already read in the other AV1 data of the file, updated
     *
     * @throws InvalidImageException when an OBU has no size field, does not fit in the data, or there are too many
     *
     * @return array{0: int, 1: int}
     */
    public static function configurationFrameSize(ByteReader $reader, int $offset, int $length, int &$obus = 0, int &$sequenceHeaders = 0): array
    {
        return $length === 0 ? [0, 0] : self::walk($reader, $offset, $length, true, $obus, $sequenceHeaders);
    }

    /**
     * Walks the OBUs of the data and reads every sequence header.
     *
     * @param bool $sizedObus whether every OBU must have a size field
     *
     * @throws InvalidImageException
     *
     * @return array{0: int, 1: int} the largest frame width and height, [0, 0] without a sequence header
     */
    private static function walk(ByteReader $reader, int $offset, int $length, bool $sizedObus, int &$obus, int &$sequenceHeaders): array
    {
        if ($offset < 0 || $length < 1 || $offset > $reader->getSize() || $length > $reader->getSize() - $offset) {
            throw ByteReader::truncated();
        }
        $end = $offset + $length;
        $width = 0;
        $height = 0;

        $position = $offset;
        while ($position < $end) {
            if (++$obus > self::MAX_OBUS) {
                throw self::invalid('the file holds too many OBUs');
            }
            $reader->seek($position);
            $head = $reader->read(min(self::OBU_HEADER_MAX_BYTES, $end - $position));
            $header = ord($head[0]);
            if (($header & 0x80) !== 0) {
                throw self::invalid('an OBU header has its forbidden bit set');
            }
            $type = ($header >> 3) & 0x0F;
            $cursor = ($header & 0x04) !== 0 ? 2 : 1;
            if ($cursor > strlen($head)) {
                throw self::invalid('an OBU header is cut');
            }
            if (($header & 0x02) !== 0) {
                list($size, $cursor) = self::readLeb128($head, $cursor);
            } elseif ($sizedObus) {
                throw self::invalid('an av1C configuration OBU has no size field');
            } else {
                // Without a size field the OBU runs to the end of the data.
                $size = $end - $position - $cursor;
            }
            if ($size > $end - $position - $cursor) {
                throw self::invalid('an OBU does not fit in the data');
            }

            if ($type === self::OBU_SEQUENCE_HEADER) {
                if (++$sequenceHeaders > self::MAX_SEQUENCE_HEADERS) {
                    throw self::invalid('the file holds too many sequence headers');
                }
                if ($size > self::MAX_SEQUENCE_HEADER_BYTES) {
                    throw self::invalid('a sequence header is too long');
                }
                $reader->seek($position + $cursor);
                list($frameWidth, $frameHeight) = (new self($reader->read($size)))->readMaxFrameSize();
                $width = max($width, $frameWidth);
                $height = max($height, $frameHeight);
            } elseif ($width === 0 && !in_array($type, self::SKIPPABLE_OBUS, true)) {
                throw self::invalid('the data has no sequence header before its first frame');
            }
            $position += $cursor + $size;
        }

        return [$width, $height];
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
     * Skips a uvlc() value: leading zero bits, a one bit, then as many value bits as leading zeros.
     *
     * @throws InvalidImageException when the value has UVLC_LEADING_ZEROS_MAX leading zeros or more, which the
     *                               specification reads as 2^32 - 1 after the one bit and some decoders without it
     */
    private function skipUvlc(): void
    {
        $leadingZeros = 0;
        while ($this->bits(1) === 0) {
            if (++$leadingZeros >= self::UVLC_LEADING_ZEROS_MAX) {
                throw self::invalid('a sequence header holds a uvlc() value with too many leading zeros');
            }
        }
        $this->bits($leadingZeros);
    }

    /**
     * Reads $count bits (0 to 32), most significant first, a byte at a time.
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
        while ($count > 0) {
            $available = 8 - ($this->bit & 7);
            $taken = min($available, $count);
            $byte = ord($this->payload[$this->bit >> 3]);
            $value = ($value << $taken) | (($byte >> ($available - $taken)) & ((1 << $taken) - 1));
            $this->bit += $taken;
            $count -= $taken;
        }

        return $value;
    }

    private static function invalid(string $problem): InvalidImageException
    {
        return new InvalidImageException('Invalid AVIF: ' . $problem . '.');
    }
}
