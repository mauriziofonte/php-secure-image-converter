<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Fixtures;

/**
 * Deterministic encoders for the test images: every byte is computed here, with no image library, so that the corpus
 * is the same on every machine and every PHP version.
 *
 * The images are small and uniform: PNG with stored (uncompressed) Deflate blocks, baseline and progressive JPEG with
 * one-symbol Huffman tables (every coefficient is zero), GIF with a clear code before every pixel, uncompressed or
 * RLE8 BMP, lossless WEBP with one-symbol prefix codes, uncompressed TIFF. Lossy WEBP and AVIF need a real VP8 or AV1
 * bitstream, so they come from the small encoded samples below. The valid images decode with libpng, libjpeg, giflib,
 * libwebp, libavif and libtiff; the malformed ones are built from them with patch().
 */
final class ImageBuilder
{
    public const PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    /**
     * A 16 x 16 lossy WEBP (simple format, VP8 bitstream).
     */
    private const WEBP_LOSSY = 'UklGRjYAAABXRUJQVlA4ICoAAAAwAQCdASoQABAAAsBMJaAAA3AA/uQBf//ggv6HP5crw///Yv2B/EsaAAA=';

    /**
     * An 8 x 8 still AVIF (primary item av01, 8-bit).
     */
    private const AVIF_STILL = 'AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAADrbWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAA'
        . 'AAAAAAAOcGl0bQAAAAAAAQAAAB5pbG9jAAAAAEQAAAEAAQAAAAEAAAETAAAAJwAAAChpaW5mAAAAAAABAAAAGmluZmUCAAAAAAEA'
        . 'AGF2MDFDb2xvcgAAAABqaXBycAAAAEtpcGNvAAAAFGlzcGUAAAAAAAAACAAAAAgAAAAQcGl4aQAAAAADCAgIAAAADGF2MUOBAAwA'
        . 'AAAAE2NvbHJuY2x4AAEADQAGgAAAABdpcG1hAAAAAAAAAAEAAQQBAoMEAAAAL21kYXQSAAoIGAi/aICGg0IyGRlHh4Yhh5555oJA'
        . 'AJBBEdVElWLLXdBZ1jk=';

    /**
     * An 8 x 8 AVIF image sequence of 3 frames, with a still primary item.
     */
    private const AVIF_SEQUENCE = 'AAAALGZ0eXBhdmlzAAAAAGF2aWZhdmlzbXNmMWlzbzhtaWYxbWlhZk1BMUIAAADrbWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAcGlj'
        . 'dAAAAAAAAAAAAAAAAAAAAAAOcGl0bQAAAAAAAQAAAB5pbG9jAAAAAEQAAAEAAQAAAAEAAAPpAAAAJgAAAChpaW5mAAAAAAABAAAA'
        . 'GmluZmUCAAAAAAEAAGF2MDFDb2xvcgAAAABqaXBycAAAAEtpcGNvAAAAFGlzcGUAAAAAAAAACAAAAAgAAAAQcGl4aQAAAAADCAgI'
        . 'AAAADGF2MUOBAAwAAAAAE2NvbHJuY2x4AAEADQAGgAAAABdpcG1hAAAAAAAAAAEAAQQBAoMEAAACym1vb3YAAAB4bXZoZAEAAAAA'
        . 'AAAA5wpkAgAAAADnCmQCAAAD6P//////////AAEAAAEAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAA'
        . 'QAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAJKdHJhawAAAGh0a2hkAQAAAQAAAADnCmQCAAAAAOcKZAIAAAABAAAA'
        . 'AP//////////AAAAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAQAAAAAAIAAAACAAAAAAALGVk'
        . 'dHMAAAAkZWxzdAEAAAEAAAABAAAAAAAAASwAAAAAAAAAAAABAAAAAAGubWRpYQAAACxtZGhkAQAAAAAAAADnCmQCAAAAAOcKZAIA'
        . 'AAPoAAAAAAAAASxVxAAAAAAAIWhkbHIAAAAAAAAAAHBpY3QAAAAAAAAAAAAAAAAAAAABWW1pbmYAAAAUdm1oZAAAAAEAAAAAAAAA'
        . 'AAAAACRkaW5mAAAAHGRyZWYAAAAAAAAAAQAAAAx1cmwgAAAAAQAAARlzdGJsAAAAlXN0c2QAAAAAAAAAAQAAAIVhdjAxAAAAAAAA'
        . 'AAEAAAAAAAAAAAAAAAAAAAAAAAgACABIAAAASAAAAAAAAAABCkFPTSBDb2RpbmcAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGP//AAAA'
        . 'DGF2MUOBAAwAAAAAE2NvbHJuY2x4AAEADQAGgAAAABBjY3N0AAAAAHwAAAAAAAAYc3R0cwAAAAAAAAABAAAAAwAAAGQAAAAcc3Rz'
        . 'YwAAAAAAAAABAAAAAQAAAAMAAAABAAAAIHN0c3oAAAAAAAAAAAAAAAMAAAAmAAAAIAAAAB8AAAAUc3RjbwAAAAAAAAABAAAD6QAA'
        . 'ABRzdHNzAAAAAAAAAAEAAAABAAAAbW1kYXQSAAoMAAAAARfm18iAhoNCMhQQALgAQQQQJBCIAEcPUgDbOzAggBIAMhwwA4CA/fh6'
        . '4AEkkkiQQCgAAn1sZEtDNKNn3ImwEgAyGzAEAQV9+HrgASSSSJBAKAAHyeitlL/ie0y+rQ==';

    /**
     * The 8 x 8 still sample with a second iprp box before the first, whose ispe property says 16000 x 16000.
     */
    private const AVIF_TWO_IPRP = 'AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAAFVbWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAA'
        . 'AAAAAAAOcGl0bQAAAAAAAQAAAB5pbG9jAAAAAEQAAAEAAQAAAAEAAAF9AAAAJwAAAChpaW5mAAAAAAABAAAAGmluZmUCAAAAAAEA'
        . 'AGF2MDFDb2xvcgAAAABqaXBycAAAAEtpcGNvAAAAFGlzcGUAAAAAAAA+gAAAPoAAAAAQcGl4aQAAAAADCAgIAAAADGF2MUOBAAwA'
        . 'AAAAE2NvbHJuY2x4AAEADQAGgAAAABdpcG1hAAAAAAAAAAEAAQQBAoMEAAAAamlwcnAAAABLaXBjbwAAABRpc3BlAAAAAAAAAAgA'
        . 'AAAIAAAAEHBpeGkAAAAAAwgICAAAAAxhdjFDgQAMAAAAABNjb2xybmNseAABAA0ABoAAAAAXaXBtYQAAAAAAAAABAAEEAQKDBAAA'
        . 'AC9tZGF0EgAKCBgIv2iAhoNCMhkZR4eGIYeeeeaCQACQQRHVRJViy13QWdY5';

    /**
     * The 8 x 8 still sample with a second item of 16000 x 16000 and two pitm boxes, naming the second item first.
     */
    private const AVIF_TWO_PITM = 'AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAAE8bWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAA'
        . 'AAAAAAAOcGl0bQAAAAAAAgAAAA5waXRtAAAAAAABAAAALGlsb2MAAAAARAAAAgABAAAAAQAAAWQAAAAnAAIAAAABAAABZAAAACcA'
        . 'AABCaWluZgAAAAAAAgAAABppbmZlAgAAAAABAABhdjAxQ29sb3IAAAAAGmluZmUCAAAAAAIAAGF2MDFDb2xvcgAAAACFaXBycAAA'
        . 'AF9pcGNvAAAAFGlzcGUAAAAAAAAACAAAAAgAAAAQcGl4aQAAAAADCAgIAAAADGF2MUOBAAwAAAAAE2NvbHJuY2x4AAEADQAGgAAA'
        . 'ABRpc3BlAAAAAAAAPoAAAD6AAAAAHmlwbWEAAAAAAAAAAgABBAECgwQAAgQFAoMEAAAAL21kYXQSAAoIGAi/aICGg0IyGRlHh4Yh'
        . 'h5555oJAAJBBEdVElWLLXdBZ1jk=';

    /**
     * The 8 x 8 still sample whose item has two ispe properties: 16000 x 16000 first, then 8 x 8.
     */
    private const AVIF_TWO_ISPE = 'AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAAEAbWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAA'
        . 'AAAAAAAOcGl0bQAAAAAAAQAAAB5pbG9jAAAAAEQAAAEAAQAAAAEAAAEoAAAAJwAAAChpaW5mAAAAAAABAAAAGmluZmUCAAAAAAEA'
        . 'AGF2MDFDb2xvcgAAAAB/aXBycAAAAF9pcGNvAAAAFGlzcGUAAAAAAAA+gAAAPoAAAAAQcGl4aQAAAAADCAgIAAAADGF2MUOBAAwA'
        . 'AAAAE2NvbHJuY2x4AAEADQAGgAAAABRpc3BlAAAAAAAAAAgAAAAIAAAAGGlwbWEAAAAAAAAAAQABBQECgwQFAAAAL21kYXQSAAoI'
        . 'GAi/aICGg0IyGRlHh4Yhh5555oJAAJBBEdVElWLLXdBZ1jk=';

    /**
     * A still AVIF whose ispe property says 8 x 8 while its AV1 sequence header codes frames of 4000 x 4000.
     */
    private const AVIF_ISPE_BELOW_AV1 = 'AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAADrbWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAA'
        . 'AAAAAAAOcGl0bQAAAAAAAQAAAB5pbG9jAAAAAEQAAAEAAQAAAAEAAAETAAACMwAAAChpaW5mAAAAAAABAAAAGmluZmUCAAAAAAEA'
        . 'AGF2MDFDb2xvcgAAAABqaXBycAAAAEtpcGNvAAAAFGlzcGUAAAAAAAAACAAAAAgAAAAQcGl4aQAAAAADCAgIAAAADGF2MUOBEAwA'
        . 'AAAAE2NvbHJuY2x4AAEADQAGgAAAABdpcG1hAAAAAAAAAAEAAQQBAoMEAAACO21kYXQSAAoLHC7+f+faICGg0IAyoQQdExHh4Xd0'
        . '000wsbAAAD/PyinCc3rwK8ta/6Xy5sYDHllymRPvRVFxpkl8jGT2IzIADTWZJ2jJT82HMrK0t5/5MyR1VFYz4Z5PLGgkc7m6Qc/K'
        . 'RFsJ0SfEflONiRIWFQQFOLb8xU/C8C1kdfwfvsIwa+VZ43u59CcnbGRzjESaTx7bTFzsoRJxety6VZF5xJ+hwkHPykRbCdEnxH5T'
        . 'jYkSFhUEBTi2/MVPwvAtZHX8H77CMGvlWeN7ufQnJ2xkc4xEmk8e20xc7KEScXrculWRecSfocJAz8pEWwnRJ8R+U42JEhYVBAU4'
        . 'tvzFT8LwLWR1/B++wjBsAN7LUfxTMJjUMIer26fnIF61ziNm1TajmrLtcAViqDpBz8pEWwnRJ8R+U42JEhYVBAU4tvzFT8LwLWR1'
        . '/B++wjBr5Vnje7n0JydsZHOMRJpPHttMXOyhEnF63LpVkXnEn6HAQc/KRFsJ0SfEflONiRIWFQQFOLb8xU/C8C1kdfwfvsIwa+VZ'
        . '43u59CcnbGRzjESaTx7bTFzsoRJxety6VZF5xJ+hwEHPykRbCdEnxH5TjYkSFhUEBTi2/MVPwvAtZHX8H77CMGvlWeN7ufQnJ2xk'
        . 'c4xEmk8e20xc7KEScXrculWRecSfocDPykRbCdEnxH5TjYkSFhUEBTi2/MVPwvAtZHX8H77CMGwA3stR/FMwmNQwh6vbp+cgXrXO'
        . 'I2bVNqOasu1wBWKoIA==';

    /**
     * Channels per PNG colour type.
     *
     * @var array<int, int>
     */
    private const PNG_CHANNELS = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];

    // Generic helpers -----------------------------------------------------------------------------------------------

    /**
     * Overwrites the bytes at $offset with $replacement.
     */
    public static function patch(string $bytes, int $offset, string $replacement): string
    {
        if ($offset < 0 || $offset + strlen($replacement) > strlen($bytes)) {
            throw new \LogicException('ImageBuilder: the patch does not fit in the image.');
        }

        return substr($bytes, 0, $offset) . $replacement . substr($bytes, $offset + strlen($replacement));
    }

    /**
     * Offset of the first occurrence of $needle at or after $from.
     */
    public static function find(string $bytes, string $needle, int $from = 0): int
    {
        $offset = strpos($bytes, $needle, $from);
        if ($offset === false) {
            throw new \LogicException('ImageBuilder: a structure to patch is missing.');
        }

        return $offset;
    }

    /**
     * The 32-bit value at $offset.
     */
    public static function readUint32(string $bytes, int $offset, bool $bigEndian = true): int
    {
        $value = $offset >= 0 && $offset + 4 <= strlen($bytes) ? unpack($bigEndian ? 'N' : 'V', $bytes, $offset) : false;
        if (!is_array($value) || !is_int($value[1])) {
            throw new \LogicException('ImageBuilder: the value to read is missing.');
        }

        return $value[1];
    }

    /**
     * Adds $delta to the big-endian 32-bit value at $offset, such as the size of a box that grows.
     */
    public static function addToUint32(string $bytes, int $offset, int $delta): string
    {
        return self::patch($bytes, $offset, pack('N', self::readUint32($bytes, $offset) + $delta));
    }

    /**
     * $count copies of $bytes.
     */
    public static function repeat(string $bytes, int $count): string
    {
        return $count > 0 ? str_repeat($bytes, $count) : '';
    }

    /**
     * A 132-byte ICC profile: a display RGB profile header with the D50 illuminant and no tag. Enough to be recognised
     * as a profile; the tests never apply it.
     */
    public static function iccProfile(): string
    {
        return pack('N', 132) . "\0\0\0\0" . "\x02\x10\0\0" . 'mntrRGB XYZ ' . self::repeat("\0", 12) . 'acsp'
            . self::repeat("\0", 68 - 40) . pack('NNN', 0xF6D6, 0x10000, 0xD32D) . self::repeat("\0", 128 - 80) . pack('N', 0);
    }

    // PNG -----------------------------------------------------------------------------------------------------------

    public static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    public static function pngHeader(int $width, int $height, int $bitDepth = 8, int $colourType = 2): string
    {
        return self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, $bitDepth, $colourType, 0, 0, 0));
    }

    /**
     * A black PNG. $ancillary goes before PLTE and IDAT, $afterData between IDAT and IEND.
     */
    public static function png(int $width, int $height, int $bitDepth = 8, int $colourType = 2, string $ancillary = '', string $afterData = ''): string
    {
        $palette = $colourType === 3 ? self::pngChunk('PLTE', "\0\0\0\xFF\xFF\xFF") : '';

        return self::PNG_SIGNATURE . self::pngHeader($width, $height, $bitDepth, $colourType) . $ancillary . $palette
            . self::pngChunk('IDAT', self::zlibStored(self::pngRows($width, $height, $bitDepth, $colourType)))
            . $afterData . self::pngChunk('IEND', '');
    }

    /**
     * An animated PNG of $frames full-canvas frames.
     */
    public static function apng(int $width, int $height, int $frames): string
    {
        $data = self::zlibStored(self::pngRows($width, $height, 8, 2));
        $chunks = self::pngChunk('acTL', pack('NN', $frames, 0));
        $sequence = 0;
        for ($frame = 0; $frame < $frames; ++$frame) {
            $chunks .= self::pngChunk('fcTL', pack('NNNNNnnCC', $sequence++, $width, $height, 0, 0, 1, 10, 0, 0));
            $chunks .= $frame === 0 ? self::pngChunk('IDAT', $data) : self::pngChunk('fdAT', pack('N', $sequence++) . $data);
        }

        return self::PNG_SIGNATURE . self::pngHeader($width, $height) . $chunks . self::pngChunk('IEND', '');
    }

    /**
     * A valid 1-bit greyscale PNG of 20000 x 20000 black pixels (400 megapixels, 50,020,000 bytes once inflated)
     * whose IDAT is a single dynamic-Huffman Deflate block of under 50 KB: a literal zero, then matches of 258 bytes
     * at distance 1, each coded on two bits.
     */
    public static function pngDecompressionBomb(): string
    {
        $size = 20000;
        $rawLength = $size * (1 + intdiv($size, 8));
        $matches = intdiv($rawLength - 1, 258);
        $literals = $rawLength - 1 - 258 * $matches;

        $writer = new BitWriter();
        $writer->write(1, 1); // final block
        $writer->write(2, 2); // dynamic Huffman codes
        $writer->write(286 - 257, 5);
        $writer->write(2 - 1, 5);
        $writer->write(18 - 4, 4);
        // Code length code lengths, in the order of RFC 1951: 18 on one bit, 1 and 2 on two bits.
        foreach ([0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 0, 2] as $length) {
            $writer->write($length, 3);
        }
        // Literal/length code lengths: 0 -> 2 bits, 256 (end of block) -> 2 bits, 285 (length 258) -> 1 bit;
        // distance code lengths: 0 (distance 1) and 1 -> 1 bit. Codes: 18 = "0", 1 = "10", 2 = "11".
        $writer->writeCode(3, 2);
        $writer->writeCode(0, 1);
        $writer->write(138 - 11, 7);
        $writer->writeCode(0, 1);
        $writer->write(117 - 11, 7);
        $writer->writeCode(3, 2);
        $writer->writeCode(0, 1);
        $writer->write(28 - 11, 7);
        $writer->writeCode(2, 2);
        $writer->writeCode(2, 2);
        $writer->writeCode(2, 2);
        // Data: literal 0 = "10", length 258 = "0" with distance 1 = "0", end of block = "11".
        $writer->writeCode(2, 2);
        $writer->writeZeros(2 * $matches);
        for ($literal = 0; $literal < $literals; ++$literal) {
            $writer->writeCode(2, 2);
        }
        $writer->writeCode(3, 2);
        // Adler-32 of zeros: the low sum stays 1, the high sum is the length.
        $adler = pack('N', (($rawLength % 65521) << 16) | 1);

        return self::PNG_SIGNATURE . self::pngHeader($size, $size, 1, 0)
            . self::pngChunk('IDAT', "\x78\x01" . $writer->getBytes() . $adler) . self::pngChunk('IEND', '');
    }

    /**
     * A zlib stream of stored (uncompressed) Deflate blocks.
     */
    public static function zlibStored(string $data): string
    {
        $length = strlen($data);
        $blocks = '';
        for ($offset = 0; $offset === 0 || $offset < $length; $offset += 65535) {
            $block = (string) substr($data, $offset, 65535);
            $final = $offset + 65535 >= $length ? 1 : 0;
            $blocks .= pack('Cvv', $final, strlen($block), 0xFFFF ^ strlen($block)) . $block;
        }

        return "\x78\x01" . $blocks . hash('adler32', $data, true);
    }

    private static function pngRows(int $width, int $height, int $bitDepth, int $colourType): string
    {
        $rowLength = intdiv($width * self::PNG_CHANNELS[$colourType] * $bitDepth + 7, 8);

        return self::repeat("\0" . self::repeat("\0", $rowLength), $height);
    }

    // JPEG ----------------------------------------------------------------------------------------------------------

    public static function jpegSegment(int $marker, string $payload): string
    {
        return "\xFF" . pack('C', $marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    /**
     * A mid-grey baseline (or $sofMarker) JPEG with JFIF header; $segments go after the JFIF segment.
     *
     * Each component is sampled 1 x 1 and coded with one-symbol Huffman tables: every block is a zero DC difference
     * and an end of block, two zero bits.
     */
    public static function jpeg(int $width, int $height, int $components = 1, string $segments = '', int $sofMarker = 0xC0, int $precision = 8): string
    {
        $frame = pack('CnnC', $precision, $height, $width, $components);
        $scan = pack('C', $components);
        for ($id = 1; $id <= $components; ++$id) {
            $frame .= pack('CCC', $id, 0x11, 0);
            $scan .= pack('CC', $id, 0x00);
        }
        $blocks = intdiv($width + 7, 8) * intdiv($height + 7, 8) * $components;

        return "\xFF\xD8" . self::jfif() . $segments . self::jpegSegment(0xDB, "\x00" . self::repeat("\x01", 64))
            . self::jpegSegment($sofMarker, $frame)
            . self::jpegSegment(0xC4, "\x00" . self::oneSymbolHuffmanTable())
            . self::jpegSegment(0xC4, "\x10" . self::oneSymbolHuffmanTable())
            . self::jpegSegment(0xDA, $scan . "\x00\x3F\x00") . self::jpegZeroBits(2 * $blocks) . "\xFF\xD9";
    }

    /**
     * A mid-grey progressive greyscale JPEG: a DC scan and $acScans AC scans, each one bit per block. Decoders warn
     * about the AC scans after the first one, which code the same coefficients again, and decode them all.
     */
    public static function progressiveJpeg(int $width, int $height, int $acScans = 1): string
    {
        $blocks = intdiv($width + 7, 8) * intdiv($height + 7, 8);

        return "\xFF\xD8" . self::jfif() . self::jpegSegment(0xDB, "\x00" . self::repeat("\x01", 64))
            . self::jpegSegment(0xC2, pack('CnnCCCC', 8, $height, $width, 1, 1, 0x11, 0))
            . self::jpegSegment(0xC4, "\x00" . self::oneSymbolHuffmanTable())
            . self::jpegSegment(0xDA, "\x01\x01\x00\x00\x00\x00") . self::jpegZeroBits($blocks)
            . self::jpegSegment(0xC4, "\x10" . self::oneSymbolHuffmanTable())
            . self::repeat(self::jpegSegment(0xDA, "\x01\x01\x00\x01\x3F\x00") . self::jpegZeroBits($blocks), $acScans) . "\xFF\xD9";
    }

    /**
     * An APP1 EXIF segment holding a TIFF header and an empty IFD0, padded with zeros to $payloadLength bytes.
     */
    public static function jpegExif(int $payloadLength = 20): string
    {
        $payload = "Exif\0\0II*\0" . pack('V', 8) . "\0\0" . pack('V', 0);

        return self::jpegSegment(0xE1, $payload . self::repeat("\0", $payloadLength - strlen($payload)));
    }

    public static function jpegIcc(): string
    {
        return self::jpegSegment(0xE2, "ICC_PROFILE\0\x01\x01" . self::iccProfile());
    }

    private static function jfif(): string
    {
        return self::jpegSegment(0xE0, "JFIF\0\x01\x01\x00\x00\x01\x00\x01\x00\x00");
    }

    /**
     * Huffman table body (after the class and id byte) with one code of one bit for the symbol 0.
     */
    private static function oneSymbolHuffmanTable(): string
    {
        return "\x01" . self::repeat("\0", 15) . "\x00";
    }

    /**
     * $bits zero bits of entropy-coded data, the last byte padded with one bits.
     */
    private static function jpegZeroBits(int $bits): string
    {
        $remainder = $bits % 8;

        return self::repeat("\0", intdiv($bits, 8)) . ($remainder > 0 ? pack('C', 0xFF >> $remainder) : '');
    }

    // GIF -----------------------------------------------------------------------------------------------------------

    /**
     * A GIF of full-canvas black frames, with the NETSCAPE loop extension and a graphic control extension per frame
     * when animated. $extensions go after the global colour table.
     */
    public static function gif(int $width, int $height, int $frames = 1, string $extensions = '', string $version = '89a'): string
    {
        $out = self::gifScreen($width, $height, $version) . ($frames > 1 ? self::gifLoop() : '') . $extensions;
        for ($frame = 0; $frame < $frames; ++$frame) {
            $out .= ($frames > 1 ? "\x21\xF9\x04\x04\x0A\x00\x00\x00" : '') . self::gifImage(0, 0, $width, $height);
        }

        return $out . "\x3B";
    }

    /**
     * An animated GIF whose $frames frames are $frameWidth x $frameHeight at the top left of the canvas, without
     * graphic control extensions (the smallest frames a GIF can hold).
     */
    public static function gifAnimation(int $width, int $height, int $frames, int $frameWidth = 1, int $frameHeight = 1): string
    {
        return self::gifScreen($width, $height, '89a') . self::gifLoop()
            . self::repeat(self::gifImage(0, 0, $frameWidth, $frameHeight), $frames) . "\x3B";
    }

    /**
     * A GIF header and logical screen with a global colour table of two colours, black and white.
     */
    public static function gifScreen(int $width, int $height, string $version = '89a'): string
    {
        return 'GIF' . $version . pack('vvCCC', $width, $height, 0x80, 0, 0) . "\0\0\0\xFF\xFF\xFF";
    }

    /**
     * An image descriptor and its LZW data: a clear code before every pixel keeps the codes three bits wide.
     */
    public static function gifImage(int $left, int $top, int $width, int $height, int $minimumCodeSize = 2): string
    {
        $writer = new BitWriter();
        for ($pixel = $width * $height; $pixel > 0; --$pixel) {
            $writer->write(4, 3);
            $writer->write(0, 3);
        }
        $writer->write(5, 3);

        return "\x2C" . pack('vvvvC', $left, $top, $width, $height, 0) . pack('C', $minimumCodeSize)
            . self::gifSubBlocks($writer->getBytes());
    }

    /**
     * Data sub-blocks of up to 255 bytes and the block terminator.
     */
    public static function gifSubBlocks(string $data): string
    {
        $out = '';
        for ($offset = 0; $offset < strlen($data); $offset += 255) {
            $block = (string) substr($data, $offset, 255);
            $out .= pack('C', strlen($block)) . $block;
        }

        return $out . "\0";
    }

    public static function gifComment(string $text): string
    {
        return "\x21\xFE" . self::gifSubBlocks($text);
    }

    private static function gifLoop(): string
    {
        return "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
    }

    // BMP -----------------------------------------------------------------------------------------------------------

    /**
     * A black BMP with an info (40), V4 (108) or V5 (124) header. Bit counts up to 8 get a palette of two colours, black and red;
     * RLE8 data codes each row as runs; bit fields are 5-6-5 for 16 bits and 8-8-8-8 for 32 bits.
     */
    public static function bmp(int $width, int $height, int $bitCount = 24, int $compression = 0, int $headerSize = 40, bool $topDown = false, string $colourSpace = 'sRGB', string $profile = ''): string
    {
        $colours = $bitCount <= 8 ? 2 : 0;
        $palette = self::repeat("\0\0\0\0\0\0\xFF\0", $colours > 0 ? 1 : 0);
        $masks = $bitCount === 16 ? pack('VVVV', 0xF800, 0x07E0, 0x001F, 0) : pack('VVVV', 0xFF0000, 0xFF00, 0xFF, 0xFF000000);
        $pixels = $compression === 1 ? self::rle8($width, $height)
            : self::repeat("\0", intdiv($width * $bitCount + 31, 32) * 4 * $height);

        $dib = pack('VVVvvVVVVVV', $headerSize, $width, $topDown ? -$height : $height, 1, $bitCount, $compression, strlen($pixels), 2835, 2835, $colours, 0);
        $afterHeader = $palette;
        if ($headerSize === 40 && ($compression === 3 || $compression === 6)) {
            $afterHeader = substr($masks, 0, $compression === 3 ? 12 : 16) . $palette;
        }
        if ($headerSize >= 108) {
            // Masks, colour space type, end points and gamma; V5 adds the intent, the profile location and a reserved
            // field. The profile, if any, follows the pixel data; its offset counts from the start of the header.
            $type = strrev($colourSpace);
            $dib .= $masks . $type . self::repeat("\0", 36 + 12);
            if ($headerSize === 124) {
                $profileOffset = $profile === '' ? 0 : $headerSize + strlen($afterHeader) + strlen($pixels);
                $dib .= pack('VVVV', 4, $profileOffset, strlen($profile), 0);
            }
        }
        $offset = 14 + strlen($dib) + strlen($afterHeader);
        $body = $dib . $afterHeader . $pixels . $profile;

        return 'BM' . pack('VvvV', 14 + strlen($body), 0, 0, $offset) . $body;
    }

    /**
     * A BMP with the OS/2 1.x core header (12 bytes) and 24-bit pixels.
     */
    public static function bmpCore(int $width, int $height): string
    {
        $pixels = self::repeat("\0", intdiv($width * 24 + 31, 32) * 4 * $height);

        return 'BM' . pack('VvvV', 26 + strlen($pixels), 0, 0, 26) . pack('Vvvvv', 12, $width, $height, 1, 24) . $pixels;
    }

    /**
     * RLE8 data: runs of colour 0 of up to 255 pixels, an end of line after each row, an end of bitmap.
     */
    private static function rle8(int $width, int $height): string
    {
        $row = '';
        for ($remaining = $width; $remaining > 0; $remaining -= 255) {
            $row .= chr(min($remaining, 255)) . "\0";
        }

        return self::repeat($row . "\0\0", $height) . "\0\1";
    }

    // WEBP ----------------------------------------------------------------------------------------------------------

    public static function riff(string $chunks): string
    {
        return 'RIFF' . pack('V', 4 + strlen($chunks)) . 'WEBP' . $chunks;
    }

    public static function webpChunk(string $type, string $data): string
    {
        return $type . pack('V', strlen($data)) . $data . (strlen($data) % 2 === 1 ? "\0" : '');
    }

    /**
     * The 16 x 16 lossy (VP8) sample.
     */
    public static function webpLossy(): string
    {
        return self::decode(self::WEBP_LOSSY);
    }

    /**
     * A uniform lossless (VP8L) bitstream: no transform, no colour cache, and five one-symbol prefix codes, so that
     * every pixel takes zero bits.
     */
    public static function vp8l(int $width, int $height, bool $alpha = false): string
    {
        $writer = new BitWriter();
        $writer->write(0x2F, 8);
        $writer->write($width - 1, 14);
        $writer->write($height - 1, 14);
        $writer->write($alpha ? 1 : 0, 1);
        $writer->write(0, 3);
        $writer->write(0, 3); // no transform, no colour cache, no meta prefix codes
        // Green, red, blue and alpha: a simple code of one 8-bit symbol.
        foreach ([0x80, 0x40, 0xC0, $alpha ? 0x80 : 0xFF] as $symbol) {
            $writer->write(1, 1);
            $writer->write(0, 1);
            $writer->write(1, 1);
            $writer->write($symbol, 8);
        }
        // Distance: a simple code of one 1-bit symbol.
        $writer->write(1, 4);

        return $writer->getBytes();
    }

    public static function webpLossless(int $width, int $height, bool $alpha = false): string
    {
        return self::riff(self::webpChunk('VP8L', self::vp8l($width, $height, $alpha)));
    }

    public static function vp8x(int $flags, int $width, int $height): string
    {
        return self::webpChunk('VP8X', pack('C', $flags) . "\0\0\0" . self::uint24($width - 1) . self::uint24($height - 1));
    }

    /**
     * An animated WEBP of $frames lossless frames of $frameWidth x $frameHeight (the canvas when 0) at the top left.
     */
    public static function webpAnimation(int $width, int $height, int $frames, int $frameWidth = 0, int $frameHeight = 0): string
    {
        $frameWidth = $frameWidth > 0 ? $frameWidth : $width;
        $frameHeight = $frameHeight > 0 ? $frameHeight : $height;
        $frame = self::webpChunk('ANMF', self::uint24(0) . self::uint24(0) . self::uint24($frameWidth - 1)
            . self::uint24($frameHeight - 1) . self::uint24(100) . "\0"
            . self::webpChunk('VP8L', self::vp8l($frameWidth, $frameHeight)));

        return self::riff(self::vp8x(0x02, $width, $height) . self::webpChunk('ANIM', "\0\0\0\0\0\0") . self::repeat($frame, $frames));
    }

    public static function uint24(int $value): string
    {
        return (string) substr(pack('V', $value), 0, 3);
    }

    // AVIF ----------------------------------------------------------------------------------------------------------

    /**
     * The 8 x 8 still sample.
     */
    public static function avifStill(): string
    {
        return self::decode(self::AVIF_STILL);
    }

    /**
     * The 8 x 8 image sequence sample of 3 frames.
     */
    public static function avifSequence(): string
    {
        return self::decode(self::AVIF_SEQUENCE);
    }

    /**
     * A still AVIF that holds a box or property twice, or whose ispe property understates its AV1 frame size; see the
     * AVIF_* constants.
     *
     * @param string $name "two-iprp", "two-pitm", "two-ispe" or "ispe-below-av1"
     */
    public static function avifReproducer(string $name): string
    {
        $samples = [
            'two-iprp' => self::AVIF_TWO_IPRP,
            'two-pitm' => self::AVIF_TWO_PITM,
            'two-ispe' => self::AVIF_TWO_ISPE,
            'ispe-below-av1' => self::AVIF_ISPE_BELOW_AV1,
        ];
        if (!isset($samples[$name])) {
            throw new \LogicException('ImageBuilder: unknown AVIF sample.');
        }

        return self::decode($samples[$name]);
    }

    /**
     * An ipco property box of one byte, such as irot (anticlockwise quarter turns) or imir (mirroring mode).
     */
    public static function avifProperty(string $type, int $value): string
    {
        return pack('N', 9) . $type . pack('C', $value);
    }

    /**
     * A still AVIF whose items share the AV1 data of the 8 x 8 still sample. Item 1 is the primary item; every item is
     * located on that data, and every AV1 item is associated with the 8 x 8 ispe (property 1), the av1C (property 2)
     * of the sample and an 8-bit pixi (property 3). Property 4 is an alpha auxC, then come $properties.
     *
     * @param array<int, string>    $items        item ID => the end of its infe box: type, then name and strings
     * @param string                $references   the reference boxes of iref (see avifReference()); none omits iref
     * @param string                $properties   property boxes appended to ipco
     * @param array<int, list<int>> $associations item ID => property indices associated after the others
     */
    public static function avifOfItems(array $items, string $references = '', string $properties = '', array $associations = []): string
    {
        $still = self::avifStill();
        // The still sample has one item, in a single extent whose offset and length follow the 14 bytes of the iloc
        // version and flags, sizes, item count, item ID, data reference and extent count.
        $iloc = self::find($still, 'iloc') + 4;
        $data = (string) substr($still, self::readUint32($still, $iloc + 14), self::readUint32($still, $iloc + 18));
        $codecConfiguration = self::find($still, 'av1C') - 4;
        $codecConfiguration = (string) substr($still, $codecConfiguration, self::readUint32($still, $codecConfiguration));

        $infe = '';
        $ipma = '';
        foreach ($items as $id => $entry) {
            $infe .= self::isoFullBox('infe', 2, pack('nn', $id, 0) . $entry);
            $indices = array_merge(substr($entry, 0, 4) === 'av01' ? [1, 0x82, 3] : [], $associations[$id] ?? []);
            $ipma .= pack('nC', $id, count($indices)) . pack('C*', ...$indices);
        }
        $ipco = self::isoFullBox('ispe', 0, pack('NN', 8, 8)) . $codecConfiguration . self::isoFullBox('pixi', 0, "\x03\x08\x08\x08")
            . self::isoFullBox('auxC', 0, "urn:mpeg:mpegB:cicp:systems:auxiliary:alpha\0") . $properties;
        $ftyp = self::isoBox('ftyp', 'avif' . "\0\0\0\0" . 'avifmif1miaf');
        $head = self::isoFullBox('hdlr', 0, "\0\0\0\0pict" . str_repeat("\0", 13)) . self::isoFullBox('pitm', 0, pack('n', 1));
        $tail = self::isoFullBox('iinf', 0, pack('n', count($items)) . $infe)
            . self::isoBox('iprp', self::isoBox('ipco', $ipco) . self::isoFullBox('ipma', 0, pack('N', count($items)) . $ipma))
            . ($references === '' ? '' : self::isoFullBox('iref', 0, $references));
        // iloc (version 0, 4-byte offsets and lengths) has the same size whatever the offset of the data in mdat.
        $meta = static function (int $offset) use ($items, $data, $head, $tail): string {
            $locations = '';
            foreach (array_keys($items) as $id) {
                $locations .= pack('nnnNN', $id, 0, 1, $offset, strlen($data));
            }

            return self::isoFullBox('meta', 0, $head . self::isoFullBox('iloc', 0, "\x44\x00" . pack('n', count($items)) . $locations) . $tail);
        };

        return $ftyp . $meta(strlen($ftyp) + strlen($meta(0)) + 8) . self::isoBox('mdat', $data);
    }

    /**
     * A single item type reference box of iref version 0: $type from item $from to item $to.
     */
    public static function avifReference(string $type, int $from, int $to): string
    {
        return self::isoBox($type, pack('nnn', $from, 1, $to));
    }

    public static function isoBox(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)) . $type . $payload;
    }

    public static function isoFullBox(string $type, int $version, string $payload): string
    {
        return self::isoBox($type, pack('N', $version << 24) . $payload);
    }

    // TIFF ----------------------------------------------------------------------------------------------------------

    /**
     * An uncompressed black TIFF in one strip: greyscale (1 sample) or RGB (3 samples), 8 bits per sample.
     *
     * @param array<int, array{0: int, 1: string}> $extraTags tag => [type, value bytes in the file byte order]
     */
    public static function tiff(int $width, int $height, bool $bigEndian = false, int $samples = 1, int $compression = 1, array $extraTags = []): string
    {
        $short = $bigEndian ? 'n' : 'v';
        $long = $bigEndian ? 'N' : 'V';
        $pixels = self::repeat("\0", $width * $height * $samples);
        $tags = [
            256 => [4, pack($long, $width)],
            257 => [4, pack($long, $height)],
            258 => [3, self::repeat(pack($short, 8), $samples)],
            259 => [3, pack($short, $compression)],
            262 => [3, pack($short, $samples === 3 ? 2 : 1)],
            273 => [4, pack($long, 0)],
            277 => [3, pack($short, $samples)],
            278 => [4, pack($long, $height)],
            279 => [4, pack($long, strlen($pixels))],
        ] + $extraTags;
        ksort($tags);

        // Values longer than 4 bytes go after the directory, at even offsets; the strip follows them.
        $dataOffset = 8 + 2 + 12 * count($tags) + 4;
        $overflow = '';
        $offsets = [];
        foreach ($tags as $tag => $entry) {
            if (strlen($entry[1]) > 4) {
                $offsets[$tag] = $dataOffset + strlen($overflow);
                $overflow .= $entry[1] . (strlen($entry[1]) % 2 === 1 ? "\0" : '');
            }
        }
        $tags[273] = [4, pack($long, $dataOffset + strlen($overflow))];

        $directory = pack($short, count($tags));
        foreach ($tags as $tag => $entry) {
            $unit = $entry[0] === 3 ? 2 : ($entry[0] === 4 ? 4 : 1);
            $value = isset($offsets[$tag]) ? pack($long, $offsets[$tag]) : str_pad($entry[1], 4, "\0");
            $directory .= pack($short . $short . $long, $tag, $entry[0], intdiv(strlen($entry[1]), $unit)) . $value;
        }

        return ($bigEndian ? "MM\0*" : "II*\0") . pack($long, 8) . $directory . pack($long, 0) . $overflow . $pixels;
    }

    /**
     * A little-endian TIFF whose first directory holds $entries in the given order (a tag may repeat), with $data
     * written at offset $dataOffset.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int|string}> $entries tag, type, count, and the value field: a LONG,
     *                                                                    or at most 4 bytes
     */
    public static function tiffEntries(array $entries, string $data, int $dataOffset): string
    {
        $directory = pack('v', count($entries));
        foreach ($entries as $entry) {
            $value = is_string($entry[3]) ? str_pad($entry[3], 4, "\0") : pack('V', $entry[3]);
            $directory .= pack('vvV', $entry[0], $entry[1], $entry[2]) . $value;
        }
        $head = "II*\0" . pack('V', 8) . $directory . pack('V', 0);

        return $head . self::repeat("\0", $dataOffset - strlen($head)) . $data;
    }

    // EXIF ----------------------------------------------------------------------------------------------------------

    /**
     * The TIFF structure of an EXIF block whose IFD0 holds the Orientation tag alone, as a JPEG APP1 segment (after
     * "Exif\0\0"), a PNG eXIf chunk or a WEBP EXIF chunk carries it.
     */
    public static function exifOrientation(int $orientation, bool $bigEndian = false): string
    {
        $short = $bigEndian ? 'n' : 'v';
        $long = $bigEndian ? 'N' : 'V';

        return ($bigEndian ? "MM\0*" : "II*\0") . pack($long, 8) . pack($short, 1)
            . pack($short . $short . $long . $short . $short, 0x0112, 3, 1, $orientation, 0) . pack($long, 0);
    }

    // SVG -----------------------------------------------------------------------------------------------------------

    public static function svg(string $prolog = ''): string
    {
        return $prolog . '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>' . "\n";
    }

    private static function decode(string $base64): string
    {
        $bytes = base64_decode($base64, true);
        if (!is_string($bytes)) {
            throw new \LogicException('ImageBuilder: an embedded sample is not valid base64.');
        }

        return $bytes;
    }
}
