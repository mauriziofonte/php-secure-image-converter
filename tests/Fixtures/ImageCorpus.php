<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Fixtures;

use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\Parser\AvifParser;
use Mfonte\SecureImageConverter\Validation\Parser\GifParser;
use Mfonte\SecureImageConverter\Validation\Parser\PngParser;
use Mfonte\SecureImageConverter\Validation\Parser\WebpParser;

/**
 * The input validation corpus: every case with the bytes ImageBuilder computes for it and what ImageInspector must
 * report with every format enabled and the default limits.
 *
 * Cases are keyed by their path under tests/Fixtures/corpus/, where generate-corpus.php writes them; the name says
 * what the file is: valid-* (accepted), bomb-* (a dimension, decompression or frame bomb), spoof-* (malformed,
 * inconsistent or forged header), truncated-*, unsupported-* (an identified variant this library does not process),
 * polyglot-* (another file type dressed as an image, or the reverse). Cases larger than 100 KB are not committed and
 * the tests generate them in memory: a GIF of 10,000 frames, and the files that reach a walk cap of a parser (the
 * most chunks, blocks or boxes allowed, then one too many), which bound the time a hostile file costs.
 *
 * An accepted case expects a subset of ImageInfo::toArray(); a refused case expects an error code. "max_bytes_read"
 * bounds the bytes the inspector may read from the file: bombs are refused from their header, whatever their size.
 */
final class ImageCorpus
{
    /**
     * Bytes a refusal from the header may read: two buffers of ByteReader.
     */
    public const HEADER_BYTES_READ = 16384;

    /**
     * Builds every case; this takes a few tens of milliseconds, so callers build them once.
     *
     * @return array<string, array{bytes: string, committed: bool, expect: array<string, bool|int|string>}>
     */
    public static function cases(): array
    {
        return array_merge(
            self::jpegCases(),
            self::pngCases(),
            self::gifCases(),
            self::bmpCases(),
            self::webpCases(),
            self::avifCases(),
            self::tiffCases(),
            self::otherCases()
        );
    }

    /**
     * Directory of the committed corpus.
     */
    public static function directory(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'corpus';
    }

    /**
     * @return array<string, array{bytes: string, committed: bool, expect: array<string, bool|int|string>}>
     */
    private static function jpegCases(): array
    {
        $valid = ImageBuilder::jpeg(33, 17, 3);
        $sof = ImageBuilder::find($valid, "\xFF\xC0");
        $frameHeader = (string) substr($valid, $sof, 2 + 2 + 6 + 3 * 3);
        $grey = ImageBuilder::jpeg(8, 8);
        $greySof = ImageBuilder::find($grey, "\xFF\xC0");

        return [
            'jpeg/valid-grey.jpg' => self::committed(ImageBuilder::jpeg(16, 8), self::accept(ImageFormat::JPEG, 16, 8, 1, ['bit_depth' => 8, 'icc_profile' => false, 'exif' => false, 'metadata' => false, 'orientation' => 1, 'trailing_data' => false])),
            'jpeg/valid-colour.jpg' => self::committed($valid, self::accept(ImageFormat::JPEG, 33, 17)),
            'jpeg/valid-progressive.jpg' => self::committed(ImageBuilder::progressiveJpeg(20, 12), self::accept(ImageFormat::JPEG, 20, 12)),
            'jpeg/valid-exif-orientation.jpg' => self::committed(
                ImageBuilder::jpeg(16, 8, 1, ImageBuilder::jpegSegment(0xE1, "Exif\0\0" . ImageBuilder::exifOrientation(6))),
                self::accept(ImageFormat::JPEG, 16, 8, 1, ['exif' => true, 'orientation' => 6])
            ),
            'jpeg/valid-exif-orientation-big-endian.jpg' => self::committed(
                ImageBuilder::jpeg(16, 8, 1, ImageBuilder::jpegSegment(0xE1, "Exif\0\0" . ImageBuilder::exifOrientation(8, true))),
                self::accept(ImageFormat::JPEG, 16, 8, 1, ['exif' => true, 'orientation' => 8])
            ),
            // An orientation outside 1 to 8 is ignored, as viewers ignore it.
            'jpeg/valid-exif-orientation-9.jpg' => self::committed(
                ImageBuilder::jpeg(16, 8, 1, ImageBuilder::jpegSegment(0xE1, "Exif\0\0" . ImageBuilder::exifOrientation(9))),
                self::accept(ImageFormat::JPEG, 16, 8, 1, ['exif' => true, 'orientation' => 1])
            ),
            'jpeg/valid-exif-icc.jpg' => self::committed(
                ImageBuilder::jpeg(8, 8, 3, ImageBuilder::jpegExif() . ImageBuilder::jpegIcc()),
                self::accept(ImageFormat::JPEG, 8, 8, 1, ['icc_profile' => true, 'exif' => true, 'metadata' => true])
            ),
            // The largest EXIF segment a JPEG can hold is skipped, not read.
            'jpeg/valid-exif-64k.jpg' => self::committed(
                ImageBuilder::jpeg(8, 8, 1, ImageBuilder::jpegExif(65533)),
                self::accept(ImageFormat::JPEG, 8, 8, 1, ['exif' => true, 'max_bytes_read' => self::HEADER_BYTES_READ])
            ),
            // A payload in a comment is metadata, which conversion strips.
            'jpeg/valid-php-comment.jpg' => self::committed(
                ImageBuilder::jpeg(8, 8, 1, ImageBuilder::jpegSegment(0xFE, '<?php echo "payload"; ?>')),
                self::accept(ImageFormat::JPEG, 8, 8, 1, ['metadata' => true])
            ),
            // Data after the end of the image (a motion photo video, a ZIP archive) is ignored by decoders and dropped
            // by conversion.
            'jpeg/valid-trailing-zip.jpg' => self::committed(
                $grey . "PK\x03\x04" . ImageBuilder::repeat("\0", 26) . 'payload.txt',
                self::accept(ImageFormat::JPEG, 8, 8, 1, ['trailing_data' => true])
            ),
            'jpeg/bomb-65535x65535.jpg' => self::committed(ImageBuilder::patch($grey, $greySof + 5, pack('nn', 65535, 65535)), self::reject(ErrorCode::IMAGE_TOO_LARGE, true)),
            'jpeg/bomb-exif-flood.jpg' => self::committed(
                ImageBuilder::jpeg(8, 8, 1, ImageBuilder::repeat(ImageBuilder::jpegSegment(0xE1, "Exif\0\0"), 1100)),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'jpeg/spoof-exif-beyond-end.jpg' => self::committed("\xFF\xD8\xFF\xE1\xFF\xF0Exif\0\0II*\0", self::reject(ErrorCode::INVALID_IMAGE)),
            'jpeg/spoof-no-frame-header.jpg' => self::committed(str_replace($frameHeader, '', $valid), self::reject(ErrorCode::INVALID_IMAGE)),
            'jpeg/spoof-two-frame-headers.jpg' => self::committed(str_replace($frameHeader, $frameHeader . $frameHeader, $valid), self::reject(ErrorCode::INVALID_IMAGE)),
            'jpeg/spoof-zero-height.jpg' => self::committed(ImageBuilder::patch($grey, $greySof + 5, pack('n', 0)), self::reject(ErrorCode::INVALID_IMAGE)),
            'jpeg/spoof-precision-7.jpg' => self::committed(ImageBuilder::patch($grey, $greySof + 4, "\x07"), self::reject(ErrorCode::INVALID_IMAGE)),
            // libjpeg decodes neither lossless nor hierarchical JPEG, nor 12 or 16 bits per sample as built by GD.
            'jpeg/unsupported-12-bit.jpg' => self::committed(ImageBuilder::jpeg(8, 8, 1, '', 0xC1, 12), self::reject(ErrorCode::UNSUPPORTED_FORMAT, true)),
            'jpeg/unsupported-lossless.jpg' => self::committed(ImageBuilder::jpeg(8, 8, 1, '', 0xC3), self::reject(ErrorCode::UNSUPPORTED_FORMAT, true)),
            'jpeg/unsupported-hierarchical.jpg' => self::committed(
                ImageBuilder::jpeg(8, 8, 1, ImageBuilder::jpegSegment(0xDE, pack('CnnC', 8, 8, 8, 1) . "\x01\x11\x00"), 0xC5),
                self::reject(ErrorCode::UNSUPPORTED_FORMAT, true)
            ),
            'jpeg/truncated-scan.jpg' => self::committed((string) substr($valid, 0, -4), self::reject(ErrorCode::INVALID_IMAGE)),
            'jpeg/truncated-header.jpg' => self::committed((string) substr($valid, 0, $sof + 6), self::reject(ErrorCode::INVALID_IMAGE)),
        ];
    }

    /**
     * @return array<string, array{bytes: string, committed: bool, expect: array<string, bool|int|string>}>
     */
    private static function pngCases(): array
    {
        $valid = ImageBuilder::png(16, 8);
        $idat = ImageBuilder::pngChunk('IDAT', ImageBuilder::zlibStored("\0\0\0\0"));
        $iend = ImageBuilder::pngChunk('IEND', '');
        $tiny = ImageBuilder::PNG_SIGNATURE . ImageBuilder::pngHeader(1, 1) . $idat . $iend;
        $exif = "II*\0" . pack('V', 8) . "\0\0" . pack('V', 0);
        $frameControl = ImageBuilder::pngChunk('fcTL', pack('NNNNNnnCC', 0, 1, 1, 0, 0, 1, 10, 0, 0));

        return [
            'png/valid-rgb.png' => self::committed($valid, self::accept(ImageFormat::PNG, 16, 8, 1, ['bit_depth' => 8, 'icc_profile' => false, 'exif' => false, 'metadata' => false, 'orientation' => 1, 'trailing_data' => false])),
            'png/valid-grey-1-bit.png' => self::committed(ImageBuilder::png(33, 17, 1, 0), self::accept(ImageFormat::PNG, 33, 17, 1, ['bit_depth' => 8])),
            'png/valid-palette.png' => self::committed(ImageBuilder::png(10, 10, 8, 3), self::accept(ImageFormat::PNG, 10, 10)),
            'png/valid-rgba-16-bit.png' => self::committed(ImageBuilder::png(4, 4, 16, 6), self::accept(ImageFormat::PNG, 4, 4, 1, ['bit_depth' => 16])),
            'png/valid-metadata.png' => self::committed(
                ImageBuilder::png(8, 8, 8, 2, ImageBuilder::pngChunk('iCCP', "corpus\0\0" . ImageBuilder::zlibStored(ImageBuilder::iccProfile()))
                    . ImageBuilder::pngChunk('eXIf', $exif) . ImageBuilder::pngChunk('tEXt', "Comment\0corpus")),
                self::accept(ImageFormat::PNG, 8, 8, 1, ['icc_profile' => true, 'exif' => true, 'metadata' => true])
            ),
            'png/valid-animated.png' => self::committed(ImageBuilder::apng(12, 6, 4), self::accept(ImageFormat::PNG, 12, 6, 4)),
            'png/valid-trailing-zip.png' => self::committed(
                $valid . "PK\x05\x06" . ImageBuilder::repeat("\0", 18),
                self::accept(ImageFormat::PNG, 16, 8, 1, ['trailing_data' => true])
            ),
            'png/valid-exif-orientation.png' => self::committed(
                ImageBuilder::png(8, 4, 8, 2, ImageBuilder::pngChunk('eXIf', ImageBuilder::exifOrientation(3, true))),
                self::accept(ImageFormat::PNG, 8, 4, 1, ['exif' => true, 'orientation' => 3])
            ),
            // The most chunks a file may have, then one too many: the walk stays far below 50 ms.
            'png/valid-most-chunks.png' => self::generated(
                ImageBuilder::png(8, 8, 8, 2, ImageBuilder::repeat(ImageBuilder::pngChunk('tEXt', "k\0"), PngParser::MAX_CHUNKS - 3)),
                self::accept(ImageFormat::PNG, 8, 8, 1, ['metadata' => true])
            ),
            'png/spoof-too-many-chunks.png' => self::generated(
                ImageBuilder::png(8, 8, 8, 2, ImageBuilder::repeat(ImageBuilder::pngChunk('tEXt', "k\0"), PngParser::MAX_CHUNKS - 2)),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'png/bomb-100000x100000.png' => self::committed(
                ImageBuilder::PNG_SIGNATURE . ImageBuilder::pngHeader(100000, 100000) . $idat . $iend,
                self::reject(ErrorCode::IMAGE_TOO_LARGE, true)
            ),
            'png/bomb-decompression-20000x20000.png' => self::committed(ImageBuilder::pngDecompressionBomb(), self::reject(ErrorCode::IMAGE_TOO_LARGE, true)),
            'png/bomb-100000-frames.png' => self::committed(
                ImageBuilder::PNG_SIGNATURE . ImageBuilder::pngHeader(1, 1) . ImageBuilder::pngChunk('acTL', pack('NN', 100000, 0)) . $frameControl . $idat . $iend,
                self::reject(ErrorCode::IMAGE_TOO_LARGE, true)
            ),
            'png/spoof-header-crc.png' => self::committed(ImageBuilder::patch($valid, 29, "\0\0\0\0"), self::reject(ErrorCode::INVALID_IMAGE)),
            'png/spoof-header-not-first.png' => self::committed(
                ImageBuilder::PNG_SIGNATURE . ImageBuilder::pngChunk('tEXt', "Comment\0first") . (string) substr($tiny, 8),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'png/spoof-zero-width.png' => self::committed(ImageBuilder::PNG_SIGNATURE . ImageBuilder::pngHeader(0, 8) . $idat . $iend, self::reject(ErrorCode::INVALID_IMAGE)),
            'png/spoof-bit-depth.png' => self::committed(ImageBuilder::PNG_SIGNATURE . ImageBuilder::pngHeader(8, 8, 4, 2) . $idat . $iend, self::reject(ErrorCode::INVALID_IMAGE)),
            'png/spoof-unknown-critical-chunk.png' => self::committed(ImageBuilder::png(8, 8, 8, 2, ImageBuilder::pngChunk('CRIT', 'x')), self::reject(ErrorCode::INVALID_IMAGE)),
            'png/spoof-exif-beyond-end.png' => self::committed(
                ImageBuilder::PNG_SIGNATURE . ImageBuilder::pngHeader(1, 1) . pack('N', 0x7FFFFFF0) . 'eXIf' . $exif,
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'png/spoof-frame-count.png' => self::committed(
                ImageBuilder::PNG_SIGNATURE . ImageBuilder::pngHeader(1, 1) . ImageBuilder::pngChunk('acTL', pack('NN', 3, 0)) . $frameControl . $idat . $iend,
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'png/truncated.png' => self::committed((string) substr($valid, 0, -20), self::reject(ErrorCode::INVALID_IMAGE)),
        ];
    }

    /**
     * @return array<string, array{bytes: string, committed: bool, expect: array<string, bool|int|string>}>
     */
    private static function gifCases(): array
    {
        $valid = ImageBuilder::gif(16, 8);

        return [
            'gif/valid-still.gif' => self::committed($valid, self::accept(ImageFormat::GIF, 16, 8, 1, ['bit_depth' => 8, 'icc_profile' => false, 'exif' => false, 'metadata' => false, 'orientation' => 1, 'trailing_data' => false])),
            'gif/valid-gif87a.gif' => self::committed(ImageBuilder::gif(5, 3, 1, '', '87a'), self::accept(ImageFormat::GIF, 5, 3)),
            'gif/valid-animated.gif' => self::committed(ImageBuilder::gif(12, 6, 4), self::accept(ImageFormat::GIF, 12, 6, 4, ['metadata' => false])),
            'gif/valid-comment.gif' => self::committed(ImageBuilder::gif(8, 8, 1, ImageBuilder::gifComment('corpus')), self::accept(ImageFormat::GIF, 8, 8, 1, ['metadata' => true])),
            // A GIFAR: a GIF followed by a Java archive.
            'gif/valid-gifar.gif' => self::committed(
                $valid . "PK\x03\x04" . ImageBuilder::repeat("\0", 26) . "PK\x05\x06" . ImageBuilder::repeat("\0", 18),
                self::accept(ImageFormat::GIF, 16, 8, 1, ['trailing_data' => true])
            ),
            // The most blocks a file may have (one-byte comment sub-blocks), then one too many.
            'gif/valid-most-blocks.gif' => self::generated(self::gifOfSubBlocks(GifParser::MAX_BLOCKS - 6), self::accept(ImageFormat::GIF, 8, 8, 1, ['metadata' => true])),
            'gif/spoof-too-many-blocks.gif' => self::generated(self::gifOfSubBlocks(GifParser::MAX_BLOCKS - 5), self::reject(ErrorCode::INVALID_IMAGE)),
            'gif/bomb-65535x65535.gif' => self::committed(
                ImageBuilder::gifScreen(65535, 65535) . ImageBuilder::gifImage(0, 0, 1, 1) . "\x3B",
                self::reject(ErrorCode::IMAGE_TOO_LARGE, true)
            ),
            'gif/bomb-1000-frames.gif' => self::committed(ImageBuilder::gifAnimation(1, 1, 1000), self::reject(ErrorCode::IMAGE_TOO_LARGE, true)),
            // 10,000 frames of one pixel take 150 KB: generated by the tests, not committed.
            'gif/bomb-10000-frames.gif' => self::generated(ImageBuilder::gifAnimation(1, 1, 10000), self::reject(ErrorCode::IMAGE_TOO_LARGE, true)),
            // 26 frames of a 16-megapixel canvas exceed the 400 total megapixels of the default limits.
            'gif/bomb-total-pixels.gif' => self::committed(ImageBuilder::gifAnimation(4000, 4000, 30), self::reject(ErrorCode::IMAGE_TOO_LARGE, true)),
            'gif/spoof-frame-outside-screen.gif' => self::committed(
                ImageBuilder::gifScreen(8, 8) . ImageBuilder::gifImage(4, 4, 8, 8) . "\x3B",
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'gif/spoof-zero-screen.gif' => self::committed(ImageBuilder::gifScreen(0, 8) . ImageBuilder::gifImage(0, 0, 1, 1) . "\x3B", self::reject(ErrorCode::INVALID_IMAGE)),
            'gif/spoof-unknown-block.gif' => self::committed(ImageBuilder::gifScreen(8, 8) . 'X' . ImageBuilder::gifImage(0, 0, 8, 8) . "\x3B", self::reject(ErrorCode::INVALID_IMAGE)),
            'gif/spoof-lzw-code-size.gif' => self::committed(ImageBuilder::gifScreen(8, 8) . ImageBuilder::gifImage(0, 0, 8, 8, 12) . "\x3B", self::reject(ErrorCode::INVALID_IMAGE)),
            'gif/spoof-no-image.gif' => self::committed(ImageBuilder::gifScreen(8, 8) . "\x3B", self::reject(ErrorCode::INVALID_IMAGE)),
            'gif/truncated.gif' => self::committed((string) substr($valid, 0, -10), self::reject(ErrorCode::INVALID_IMAGE)),
            // A GIF/JavaScript polyglot: the width bytes open a script comment, so the width is 0x2A2F = 10799.
            'gif/polyglot-javascript.gif' => self::committed(
                "GIF89a/*\x0A\x00\x00\x00\x00*/=alert(document.domain);/*" . ImageBuilder::gifImage(0, 0, 1, 1) . '*/' . "\x3B",
                self::reject(ErrorCode::IMAGE_TOO_LARGE)
            ),
        ];
    }

    /**
     * @return array<string, array{bytes: string, committed: bool, expect: array<string, bool|int|string>}>
     */
    private static function bmpCases(): array
    {
        $valid = ImageBuilder::bmp(7, 5);
        $paletted = ImageBuilder::bmp(4, 4, 8);
        $os2 = 'BM' . pack('VvvV', 14 + 64 + 16, 0, 0, 14 + 64) . pack('VVVvvVV', 64, 2, 2, 1, 24, 0, 16) . ImageBuilder::repeat("\0", 64 - 24 + 16);

        return [
            'bmp/valid-24-bit.bmp' => self::committed($valid, self::accept(ImageFormat::BMP, 7, 5, 1, ['bit_depth' => 8, 'icc_profile' => false, 'exif' => false, 'metadata' => false, 'orientation' => 1, 'trailing_data' => false])),
            'bmp/valid-1-bit.bmp' => self::committed(ImageBuilder::bmp(9, 3, 1), self::accept(ImageFormat::BMP, 9, 3)),
            'bmp/valid-8-bit-palette.bmp' => self::committed($paletted, self::accept(ImageFormat::BMP, 4, 4)),
            'bmp/valid-rle8.bmp' => self::committed(ImageBuilder::bmp(300, 3, 8, 1), self::accept(ImageFormat::BMP, 300, 3)),
            'bmp/valid-16-bit-bitfields.bmp' => self::committed(ImageBuilder::bmp(5, 5, 16, 3), self::accept(ImageFormat::BMP, 5, 5)),
            'bmp/valid-v5-icc.bmp' => self::committed(
                ImageBuilder::bmp(4, 4, 32, 3, 124, false, 'MBED', ImageBuilder::iccProfile()),
                self::accept(ImageFormat::BMP, 4, 4, 1, ['icc_profile' => true, 'metadata' => false])
            ),
            'bmp/valid-top-down.bmp' => self::committed(ImageBuilder::bmp(5, 4, 24, 0, 40, true), self::accept(ImageFormat::BMP, 5, 4)),
            'bmp/valid-os2-core.bmp' => self::committed(ImageBuilder::bmpCore(6, 3), self::accept(ImageFormat::BMP, 6, 3)),
            'bmp/valid-trailing-php.bmp' => self::committed($valid . '<?php echo "payload"; ?>', self::accept(ImageFormat::BMP, 7, 5, 1, ['trailing_data' => true])),
            'bmp/bomb-100000x100000.bmp' => self::committed(ImageBuilder::patch($valid, 18, pack('VV', 100000, 100000)), self::reject(ErrorCode::IMAGE_TOO_LARGE, true)),
            'bmp/spoof-planes.bmp' => self::committed(ImageBuilder::patch($valid, 26, pack('v', 2)), self::reject(ErrorCode::INVALID_IMAGE)),
            'bmp/spoof-bit-count.bmp' => self::committed(ImageBuilder::patch($valid, 30, pack('V', 1)), self::reject(ErrorCode::INVALID_IMAGE)),
            'bmp/spoof-min-height.bmp' => self::committed(ImageBuilder::patch($valid, 22, pack('V', 0x80000000)), self::reject(ErrorCode::INVALID_IMAGE)),
            'bmp/spoof-data-offset.bmp' => self::committed(ImageBuilder::patch($valid, 10, pack('V', 20)), self::reject(ErrorCode::INVALID_IMAGE)),
            'bmp/spoof-palette-size.bmp' => self::committed(ImageBuilder::patch($paletted, 46, pack('V', 300)), self::reject(ErrorCode::INVALID_IMAGE)),
            'bmp/spoof-top-down-rle.bmp' => self::committed(ImageBuilder::patch(ImageBuilder::bmp(4, 4, 8, 1), 22, pack('V', 0xFFFFFFFC)), self::reject(ErrorCode::INVALID_IMAGE)),
            'bmp/unsupported-embedded-png.bmp' => self::committed(ImageBuilder::patch($valid, 30, pack('V', 5)), self::reject(ErrorCode::UNSUPPORTED_FORMAT)),
            'bmp/unsupported-os2-v2.bmp' => self::committed($os2, self::reject(ErrorCode::UNSUPPORTED_FORMAT)),
            'bmp/unsupported-linked-profile.bmp' => self::committed(
                ImageBuilder::bmp(4, 4, 24, 0, 124, false, 'LINK', "C:\\profile.icc\0"),
                self::reject(ErrorCode::UNSUPPORTED_FORMAT)
            ),
            'bmp/truncated.bmp' => self::committed((string) substr(ImageBuilder::bmp(16, 16), 0, 400), self::reject(ErrorCode::INVALID_IMAGE)),
            'bmp/polyglot-html.bmp' => self::committed("BM<html><body><script>alert(document.domain)</script></body></html>\n", self::reject(ErrorCode::UNSUPPORTED_FORMAT)),
        ];
    }

    /**
     * @return array<string, array{bytes: string, committed: bool, expect: array<string, bool|int|string>}>
     */
    private static function webpCases(): array
    {
        $lossy = ImageBuilder::webpLossy();
        $lossless = ImageBuilder::webpLossless(24, 12);
        $exif = "II*\0" . pack('V', 8) . "\0\0" . pack('V', 0);
        $frame = ImageBuilder::webpChunk('ANMF', ImageBuilder::repeat("\0", 6) . ImageBuilder::uint24(7) . ImageBuilder::uint24(7)
            . ImageBuilder::uint24(100) . "\0" . ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l(8, 8)));

        return [
            'webp/valid-lossy.webp' => self::committed($lossy, self::accept(ImageFormat::WEBP, 16, 16, 1, ['bit_depth' => 8, 'icc_profile' => false, 'exif' => false, 'metadata' => false, 'orientation' => 1, 'trailing_data' => false])),
            'webp/valid-lossless.webp' => self::committed($lossless, self::accept(ImageFormat::WEBP, 24, 12)),
            'webp/valid-lossless-alpha.webp' => self::committed(ImageBuilder::webpLossless(7, 3, true), self::accept(ImageFormat::WEBP, 7, 3)),
            'webp/valid-extended-metadata.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0x2C, 10, 6) . ImageBuilder::webpChunk('ICCP', ImageBuilder::iccProfile())
                    . ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l(10, 6)) . ImageBuilder::webpChunk('EXIF', $exif)
                    . ImageBuilder::webpChunk('XMP ', '<x:xmpmeta xmlns:x="adobe:ns:meta/"/>')),
                self::accept(ImageFormat::WEBP, 10, 6, 1, ['icc_profile' => true, 'exif' => true, 'metadata' => true])
            ),
            'webp/valid-animated.webp' => self::committed(ImageBuilder::webpAnimation(12, 6, 3), self::accept(ImageFormat::WEBP, 12, 6, 3)),
            'webp/valid-exif-orientation.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0x08, 8, 4) . ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l(8, 4))
                    . ImageBuilder::webpChunk('EXIF', ImageBuilder::exifOrientation(5))),
                self::accept(ImageFormat::WEBP, 8, 4, 1, ['exif' => true, 'orientation' => 5])
            ),
            'webp/valid-trailing-zip.webp' => self::committed(
                $lossless . "PK\x05\x06" . ImageBuilder::repeat("\0", 18),
                self::accept(ImageFormat::WEBP, 24, 12, 1, ['trailing_data' => true])
            ),
            // The most chunks an extended file may have, then one too many.
            'webp/valid-most-chunks.webp' => self::generated(self::webpOfChunks(WebpParser::MAX_CHUNKS - 2), self::accept(ImageFormat::WEBP, 8, 8, 1, ['metadata' => true])),
            'webp/spoof-too-many-chunks.webp' => self::generated(self::webpOfChunks(WebpParser::MAX_CHUNKS - 1), self::reject(ErrorCode::INVALID_IMAGE)),
            // C-2: the content decides, not the name: a WEBP named .jpg is a WEBP, refused unless WEBP is enabled.
            'webp/valid-named-as-jpeg.jpg' => self::committed(ImageBuilder::webpLossless(8, 8), self::accept(ImageFormat::WEBP, 8, 8)),
            'webp/bomb-60000x60000.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0, 60000, 60000) . ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l(1, 1))),
                self::reject(ErrorCode::IMAGE_TOO_LARGE, true)
            ),
            // A real lossless bomb: 16384 x 16384 pixels coded on zero bits each.
            'webp/bomb-lossless-16384x16384.webp' => self::committed(ImageBuilder::webpLossless(16384, 16384), self::reject(ErrorCode::IMAGE_TOO_LARGE, true)),
            'webp/bomb-lossy-16383x16383.webp' => self::committed(ImageBuilder::patch($lossy, 26, pack('vv', 16383, 16383)), self::reject(ErrorCode::IMAGE_TOO_LARGE, true)),
            'webp/bomb-301-frames.webp' => self::committed(ImageBuilder::webpAnimation(1, 1, 301), self::reject(ErrorCode::IMAGE_TOO_LARGE)),
            'webp/spoof-canvas-area.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0, 100000, 100000) . ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l(1, 1))),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'webp/spoof-canvas-mismatch.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0, 10, 10) . ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l(8, 8))),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'webp/spoof-vp8-start-code.webp' => self::committed(ImageBuilder::patch($lossy, 23, "\0\0\0"), self::reject(ErrorCode::INVALID_IMAGE)),
            'webp/spoof-exif-beyond-end.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0x08, 8, 8) . ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l(8, 8)) . 'EXIF' . pack('V', 0x7FFFFFF0) . $exif),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'webp/spoof-frame-without-anim.webp' => self::committed(ImageBuilder::riff(ImageBuilder::vp8x(0x02, 8, 8) . $frame), self::reject(ErrorCode::INVALID_IMAGE)),
            'webp/spoof-riff-avi.webp' => self::committed('RIFF' . pack('V', 16) . 'AVI LIST' . pack('V', 4) . 'hdrl', self::reject(ErrorCode::UNSUPPORTED_FORMAT)),
            'webp/truncated.webp' => self::committed((string) substr($lossless, 0, -4), self::reject(ErrorCode::INVALID_IMAGE)),
        ];
    }

    /**
     * @return array<string, array{bytes: string, committed: bool, expect: array<string, bool|int|string>}>
     */
    private static function avifCases(): array
    {
        $still = ImageBuilder::avifStill();
        $sequence = ImageBuilder::avifSequence();
        $ispe = ImageBuilder::find($still, 'ispe');
        $sampleEntry = ImageBuilder::find($sequence, 'av01', ImageBuilder::find($sequence, 'stsd'));
        $sampleSizes = ImageBuilder::find($sequence, 'stsz');
        $colour = ImageBuilder::find($still, 'colr') - 4;
        // A track whose handler is "vide" instead of "pict": decoders play any AV1 track.
        $video = ImageBuilder::patch($sequence, ImageBuilder::find($sequence, 'pict', ImageBuilder::find($sequence, 'trak')), 'vide');
        // Replaces the 19-byte colr property with a 9-byte property and a 10-byte free box, so that no offset moves,
        // and marks its association (the fourth of the ipma box) essential, as a transformative property must be.
        $associations = ImageBuilder::find($still, 'ipma') + 4 + 4 + 4 + 2 + 1;
        $transformed = static function (string $type, int $value) use ($still, $colour, $associations): string {
            $bytes = ImageBuilder::patch($still, $colour, ImageBuilder::avifProperty($type, $value) . pack('N', 10) . 'free' . "\0\0");

            return ImageBuilder::patch($bytes, $associations + 3, "\x84");
        };

        return [
            'avif/valid-still.avif' => self::committed($still, self::accept(ImageFormat::AVIF, 8, 8, 1, ['bit_depth' => 8, 'icc_profile' => false, 'exif' => false, 'metadata' => false, 'orientation' => 1, 'trailing_data' => false])),
            'avif/valid-sequence.avif' => self::committed($sequence, self::accept(ImageFormat::AVIF, 8, 8, 3)),
            'avif/valid-video-track.avif' => self::committed($video, self::accept(ImageFormat::AVIF, 8, 8, 3)),
            // irot turns anticlockwise: one quarter turn is EXIF orientation 8. imir mode 1 mirrors left to right.
            'avif/valid-rotated.avif' => self::committed($transformed('irot', 1), self::accept(ImageFormat::AVIF, 8, 8, 1, ['orientation' => 8])),
            'avif/valid-mirrored.avif' => self::committed($transformed('imir', 1), self::accept(ImageFormat::AVIF, 8, 8, 1, ['orientation' => 2])),
            'avif/valid-trailing-box.avif' => self::committed(
                $still . pack('N', 32) . 'skip<?php echo "payload"; ?>',
                self::accept(ImageFormat::AVIF, 8, 8, 1, ['trailing_data' => true])
            ),
            // The most boxes a file may have, then one too many.
            'avif/valid-most-boxes.avif' => self::generated(
                $still . ImageBuilder::repeat(pack('N', 8) . 'free', AvifParser::MAX_BOXES - 15),
                self::accept(ImageFormat::AVIF, 8, 8, 1, ['trailing_data' => true])
            ),
            'avif/spoof-too-many-boxes.avif' => self::generated(
                $still . ImageBuilder::repeat(pack('N', 8) . 'free', AvifParser::MAX_BOXES - 14),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'avif/bomb-100000x100000.avif' => self::committed(ImageBuilder::patch($still, $ispe + 8, pack('NN', 100000, 100000)), self::reject(ErrorCode::IMAGE_TOO_LARGE, true)),
            'avif/bomb-sequence-65535x65535.avif' => self::committed(ImageBuilder::patch($sequence, $sampleEntry + 28, pack('nn', 65535, 65535)), self::reject(ErrorCode::IMAGE_TOO_LARGE, true)),
            'avif/bomb-100000-frames.avif' => self::committed(ImageBuilder::patch($sequence, $sampleSizes + 12, pack('N', 100000)), self::reject(ErrorCode::IMAGE_TOO_LARGE, true)),
            'avif/bomb-video-track-100000-frames.avif' => self::committed(ImageBuilder::patch($video, $sampleSizes + 12, pack('N', 100000)), self::reject(ErrorCode::IMAGE_TOO_LARGE, true)),
            'avif/unsupported-grid.avif' => self::committed(
                ImageBuilder::patch($still, ImageBuilder::find($still, 'av01', ImageBuilder::find($still, 'infe')), 'grid'),
                self::reject(ErrorCode::UNSUPPORTED_FORMAT)
            ),
            // Decoders read the first copy of a box or property, so a second copy must not hide a large first one.
            'avif/spoof-two-iprp.avif' => self::committed(ImageBuilder::avifReproducer('two-iprp'), self::reject(ErrorCode::INVALID_IMAGE)),
            'avif/spoof-two-pitm.avif' => self::committed(ImageBuilder::avifReproducer('two-pitm'), self::reject(ErrorCode::INVALID_IMAGE)),
            'avif/spoof-two-ispe.avif' => self::committed(ImageBuilder::avifReproducer('two-ispe'), self::reject(ErrorCode::INVALID_IMAGE)),
            // The AV1 sequence header bounds the frames the decoder allocates: ispe and the sample entry may not say less.
            'avif/spoof-ispe-below-av1.avif' => self::committed(ImageBuilder::patch($still, $ispe + 8, pack('NN', 4, 4)), self::reject(ErrorCode::INVALID_IMAGE)),
            'avif/spoof-ispe-below-av1-4000x4000.avif' => self::committed(ImageBuilder::avifReproducer('ispe-below-av1'), self::reject(ErrorCode::INVALID_IMAGE)),
            'avif/spoof-sample-entry-below-av1.avif' => self::committed(
                ImageBuilder::patch($sequence, $sampleEntry + 28, pack('nn', 4, 4)),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'avif/unsupported-heic-brand.avif' => self::committed(str_replace('avif', 'heic', (string) substr($still, 0, 32)) . (string) substr($still, 32), self::reject(ErrorCode::UNSUPPORTED_FORMAT)),
            'avif/spoof-no-ispe.avif' => self::committed(ImageBuilder::patch($still, $ispe, 'xspe'), self::reject(ErrorCode::INVALID_IMAGE)),
            'avif/spoof-ftyp-only.avif' => self::committed((string) substr($still, 0, 32), self::reject(ErrorCode::INVALID_IMAGE)),
            'avif/truncated.avif' => self::committed((string) substr($still, 0, 200), self::reject(ErrorCode::INVALID_IMAGE)),
        ];
    }

    /**
     * @return array<string, array{bytes: string, committed: bool, expect: array<string, bool|int|string>}>
     */
    private static function tiffCases(): array
    {
        $valid = ImageBuilder::tiff(9, 5);
        $tiny = ImageBuilder::tiff(8, 8);

        return [
            'tiff/valid-grey-little-endian.tif' => self::committed($valid, self::accept(ImageFormat::TIFF, 9, 5, 1, ['bit_depth' => 8, 'icc_profile' => false, 'exif' => false, 'metadata' => false, 'orientation' => 1, 'trailing_data' => false])),
            'tiff/valid-rgb-big-endian.tif' => self::committed(ImageBuilder::tiff(6, 4, true, 3), self::accept(ImageFormat::TIFF, 6, 4)),
            'tiff/valid-metadata.tif' => self::committed(
                ImageBuilder::tiff(4, 4, false, 1, 1, [270 => [2, "corpus\0"], 34675 => [7, ImageBuilder::iccProfile()]]),
                self::accept(ImageFormat::TIFF, 4, 4, 1, ['icc_profile' => true, 'metadata' => true])
            ),
            // ImageWidth and ImageLength are the first two entries of the directory at offset 8.
            'tiff/bomb-100000x100000.tif' => self::committed(
                ImageBuilder::patch(ImageBuilder::patch($tiny, 18, pack('V', 100000)), 30, pack('V', 100000)),
                self::reject(ErrorCode::IMAGE_TOO_LARGE, true)
            ),
            'tiff/unsupported-old-jpeg.tif' => self::committed(ImageBuilder::tiff(4, 4, false, 1, 6), self::reject(ErrorCode::UNSUPPORTED_FORMAT)),
            'tiff/spoof-directory-offset.tif' => self::committed(ImageBuilder::patch($valid, 4, pack('V', 4)), self::reject(ErrorCode::INVALID_IMAGE)),
            'tiff/spoof-zero-width.tif' => self::committed(ImageBuilder::patch($tiny, 18, pack('V', 0)), self::reject(ErrorCode::INVALID_IMAGE)),
            'tiff/truncated.tif' => self::committed((string) substr($tiny, 0, -32), self::reject(ErrorCode::INVALID_IMAGE)),
        ];
    }

    /**
     * @return array<string, array{bytes: string, committed: bool, expect: array<string, bool|int|string>}>
     */
    private static function otherCases(): array
    {
        $unsupported = self::reject(ErrorCode::UNSUPPORTED_FORMAT);
        $prolog = "\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- generated -->\n"
            . "<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\">\n";

        return [
            // SVG is identified but never rasterised: refused even when enabled.
            'svg/unsupported-plain.svg' => self::committed(ImageBuilder::svg(), $unsupported),
            'svg/unsupported-prolog.svg' => self::committed(ImageBuilder::svg($prolog), $unsupported),
            'svg/unsupported-named-as-jpeg.jpg' => self::committed(ImageBuilder::svg(), $unsupported),
            'other/unsupported-empty.png' => self::committed('', $unsupported),
            'other/unsupported-html.png' => self::committed("<!DOCTYPE html>\n<html><body><script>alert(document.domain)</script></body></html>\n", $unsupported),
            'other/unsupported-php.jpg' => self::committed("<?php system(\$_GET['c']); ?>\n", $unsupported),
            'other/unsupported-zip.gif' => self::committed("PK\x03\x04" . ImageBuilder::repeat("\0", 26) . "PK\x05\x06" . ImageBuilder::repeat("\0", 18), $unsupported),
            'other/unsupported-heic.avif' => self::committed(pack('N', 24) . 'ftypheic' . pack('N', 0) . 'mif1heic' . pack('N', 8) . 'mdat', $unsupported),
            'other/polyglot-html-then-png.png' => self::committed("<html><!--\n" . ImageBuilder::png(4, 4) . "--></html>\n", $unsupported),
            'other/polyglot-pdf-then-jpeg.jpg' => self::committed("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n" . ImageBuilder::jpeg(8, 8), $unsupported),
        ];
    }

    /**
     * An 8 x 8 GIF whose comment is made of $count one-byte sub-blocks.
     */
    private static function gifOfSubBlocks(int $count): string
    {
        return ImageBuilder::gifScreen(8, 8) . "\x21\xFE" . ImageBuilder::repeat("\x01A", $count) . "\x00"
            . ImageBuilder::gifImage(0, 0, 8, 8) . "\x3B";
    }

    /**
     * An 8 x 8 extended WEBP followed by $count empty unknown chunks.
     */
    private static function webpOfChunks(int $count): string
    {
        return ImageBuilder::riff(ImageBuilder::vp8x(0, 8, 8) . ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l(8, 8))
            . ImageBuilder::repeat(ImageBuilder::webpChunk('ABCD', ''), $count));
    }

    /**
     * @param array<string, bool|int|string> $expect
     *
     * @return array{bytes: string, committed: bool, expect: array<string, bool|int|string>}
     */
    private static function committed(string $bytes, array $expect): array
    {
        return ['bytes' => $bytes, 'committed' => true, 'expect' => $expect];
    }

    /**
     * @param array<string, bool|int|string> $expect
     *
     * @return array{bytes: string, committed: bool, expect: array<string, bool|int|string>}
     */
    private static function generated(string $bytes, array $expect): array
    {
        return ['bytes' => $bytes, 'committed' => false, 'expect' => $expect];
    }

    /**
     * @param array<string, bool|int|string> $more other ImageInfo::toArray() values to expect, or max_bytes_read
     *
     * @return array<string, bool|int|string>
     */
    private static function accept(string $format, int $width, int $height, int $frames = 1, array $more = []): array
    {
        return ['format' => $format, 'width' => $width, 'height' => $height, 'frame_count' => $frames] + $more;
    }

    /**
     * @param bool $fromHeader whether the refusal must come from the header, within HEADER_BYTES_READ bytes
     *
     * @return array<string, bool|int|string>
     */
    private static function reject(string $errorCode, bool $fromHeader = false): array
    {
        return ['error' => $errorCode] + ($fromHeader ? ['max_bytes_read' => self::HEADER_BYTES_READ] : []);
    }
}
