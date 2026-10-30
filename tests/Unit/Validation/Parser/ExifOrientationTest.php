<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Validation\Parser;

use Mfonte\SecureImageConverter\Tests\Fixtures\ImageBuilder;
use Mfonte\SecureImageConverter\Tests\Unit\Validation\CountingStreamWrapper;
use Mfonte\SecureImageConverter\Validation\Parser\ByteReader;
use Mfonte\SecureImageConverter\Validation\Parser\ExifOrientation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Validation\Parser\ExifOrientation
 */
#[CoversClass(ExifOrientation::class)]
final class ExifOrientationTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function blocks(): array
    {
        $entry = static function (int $tag, int $type, int $count, int $value): string {
            return pack('vvVvv', $tag, $type, $count, $value, 0);
        };
        $directory = static function (string ...$entries): string {
            return "II*\0" . pack('V', 8) . pack('v', count($entries)) . implode('', $entries) . pack('V', 0);
        };

        return [
            'little endian' => [ImageBuilder::exifOrientation(6), 6],
            'big endian' => [ImageBuilder::exifOrientation(3, true), 3],
            'after another tag' => [$directory($entry(0x010F, 2, 4, 0), $entry(0x0112, 3, 1, 7)), 7],
            'no orientation tag' => [$directory($entry(0x010F, 2, 4, 0)), 1],
            'orientation of type LONG' => [$directory($entry(0x0112, 4, 1, 6)), 1],
            'orientation with two values' => [$directory($entry(0x0112, 3, 2, 6)), 1],
            'orientation zero' => [ImageBuilder::exifOrientation(0), 1],
            'unknown byte order' => ['XX*' . (string) substr(ImageBuilder::exifOrientation(6), 3), 1],
            'directory inside the header' => [ImageBuilder::patch(ImageBuilder::exifOrientation(6), 4, pack('V', 4)), 1],
            'directory beyond the block' => [ImageBuilder::patch(ImageBuilder::exifOrientation(6), 4, pack('V', 1000)), 1],
            'entries beyond the block' => [ImageBuilder::patch(ImageBuilder::exifOrientation(6), 8, pack('v', 2)), 1],
            'too many entries' => [ImageBuilder::patch(ImageBuilder::exifOrientation(6), 8, pack('v', ExifOrientation::MAX_ENTRIES + 1)), 1],
            'shorter than a TIFF header' => ['II*', 1],
        ];
    }

    /**
     * @dataProvider blocks
     */
    #[DataProvider('blocks')]
    public function testReadsTheOrientationOrFallsBackToNormal(string $block, int $orientation): void
    {
        $data = 'prefix' . $block;
        $reader = new ByteReader(CountingStreamWrapper::open($data), strlen($data));

        self::assertSame($orientation, ExifOrientation::read($reader, 6, strlen($block)));
    }

    public function testABlockOutsideTheImageIsIgnored(): void
    {
        $data = ImageBuilder::exifOrientation(6);
        $reader = new ByteReader(CountingStreamWrapper::open($data), strlen($data));

        self::assertSame(1, ExifOrientation::read($reader, 4, strlen($data)));
        self::assertSame(1, ExifOrientation::read($reader, -1, 8));
    }
}
