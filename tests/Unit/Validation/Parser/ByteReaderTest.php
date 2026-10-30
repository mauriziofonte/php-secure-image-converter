<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Tests\Unit\Validation\CountingStreamWrapper;
use Mfonte\SecureImageConverter\Validation\Parser\ByteReader;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Validation\Parser\ByteReader
 */
#[CoversClass(ByteReader::class)]
final class ByteReaderTest extends TestCase
{
    public function testReadsIntegersInBothByteOrders(): void
    {
        $reader = self::reader("\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10");

        self::assertSame(0x01, $reader->readUint8());
        self::assertSame(0x0203, $reader->readUint16Be());
        self::assertSame(0x0504, $reader->readUint16Le());
        self::assertSame(0x080706, $reader->readUint24Le());
        self::assertSame(0x090A0B0C, $reader->readUint32Be());
        self::assertSame(0x100F0E0D, $reader->readUint32Le());
        self::assertSame(16, $reader->getPosition());
        self::assertSame(0, $reader->getRemaining());
    }

    public function testUnsignedIntegersStayPositiveOnHighBits(): void
    {
        $reader = self::reader("\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF");

        self::assertSame(0xFFFFFFFF, $reader->readUint32Be());
        self::assertSame(0xFFFFFFFF, $reader->readUint32Le());
    }

    public function testSeekSkipAndReadMoveThePosition(): void
    {
        $reader = self::reader('0123456789');

        $reader->seek(3);
        self::assertSame('345', $reader->read(3));
        $reader->skip(2);
        self::assertSame(8, $reader->getPosition());
        self::assertSame('', $reader->read(0));
        self::assertSame('89', $reader->read(2));
        $reader->seek(10);
        self::assertSame(0, $reader->getRemaining());
        $reader->seek(0);
        self::assertSame('0', $reader->read(1));
    }

    public function testReadingBeyondTheEndIsATruncatedImage(): void
    {
        $reader = self::reader('0123');
        $reader->seek(2);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('truncated');

        $reader->read(3);
    }

    public function testSeekingBeyondTheEndIsATruncatedImage(): void
    {
        $this->expectException(InvalidImageException::class);

        self::reader('0123')->seek(5);
    }

    public function testSkippingBeyondTheEndIsATruncatedImage(): void
    {
        $reader = self::reader('0123');
        $reader->skip(4);

        $this->expectException(InvalidImageException::class);

        $reader->skip(1);
    }

    public function testNegativeLengthsAreRefused(): void
    {
        $this->expectException(InvalidImageException::class);

        self::reader('0123')->read(-1);
    }

    public function testNeverReadsAtOrBeyondTheGivenSize(): void
    {
        $stream = CountingStreamWrapper::open('0123456789SECRET');
        $reader = new ByteReader($stream, 10);

        self::assertSame('0123456789', $reader->readHead(4096));
        $this->expectException(InvalidImageException::class);

        $reader->read(1);
    }

    public function testReadHeadReturnsTheWholeOfAShortImage(): void
    {
        $reader = self::reader('abc');
        $reader->seek(2);

        self::assertSame('abc', $reader->readHead(10));
        self::assertSame('', self::reader('')->readHead(10));
    }

    public function testAStreamShorterThanItsSizeIsATruncatedImage(): void
    {
        $reader = new ByteReader(CountingStreamWrapper::open('0123', 100), 100);

        $this->expectException(InvalidImageException::class);

        $reader->readHead(50);
    }

    public function testReadsThroughABufferAndSkipsWithoutReading(): void
    {
        $data = str_repeat('a', 100000);
        $reader = new ByteReader(CountingStreamWrapper::open($data), strlen($data));

        for ($index = 0; $index < 100; ++$index) {
            $reader->readUint8();
        }
        self::assertSame(ByteReader::BUFFER_SIZE, CountingStreamWrapper::$bytesRead, 'one refill serves many small reads');

        $reader->skip(94900);
        self::assertSame(ByteReader::BUFFER_SIZE, CountingStreamWrapper::$bytesRead, 'skipping reads nothing');

        $reader->readUint8();
        self::assertSame(ByteReader::BUFFER_SIZE + 5000, CountingStreamWrapper::$bytesRead, 'the last refill stops at the size');
    }

    public function testReadsLongerThanTheBufferAreServedInOneRefill(): void
    {
        $data = str_repeat('b', 3 * ByteReader::BUFFER_SIZE);
        $reader = new ByteReader(CountingStreamWrapper::open($data), strlen($data));

        self::assertSame($data, $reader->read(strlen($data)));
        self::assertSame(strlen($data), CountingStreamWrapper::$bytesRead);
    }

    public function testSkipSubBlocksCountsLengthBytesUpToTheTerminator(): void
    {
        $reader = self::reader("\x03abc\x01d\x00tail");

        self::assertSame(3, $reader->skipSubBlocks(10));
        self::assertSame(7, $reader->getPosition());
        self::assertSame('tail', $reader->read(4));
    }

    public function testSkipSubBlocksStopsWhenTheBudgetIsSpent(): void
    {
        $reader = self::reader(str_repeat("\x01x", 100) . "\x00");

        self::assertSame(-1, $reader->skipSubBlocks(100));
        $reader->seek(0);
        self::assertSame(101, $reader->skipSubBlocks(101));
    }

    public function testSkipSubBlocksAcrossBufferRefills(): void
    {
        $blocks = str_repeat("\xFF" . str_repeat('x', 255), 100) . "\x00";
        $reader = self::reader('pad' . $blocks);
        $reader->seek(3);

        self::assertSame(101, $reader->skipSubBlocks(1000));
        self::assertSame(strlen($blocks) + 3, $reader->getPosition());
    }

    public function testSkipSubBlocksWithoutTerminatorIsATruncatedImage(): void
    {
        $reader = self::reader("\x05abc");

        $this->expectException(InvalidImageException::class);

        $reader->skipSubBlocks(10);
    }

    public function testSkipSubBlocksAtTheEndIsATruncatedImage(): void
    {
        $reader = self::reader("\x02ab");
        $reader->seek(3);

        $this->expectException(InvalidImageException::class);

        $reader->skipSubBlocks(10);
    }

    public function testContainsFindsANeedleAcrossScanChunks(): void
    {
        $data = str_repeat("\0", 65535) . "\xFF\xD9" . str_repeat("\0", 10);

        self::assertTrue(self::reader($data)->contains("\xFF\xD9", 0));
        self::assertTrue(self::reader($data)->contains("\xFF\xD9", 65535));
        self::assertFalse(self::reader($data)->contains("\xFF\xD9", 65536));
        self::assertFalse(self::reader(str_repeat("\0", 200000))->contains("\xFF\xD9", 0));
        self::assertTrue(self::reader('abc')->contains('c', 2));
    }

    public function testContainsRefusesAnEmptyOrLongNeedle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::reader('abc')->contains('', 0);
    }

    public function testContainsRefusesANeedleLongerThan64Bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::reader('abc')->contains(str_repeat('a', 65), 0);
    }

    public function testConstructorRefusesANegativeSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ByteReader(CountingStreamWrapper::open(''), -1);
    }

    public function testConstructorRefusesAClosedStream(): void
    {
        $stream = CountingStreamWrapper::open('');
        fclose($stream);

        $this->expectException(InvalidArgumentException::class);

        new ByteReader($stream, 0);
    }

    private static function reader(string $data): ByteReader
    {
        return new ByteReader(CountingStreamWrapper::open($data), strlen($data));
    }
}
