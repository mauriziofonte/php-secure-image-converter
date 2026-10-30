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
 * 5.5), and the OBUs that may or may not precede it.
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

    public function testReadsASequenceHeaderWithoutSizeField(): void
    {
        // Without obu_has_size_field, the OBU extends to the end of the data.
        $data = pack('C', self::OBU_SEQUENCE_HEADER << 3) . self::bytes(self::reducedHeader(16, 16));

        self::assertSame([16, 16], self::maxFrameSize($data));
    }

    public function testAnUnboundedUvlcIsSkipped(): void
    {
        $bits = '000' . '0' . '0' . '1' . self::field(1, 32) . self::field(25, 32)
            . '1' . str_repeat('0', 32) . '1' // uvlc with 32 leading zeros: no value bits follow
            . '0' . '0' . self::field(0, 5) . self::field(0, 12) . self::field(1, 5)
            . self::field(3, 4) . self::field(3, 4) . self::field(9, 4) . self::field(7, 4);

        self::assertSame([10, 8], self::maxFrameSize(self::obu(self::OBU_SEQUENCE_HEADER, $bits)));
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
            'no sequence header in the first 16 OBUs' => [str_repeat(self::obu(self::OBU_TEMPORAL_DELIMITER, ''), 16) . self::obu(self::OBU_SEQUENCE_HEADER, self::reducedHeader(8, 8))],
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
