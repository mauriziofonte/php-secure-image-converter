<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Validation;

use Mfonte\SecureImageConverter\Validation\FormatSniffer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Validation\FormatSniffer
 */
#[CoversClass(FormatSniffer::class)]
final class FormatSnifferTest extends TestCase
{
    private static function ftyp(string $major, string ...$compatible): string
    {
        $payload = $major . "\0\0\0\0" . implode('', $compatible);

        return pack('N', 8 + strlen($payload)) . 'ftyp' . $payload;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function identifiedHeads(): array
    {
        $bmp = static function (int $dibSize): string {
            return 'BM' . pack('V', 1000) . "\0\0\0\0" . pack('V', 54) . pack('V', $dibSize) . str_repeat("\0", 36);
        };

        $cases = [
            'JPEG/JFIF' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF\x00", 'jpeg'],
            'JPEG/Exif' => ["\xFF\xD8\xFF\xE1\x12\x34Exif\x00\x00", 'jpeg'],
            'JPEG, minimal head' => ["\xFF\xD8\xFF", 'jpeg'],
            'PNG' => ["\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR", 'png'],
            'GIF87a' => ['GIF87a' . "\x01\x00\x01\x00\x00\x00\x00", 'gif'],
            'GIF89a' => ['GIF89a', 'gif'],
            'WEBP' => ['RIFF' . pack('V', 26) . 'WEBPVP8L', 'webp'],
            'TIFF little-endian' => ["II*\0\x08\0\0\0", 'tiff'],
            'TIFF big-endian' => ["MM\0*\0\0\0\x08", 'tiff'],
            'AVIF major brand' => [self::ftyp('avif', 'mif1', 'miaf'), 'avif'],
            'AVIF compatible brand' => [self::ftyp('mif1', 'miaf', 'avif'), 'avif'],
            'AVIF sequence' => [self::ftyp('avis', 'msf1', 'iso8'), 'avif'],
            'SVG' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', 'svg'],
            'SVG, bare element' => ['<svg>', 'svg'],
            'SVG, self-closing' => ['<svg/>', 'svg'],
            'SVG, newline after the name' => ["<svg\nwidth=\"1\">", 'svg'],
            'SVG with byte order mark and whitespace' => ["\xEF\xBB\xBF \r\n\t<svg>", 'svg'],
            'SVG with XML declaration' => ["<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg>", 'svg'],
            'SVG with comments and stylesheet' => ["<?xml version=\"1.0\"?><!-- a <svg> in a comment --><?xml-stylesheet href=\"a.css\"?>\n<!-- --><svg>", 'svg'],
            'SVG with doctype' => ["<?xml version=\"1.0\"?>\n<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\">\n<svg>", 'svg'],
            'SVG with doctype internal subset' => ["<!DOCTYPE svg [\n<!ENTITY ns \"a>b\">\n<!-- ]> don't -->\n<!ENTITY q 'x]y'>\n]>\n<svg>", 'svg'],
        ];
        foreach ([12, 16, 40, 52, 56, 64, 108, 124] as $dibSize) {
            $cases['BMP with a DIB header of ' . $dibSize . ' bytes'] = [$bmp($dibSize), 'bmp'];
        }

        return $cases;
    }

    /**
     * @dataProvider identifiedHeads
     */
    #[DataProvider('identifiedHeads')]
    public function testFormatIsIdentifiedFromMagicBytes(string $head, string $format): void
    {
        self::assertSame($format, (new FormatSniffer())->detect($head));
        self::assertSame($format, (new FormatSniffer())->detect($head . str_repeat("\0", 5000)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unidentifiedHeads(): array
    {
        return [
            'empty' => [''],
            'JPEG SOI without a marker' => ["\xFF\xD8"],
            'JPEG SOI followed by data' => ["\xFF\xD8\x00\x10"],
            'PNG signature truncated' => ["\x89PNG\r\n\x1A"],
            'PNG signature altered' => ["\x89PNG\n\r\x1A\n"],
            'GIF of an unknown version' => ['GIF88a'],
            'BMP magic followed by text' => ['BMW engines are fast and reliable'],
            'BMP magic with an unknown DIB header size' => ['BM' . str_repeat("\0", 12) . pack('V', 41) . str_repeat("\0", 36)],
            'BMP magic too short' => ['BM' . str_repeat("\0", 14)],
            'RIFF AVI' => ['RIFF' . pack('V', 1000) . 'AVI LIST'],
            'RIFF WAVE' => ['RIFF' . pack('V', 1000) . 'WAVEfmt '],
            'BigTIFF' => ["II+\0\x08\0\0\0"],
            'HEIC' => [self::ftyp('heic', 'mif1', 'heic')],
            'HEIF without an AVIF brand' => [self::ftyp('mif1', 'mif1', 'miaf')],
            'MP4' => [self::ftyp('isom', 'isom', 'mp41')],
            'ftyp box too short' => [pack('N', 12) . 'ftypavif' . "\0\0\0\0"],
            'AVIF brand after the end of the ftyp box' => [pack('N', 16) . 'ftypmif1' . "\0\0\0\0" . 'avif'],
            'PDF' => ["%PDF-1.7\n%\xE2\xE3\xCF\xD3"],
            'ZIP' => ["PK\x03\x04\x14\x00"],
            'HTML before a PNG signature' => ["<html><body>\x89PNG\r\n\x1A\n"],
            'HTML with inline SVG' => ['<!DOCTYPE html><html><body><svg></svg></body></html>'],
            'XML of another vocabulary' => ['<?xml version="1.0"?><rss version="2.0"></rss>'],
            'element whose name starts with svg' => ['<svgx>'],
            'SVG element at the very end of the head' => ['<svg'],
            'unterminated XML declaration' => ['<?xml version="1.0" <svg>'],
            'unterminated comment' => ['<!-- <svg>'],
            'unterminated doctype' => ['<!DOCTYPE svg [ <svg>'],
            'unterminated doctype literal' => ['<!DOCTYPE svg "<svg>'],
            'text before the root element' => ['hello <svg>'],
            'UTF-16 SVG' => ["\xFF\xFE<\0s\0v\0g\0>\0"],
            'SVG root beyond the head' => ['<!--' . str_repeat('-', 4100) . '--><svg>'],
        ];
    }

    /**
     * @dataProvider unidentifiedHeads
     */
    #[DataProvider('unidentifiedHeads')]
    public function testUnknownContentIsNotIdentified(string $head): void
    {
        self::assertNull((new FormatSniffer())->detect($head));
    }

    public function testOnlyTheFirstHeadLengthBytesAreConsidered(): void
    {
        $sniffer = new FormatSniffer();
        $padding = str_repeat(' ', FormatSniffer::HEAD_LENGTH - 5);

        self::assertSame('svg', $sniffer->detect($padding . '<svg>'));
        self::assertNull($sniffer->detect($padding . ' <svg>'));
    }
}
