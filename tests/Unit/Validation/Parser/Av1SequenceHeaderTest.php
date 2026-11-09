<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Tests\Unit\Validation\CountingStreamWrapper;
use Mfonte\SecureImageConverter\Validation\Parser\Av1SequenceHeader;
use Mfonte\SecureImageConverter\Validation\Parser\ByteReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The sequence header fields before the maximum frame size, built bit by bit from the AV1 specification (section
 * 5.5), the OBUs that may or may not precede it, and the walk over every OBU of the data.
 *
 * @covers \Mfonte\SecureImageConverter\Validation\Parser\Av1SequenceHeader
 */
#[CoversClass(Av1SequenceHeader::class)]
final class Av1SequenceHeaderTest extends TestCase
{
    private const OBU_SEQUENCE_HEADER = 1;
    private const OBU_TEMPORAL_DELIMITER = 2;
    private const OBU_FRAME = 6;
    private const OBU_PADDING = 15;

    public function testReadsAReducedStillPictureHeader(): void
    {
        $data = self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(4000, 3000));

        self::assertSame([4000, 3000], self::maxFrameSize($data));
    }

    public function testReadsAFullHeaderWithTimingDecoderModelAndOperatingPoints(): void
    {
        $bits = '000' . '0' . '0' // seq_profile, still_picture, reduced_still_picture_header
            . '1' . self::field(1, 32) . self::field(25, 32) // timing_info_present_flag, display tick, time_scale
            . '1' . '00101' // equal_picture_interval, uvlc(4): two leading zeros, a one, then two bits
            . '1' . self::field(9, 5) . self::field(1, 32) . self::field(0, 10) // decoder model, buffer delay of 10 bits
            . '1' . self::field(1, 5) // initial_display_delay_present_flag, two operating points
            // Operating point 0: level 9 (so a tier bit), decoder model with two 10-bit delays, a display delay.
            . self::field(0, 12) . self::field(9, 5) . '1' . '1' . self::field(5, 10) . self::field(6, 10) . '0' . '1' . '0011'
            // Operating point 1: level 4 (no tier bit), no decoder model, no display delay.
            . self::field(0, 12) . self::field(4, 5) . '0' . '0'
            . self::field(12, 4) . self::field(10, 4) . self::field(8191, 13) . self::field(1079, 11);

        self::assertSame([8192, 1080], self::maxFrameSize(self::obu(self::OBU_SEQUENCE_HEADER, $bits)));
    }

    public function testSkipsTemporalDelimiterPaddingAndExtensionHeaders(): void
    {
        $data = self::obu(self::OBU_TEMPORAL_DELIMITER, '')
            . self::obu(self::OBU_PADDING, str_repeat('1', 24), true)
            . self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(64, 48), true);

        self::assertSame([64, 48], self::maxFrameSize($data));
    }

    public function testFindsASequenceHeaderAfterManyOtherObus(): void
    {
        $data = str_repeat(self::obu(self::OBU_TEMPORAL_DELIMITER, ''), Av1SequenceHeader::MAX_OBUS - 1)
            . self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(8, 8));

        self::assertSame([8, 8], self::maxFrameSize($data));
    }

    /**
     * Items or tracks can share the same data: the OBU and sequence header budgets are for the whole file.
     */
    public function testCountsObusAndSequenceHeadersAcrossTheDataOfAFile(): void
    {
        $data = str_repeat(self::obu(self::OBU_TEMPORAL_DELIMITER, ''), Av1SequenceHeader::MAX_OBUS - 2)
            . self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(8, 8))
            . self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(8, 8));
        $reader = new ByteReader(CountingStreamWrapper::open($data), strlen($data));
        $obus = 0;
        $sequenceHeaders = 0;

        self::assertSame([8, 8], Av1SequenceHeader::maxFrameSize($reader, 0, strlen($data), $obus, $sequenceHeaders));
        self::assertSame([Av1SequenceHeader::MAX_OBUS, 2], [$obus, $sequenceHeaders]);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('too many OBUs');

        Av1SequenceHeader::maxFrameSize($reader, strlen($data) - 9, 9, $obus, $sequenceHeaders);
    }

    /**
     * A decoder accepts a later sequence header that differs from the first one: the largest of them bounds the
     * frames.
     */
    public function testEverySequenceHeaderCounts(): void
    {
        $data = self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(8, 8))
            . self::obu(self::OBU_FRAME, '00000000')
            . self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(8200, 6))
            . self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(6, 4100))
            . self::obu(self::OBU_FRAME, '00000000');

        self::assertSame([8200, 4100], self::maxFrameSize($data));
    }

    public function testReadsASequenceHeaderWithoutSizeField(): void
    {
        // Without obu_has_size_field, the OBU extends to the end of the data.
        $data = pack('C', self::OBU_SEQUENCE_HEADER << 3) . self::bytes(self::reducedHeader(16, 16));

        self::assertSame([16, 16], self::maxFrameSize($data));
    }

    public function testReadsTheLongestBoundedUvlc(): void
    {
        $bits = '000' . '0' . '0' . '1' . self::field(1, 32) . self::field(25, 32)
            . '1' . str_repeat('0', 31) . '1' . str_repeat('1', 31) // uvlc with 31 leading zeros and 31 value bits
            . '0' . '0' . self::field(0, 5) . self::field(0, 12) . self::field(1, 5)
            . self::field(3, 4) . self::field(3, 4) . self::field(9, 4) . self::field(7, 4);

        self::assertSame([10, 8], self::maxFrameSize(self::obu(self::OBU_SEQUENCE_HEADER, $bits)));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unboundedUvlcLeadingZeros(): array
    {
        return ['32 leading zeros' => [32], '200 leading zeros' => [200]];
    }

    /**
     * The specification reads a uvlc() of 32 leading zeros as 2^32 - 1 after its one bit, and some decoders stop
     * before that bit: the fields that follow differ, so the value is refused, and so is any longer one.
     *
     * @dataProvider unboundedUvlcLeadingZeros
     */
    #[DataProvider('unboundedUvlcLeadingZeros')]
    public function testRefusesAnUnboundedUvlc(int $leadingZeros): void
    {
        $bits = '000' . '0' . '0' . '1' . self::field(1, 32) . self::field(25, 32)
            . '1' . str_repeat('0', $leadingZeros) . '1'
            . '0' . '0' . self::field(0, 5) . self::field(0, 12) . self::field(1, 5)
            . self::field(3, 4) . self::field(3, 4) . self::field(9, 4) . self::field(7, 4);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('too many leading zeros');

        self::maxFrameSize(self::obu(self::OBU_SEQUENCE_HEADER, $bits));
    }

    /**
     * The configuration OBUs of av1C reach the decoder before the image data: their sequence headers count, a frame
     * among them needs one, and each needs a size field.
     */
    public function testReadsTheConfigurationObus(): void
    {
        $data = self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(8200, 8200))
            . self::obu(self::OBU_FRAME, '00000000');
        $reader = new ByteReader(CountingStreamWrapper::open($data), strlen($data));
        $obus = 0;
        $sequenceHeaders = 0;

        self::assertSame([8200, 8200], Av1SequenceHeader::configurationFrameSize($reader, 0, strlen($data), $obus, $sequenceHeaders));
        self::assertSame([2, 1], [$obus, $sequenceHeaders]);
        self::assertSame([0, 0], Av1SequenceHeader::configurationFrameSize($reader, 0, 0));
        $padding = self::obu(self::OBU_PADDING, '00000000');
        self::assertSame([0, 0], Av1SequenceHeader::configurationFrameSize(new ByteReader(CountingStreamWrapper::open($padding), strlen($padding)), 0, strlen($padding)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidConfigurations(): array
    {
        return [
            'frame before the sequence header' => [self::obu(self::OBU_FRAME, '00000000') . self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(8, 8))],
            'OBU without size field' => [pack('C', self::OBU_SEQUENCE_HEADER << 3) . self::bytes(self::reducedHeader(8200, 8200))],
            'OBU larger than the data' => [pack('C', (self::OBU_PADDING << 3) | 0x02) . "\x05\x00"],
        ];
    }

    /**
     * @dataProvider invalidConfigurations
     */
    #[DataProvider('invalidConfigurations')]
    public function testRefusesInvalidConfigurationObus(string $data): void
    {
        $this->expectException(InvalidImageException::class);

        Av1SequenceHeader::configurationFrameSize(new ByteReader(CountingStreamWrapper::open($data), strlen($data)), 0, strlen($data));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidData(): array
    {
        return [
            'forbidden bit' => [pack('C', 0x80 | (self::OBU_SEQUENCE_HEADER << 3) | 0x02) . "\x01\x00"],
            'frame before the sequence header' => [self::obu(self::OBU_FRAME, '00000000') . self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(8, 8))],
            'cut sequence header' => [self::obu(self::OBU_SEQUENCE_HEADER, '00011')],
            'size longer than 8 bytes' => [pack('C', (self::OBU_SEQUENCE_HEADER << 3) | 0x02) . str_repeat("\x80", 8) . "\x01"],
            'cut size' => [pack('C', (self::OBU_PADDING << 3) | 0x02) . "\x80"],
            'cut extension header' => [pack('C', (self::OBU_SEQUENCE_HEADER << 3) | 0x04)],
            'too many OBUs' => [str_repeat(self::obu(self::OBU_TEMPORAL_DELIMITER, ''), Av1SequenceHeader::MAX_OBUS) . self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(8, 8))],
            'OBU larger than the data' => [self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(8, 8)) . pack('C', (self::OBU_FRAME << 3) | 0x02) . "\x05\x00"],
            'sequence header too long' => [
                pack('C', (self::OBU_SEQUENCE_HEADER << 3) | 0x02) . "\x81\x08" // LEB128 1025
                . self::bytes(self::reducedHeader(8, 8)) . str_repeat("\0", Av1SequenceHeader::MAX_SEQUENCE_HEADER_BYTES + 1 - 7),
            ],
            'too many sequence headers' => [str_repeat(self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(8, 8)), Av1SequenceHeader::MAX_SEQUENCE_HEADERS + 1)],
            'padding only' => [self::obu(self::OBU_PADDING, '00000000')],
        ];
    }

    /**
     * @dataProvider invalidData
     */
    #[DataProvider('invalidData')]
    public function testRefusesDataWithoutAReadableSequenceHeader(string $data): void
    {
        $this->expectException(InvalidImageException::class);

        self::maxFrameSize($data);
    }

    public function testRefusesARangeOutsideTheImage(): void
    {
        $reader = new ByteReader(CountingStreamWrapper::open('abc'), 3);

        $this->expectException(InvalidImageException::class);

        Av1SequenceHeader::maxFrameSize($reader, 2, 2);
    }

    public function testReadsOnlyTheGivenRange(): void
    {
        $header = self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(20, 10));
        $data = 'xx' . $header . 'yy';
        $reader = new ByteReader(CountingStreamWrapper::open($data), strlen($data));

        self::assertSame([20, 10], Av1SequenceHeader::maxFrameSize($reader, 2, strlen($header)));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function maxFrameSize(string $data): array
    {
        return Av1SequenceHeader::maxFrameSize(new ByteReader(CountingStreamWrapper::open($data), strlen($data)), 0, strlen($data));
    }

    /**
     * A reduced still picture header: profile 0, level 0, 16-bit size fields.
     */
    private static function reducedHeader(int $width, int $height): string
    {
        return '000' . '1' . '1' . self::field(0, 5) . self::field(15, 4) . self::field(15, 4)
            . self::field($width - 1, 16) . self::field($height - 1, 16);
    }

    /**
     * An OBU with a size field, optionally with an extension header, whose payload holds $bits padded to bytes.
     */
    private static function obu(int $type, string $bits, bool $extension = false): string
    {
        $payload = self::bytes($bits);

        return pack('C', ($type << 3) | ($extension ? 0x04 : 0) | 0x02) . ($extension ? "\x00" : '') . pack('C', strlen($payload)) . $payload;
    }

    /**
     * $value as $length bits, most significant first.
     */
    private static function field(int $value, int $length): string
    {
        return str_pad(decbin($value), $length, '0', STR_PAD_LEFT);
    }

    /**
     * A string of "0" and "1" characters packed most significant bit first, the last byte padded with zeros.
     */
    private static function bytes(string $bits): string
    {
        $bytes = '';
        foreach (str_split(str_pad($bits, 8 * intdiv(strlen($bits) + 7, 8), '0'), 8) as $byte) {
            $bytes .= pack('C', bindec($byte));
        }

        return $bytes;
    }
}
