<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Fixtures;

use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\Parser\Av1SequenceHeader;
use Mfonte\SecureImageConverter\Validation\Parser\AvifParser;
use Mfonte\SecureImageConverter\Validation\Parser\BmpParser;
use Mfonte\SecureImageConverter\Validation\Parser\GifParser;
use Mfonte\SecureImageConverter\Validation\Parser\JpegParser;
use Mfonte\SecureImageConverter\Validation\Parser\PngParser;
use Mfonte\SecureImageConverter\Validation\Parser\TiffParser;
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
 * most chunks, blocks, boxes, directory entries or strips allowed, then one too many), which bound the time a
 * hostile file costs.
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
        // The 8 x 8 grey image with $segments between its scan data and its EOI marker.
        $afterScan = static function (string $segments) use ($grey): string {
            return substr($grey, 0, -2) . $segments . "\xFF\xD9";
        };
        // The 8 x 8 grey image whose scan data ends with zero bytes up to $offset from its start, then $bytes. The
        // parser searches the scan data in chunks of 256 bytes, then 512, 1024...: $bytes straddle a chunk boundary.
        // The scan data follows the SOS marker and its segment, whose length the 32-bit value at the marker ends with.
        $scanStart = ImageBuilder::find($grey, "\xFF\xDA");
        $scanStart += 2 + (ImageBuilder::readUint32($grey, $scanStart) & 0xFFFF);
        $acrossChunks = static function (int $offset, string $bytes) use ($grey, $scanStart): string {
            $data = (string) substr($grey, $scanStart, -2);

            return substr($grey, 0, $scanStart) . $data . ImageBuilder::repeat("\0", $offset - strlen($data)) . $bytes;
        };

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
            // Decoders read the segments between the scan data and EOI too: a comment there is metadata, its bytes are
            // not the end of the image.
            'jpeg/valid-zip-in-comment-after-scan.jpg' => self::committed(
                $afterScan(ImageBuilder::jpegSegment(0xFE, "PK\x05\x06" . ImageBuilder::repeat("\0", 18))),
                self::accept(ImageFormat::JPEG, 8, 8, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            'jpeg/valid-eoi-in-comment-after-scan.jpg' => self::committed(
                $afterScan(ImageBuilder::jpegSegment(0xFE, "\xFF\xD9<?php echo 'payload'; ?>")),
                self::accept(ImageFormat::JPEG, 8, 8, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            'jpeg/valid-eoi-across-chunks.jpg' => self::committed($acrossChunks(255, "\xFF\xD9"), self::accept(ImageFormat::JPEG, 8, 8, 1, ['trailing_data' => false])),
            'jpeg/valid-fill-across-chunks.jpg' => self::committed($acrossChunks(254, "\xFF\xFF\xFF\xD9"), self::accept(ImageFormat::JPEG, 8, 8, 1, ['trailing_data' => false])),
            'jpeg/valid-stuffed-across-chunks.jpg' => self::committed(
                $acrossChunks(767, "\xFF\x00\xFF\xD0" . ImageBuilder::repeat("\0", 1022) . "\xFF\xD7\xFF\xD9"),
                self::accept(ImageFormat::JPEG, 8, 8, 1, ['trailing_data' => false])
            ),
            'jpeg/valid-comment-across-chunks.jpg' => self::committed(
                $acrossChunks(255, ImageBuilder::jpegSegment(0xFE, 'comment') . "\xFF\xD9"),
                self::accept(ImageFormat::JPEG, 8, 8, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            // The image has 6 segments before its scan data: then the most segments allowed after it, and one too many.
            'jpeg/valid-most-segments-after-scan.jpg' => self::committed(
                $afterScan(ImageBuilder::repeat("\xFF\xFE\x00\x02", JpegParser::MAX_SEGMENTS - 6)),
                self::accept(ImageFormat::JPEG, 8, 8, 1, ['metadata' => true])
            ),
            'jpeg/spoof-too-many-segments-after-scan.jpg' => self::committed(
                $afterScan(ImageBuilder::repeat("\xFF\xFE\x00\x02", JpegParser::MAX_SEGMENTS - 5)),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            // Decoders go over the whole image once per scan: the most scans allowed (a DC scan and AC scans), then one
            // too many.
            'jpeg/valid-most-scans.jpg' => self::committed(ImageBuilder::progressiveJpeg(8, 8, JpegParser::MAX_SCANS - 1), self::accept(ImageFormat::JPEG, 8, 8)),
            'jpeg/bomb-too-many-scans.jpg' => self::committed(ImageBuilder::progressiveJpeg(8, 8, JpegParser::MAX_SCANS), self::reject(ErrorCode::UNSUPPORTED_FORMAT)),
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
        $zip = "PK\x05\x06" . ImageBuilder::repeat("\0", 18);
        // A palette image of two entries with $chunks after its PLTE chunk.
        $palette = ImageBuilder::png(4, 4, 8, 3);
        $afterPalette = ImageBuilder::find($palette, 'PLTE') + 4 + 6 + 4;
        $withPalette = static function (string $chunks) use ($palette, $afterPalette): string {
            return substr($palette, 0, $afterPalette) . $chunks . substr($palette, $afterPalette);
        };

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
            // libpng and ImageMagick ignore animation chunks outside an animated PNG (Pillow refuses the file), and
            // decoders do not render a suggested palette.
            'png/valid-zip-in-fdat-without-actl.png' => self::committed(
                ImageBuilder::png(4, 4, 8, 2, '', ImageBuilder::pngChunk('fdAT', pack('N', 1) . "PK\x05\x06" . ImageBuilder::repeat("\0", 18))),
                self::accept(ImageFormat::PNG, 4, 4, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            'png/valid-zip-in-fctl-without-actl.png' => self::committed(
                ImageBuilder::png(4, 4, 8, 2, ImageBuilder::pngChunk('fcTL', "PK\x05\x06" . ImageBuilder::repeat("\0", 18))),
                self::accept(ImageFormat::PNG, 4, 4, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            'png/valid-zip-in-splt.png' => self::committed(
                ImageBuilder::png(4, 4, 8, 2, ImageBuilder::pngChunk('sPLT', "zip\0\x08PK\x05\x06" . ImageBuilder::repeat("\0", 18))),
                self::accept(ImageFormat::PNG, 4, 4, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            // Decoders read the fields of a colour chunk and nothing beyond them.
            'png/valid-phys.png' => self::committed(
                ImageBuilder::png(4, 4, 8, 2, ImageBuilder::pngChunk('pHYs', pack('NNC', 2835, 2835, 1))),
                self::accept(ImageFormat::PNG, 4, 4, 1, ['metadata' => false])
            ),
            'png/valid-zip-in-long-phys.png' => self::committed(
                ImageBuilder::png(4, 4, 8, 2, ImageBuilder::pngChunk('pHYs', pack('NNC', 2835, 2835, 1) . $zip)),
                self::accept(ImageFormat::PNG, 4, 4, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            // The fields of bKGD, sBIT and tRNS depend on the colour type, and a palette has one transparency byte per
            // entry; decoders do not render a histogram.
            'png/valid-trns.png' => self::committed(
                ImageBuilder::png(4, 4, 8, 2, ImageBuilder::pngChunk('tRNS', pack('nnn', 0, 0, 0))),
                self::accept(ImageFormat::PNG, 4, 4, 1, ['metadata' => false])
            ),
            'png/valid-zip-in-long-trns.png' => self::committed(
                ImageBuilder::png(4, 4, 8, 2, ImageBuilder::pngChunk('tRNS', pack('nnn', 0, 0, 0) . $zip)),
                self::accept(ImageFormat::PNG, 4, 4, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            'png/valid-palette-trns.png' => self::committed(
                $withPalette(ImageBuilder::pngChunk('tRNS', "\xFF\x00") . ImageBuilder::pngChunk('bKGD', "\x01")),
                self::accept(ImageFormat::PNG, 4, 4, 1, ['metadata' => false])
            ),
            'png/valid-zip-in-palette-trns.png' => self::committed(
                $withPalette(ImageBuilder::pngChunk('tRNS', "\xFF\x00" . $zip)),
                self::accept(ImageFormat::PNG, 4, 4, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            'png/valid-zip-in-palette-bkgd.png' => self::committed(
                $withPalette(ImageBuilder::pngChunk('bKGD', "\x01" . $zip)),
                self::accept(ImageFormat::PNG, 4, 4, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            'png/valid-palette-bkgd-of-rgb-length.png' => self::committed(
                $withPalette(ImageBuilder::pngChunk('bKGD', "\x01\x00\x00\x00\x00\x00")),
                self::accept(ImageFormat::PNG, 4, 4, 1, ['metadata' => true])
            ),
            'png/valid-histogram.png' => self::committed(
                $withPalette(ImageBuilder::pngChunk('hIST', pack('nn', 16, 0))),
                self::accept(ImageFormat::PNG, 4, 4, 1, ['metadata' => true])
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
        ] + self::pngColourChunkCases($withPalette);
    }

    /**
     * Images of each colour type with a bKGD, sBIT or tRNS chunk as long as its fields, which is not metadata, and
     * with one a byte longer, which is. The palette image has two entries, so one transparency byte more stays within
     * its PLTE chunk.
     *
     * @param \Closure(string): string $withPalette the palette image with chunks after its PLTE chunk
     *
     * @return array<string, array{bytes: string, committed: bool, expect: array<string, bool|int|string>}>
     */
    private static function pngColourChunkCases(\Closure $withPalette): array
    {
        $fields = [
            'bKGD' => [0 => 2, 2 => 6, 3 => 1, 4 => 2, 6 => 6],
            'sBIT' => [0 => 1, 2 => 3, 3 => 3, 4 => 2, 6 => 4],
            'tRNS' => [0 => 2, 2 => 6, 3 => 2, 4 => 0, 6 => 0],
        ];
        $cases = [];
        foreach ($fields as $type => $lengths) {
            foreach ($lengths as $colourType => $length) {
                foreach ([$length, $length + 1] as $size) {
                    // sBIT values are significant bits, from 1 to the bit depth; bKGD and tRNS hold samples.
                    $chunk = ImageBuilder::pngChunk($type, ImageBuilder::repeat($type === 'sBIT' ? "\x08" : "\x00", $size));
                    $bytes = $colourType === 3 && $type !== 'sBIT' ? $withPalette($chunk) : ImageBuilder::png(4, 4, 8, $colourType, $chunk);
                    $name = sprintf('png/valid-%s-of-%d-bytes-in-colour-type-%d.png', strtolower($type), $size, $colourType);
                    $cases[$name] = self::generated($bytes, self::accept(ImageFormat::PNG, 4, 4, 1, ['metadata' => $size > $length]));
                }
            }
        }

        return $cases;
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
            // The most blocks a file may have (one-byte comment sub-blocks), then one too many: the comment, image and
            // trailer introducers take BLOCK_COST units each, the image data and the two terminators three more.
            'gif/valid-most-blocks.gif' => self::generated(self::gifOfSubBlocks(GifParser::MAX_BLOCKS - 3 * GifParser::BLOCK_COST - 3), self::accept(ImageFormat::GIF, 8, 8, 1, ['metadata' => true])),
            'gif/spoof-too-many-blocks.gif' => self::generated(self::gifOfSubBlocks(GifParser::MAX_BLOCKS - 3 * GifParser::BLOCK_COST - 2), self::reject(ErrorCode::INVALID_IMAGE)),
            // The most empty comment extensions a file may have, then one too many: each takes BLOCK_COST units and its
            // terminator one; the image and trailer take 2 x BLOCK_COST + 2.
            'gif/valid-most-extensions.gif' => self::generated(
                self::gifOfExtensions(intdiv(GifParser::MAX_BLOCKS - 2 * GifParser::BLOCK_COST - 2, GifParser::BLOCK_COST + 1)),
                self::accept(ImageFormat::GIF, 8, 8, 1, ['metadata' => true])
            ),
            'gif/spoof-too-many-extensions.gif' => self::generated(
                self::gifOfExtensions(intdiv(GifParser::MAX_BLOCKS - 2 * GifParser::BLOCK_COST - 2, GifParser::BLOCK_COST + 1) + 1),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            // Decoders skip plain text extensions and extensions of unknown label, which can carry anything.
            'gif/valid-zip-in-unknown-extension.gif' => self::committed(
                ImageBuilder::gif(8, 8, 1, "\x21\x99" . ImageBuilder::gifSubBlocks("PK\x05\x06" . ImageBuilder::repeat("\0", 18))),
                self::accept(ImageFormat::GIF, 8, 8, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            // Decoders read the fields of a graphic control or loop extension from its first sub-block, and skip the rest.
            'gif/valid-zip-in-graphic-control.gif' => self::committed(
                ImageBuilder::gif(8, 8, 1, "\x21\xF9\x04\x00\x00\x00\x00" . ImageBuilder::gifSubBlocks("PK\x05\x06" . ImageBuilder::repeat("\0", 18))),
                self::accept(ImageFormat::GIF, 8, 8, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            'gif/valid-zip-in-long-graphic-control.gif' => self::committed(
                ImageBuilder::gif(8, 8, 1, "\x21\xF9" . ImageBuilder::gifSubBlocks("\0\0\0\0PK\x05\x06" . ImageBuilder::repeat("\0", 18))),
                self::accept(ImageFormat::GIF, 8, 8, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            'gif/valid-zip-in-loop-extension.gif' => self::committed(
                ImageBuilder::gif(8, 8, 1, "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00" . ImageBuilder::gifSubBlocks("PK\x05\x06" . ImageBuilder::repeat("\0", 18))),
                self::accept(ImageFormat::GIF, 8, 8, 1, ['metadata' => true, 'trailing_data' => false])
            ),
            'gif/valid-plain-text.gif' => self::committed(
                ImageBuilder::gif(8, 8, 1, "\x21\x01\x0C" . pack('vvvvCCCC', 0, 0, 8, 8, 8, 8, 1, 0) . ImageBuilder::gifSubBlocks('corpus')),
                self::accept(ImageFormat::GIF, 8, 8, 1, ['metadata' => true])
            ),
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
            // Decoders skip the bytes between the headers and the pixel data.
            'bmp/valid-zip-before-pixels.bmp' => self::committed(self::bmpWithGap(ImageBuilder::bmp(7, 5), "PK\x05\x06" . ImageBuilder::repeat("\0", 18)), self::accept(ImageFormat::BMP, 7, 5, 1, ['trailing_data' => true])),
            // A colour profile there is not hidden data.
            'bmp/valid-v5-icc-before-pixels.bmp' => self::committed(self::bmpProfileBeforePixels(), self::accept(ImageFormat::BMP, 4, 4, 1, ['icc_profile' => true, 'trailing_data' => false])),
            // Images of more than 8 bits have no palette for decoders to read, whatever the colours-used field says.
            'bmp/valid-zip-as-unused-palette.bmp' => self::committed(
                ImageBuilder::patch(self::bmpWithGap($valid, "PK\x05\x06" . ImageBuilder::repeat("\0", 20)), 46, pack('V', 6)),
                self::accept(ImageFormat::BMP, 7, 5, 1, ['trailing_data' => true])
            ),
            // Nor do they read the bytes between the pixel data and a colour profile stored after it.
            'bmp/valid-zip-before-icc.bmp' => self::committed(
                self::bmpGapBeforeProfile("PK\x05\x06" . ImageBuilder::repeat("\0", 18)),
                self::accept(ImageFormat::BMP, 4, 4, 1, ['icc_profile' => true, 'trailing_data' => true])
            ),
            // ImageMagick reads one more image, of any size, when the two bytes where it stops reading are a BMP magic.
            'bmp/unsupported-second-image.bmp' => self::committed($valid . ImageBuilder::bmp(8, 8), self::reject(ErrorCode::UNSUPPORTED_FORMAT)),
            'bmp/unsupported-second-icon.bmp' => self::committed($valid . 'ic' . ImageBuilder::repeat("\0", 30), self::reject(ErrorCode::UNSUPPORTED_FORMAT)),
            'bmp/unsupported-image-after-icc.bmp' => self::committed(
                ImageBuilder::bmp(4, 4, 32, 3, 124, false, 'MBED', ImageBuilder::iccProfile()) . ImageBuilder::bmp(8, 8),
                self::reject(ErrorCode::UNSUPPORTED_FORMAT)
            ),
            // Run-length encoded data ends at its end of bitmap whatever its declared size, and ImageMagick reads two more bytes.
            'bmp/unsupported-image-after-rle-end.bmp' => self::committed(
                self::bmpOfRuns(8, 8, ImageBuilder::repeat("\x07\x01\0\0", 7) . "\0\1\0\0" . ImageBuilder::bmp(8, 8)),
                self::reject(ErrorCode::UNSUPPORTED_FORMAT)
            ),
            // An absolute run of 5 pixels takes 3 bytes and a pad byte.
            'bmp/unsupported-image-after-rle4-end.bmp' => self::committed(
                self::bmpOfRuns(8, 2, "\0\x05\x12\x34\x50\0\0\0\0\1\0\0" . ImageBuilder::bmp(8, 8), true),
                self::reject(ErrorCode::UNSUPPORTED_FORMAT)
            ),
            // A run of 66 pixels of colour 77 reads "BM": only the bytes where the data ends count.
            'bmp/valid-magic-in-rle-data.bmp' => self::committed(self::bmpOfRuns(66, 2, "BM\0\0BM\0\0\0\1"), self::accept(ImageFormat::BMP, 66, 2)),
            // The most runs ImageMagick's walk may take (the first run of a series and the end of bitmap count as
            // three), then one too many.
            'bmp/valid-most-runs.bmp' => self::generated(
                self::bmpOfRuns(1024, 1024, ImageBuilder::repeat("\x01\0", BmpParser::MAX_RUNS - 2 * BmpParser::ESCAPE_COST + 1) . "\0\1"),
                self::accept(ImageFormat::BMP, 1024, 1024)
            ),
            'bmp/unsupported-too-many-runs.bmp' => self::generated(
                self::bmpOfRuns(1024, 1024, ImageBuilder::repeat("\x01\0", BmpParser::MAX_RUNS - 2 * BmpParser::ESCAPE_COST + 2) . "\0\1"),
                self::reject(ErrorCode::UNSUPPORTED_FORMAT)
            ),
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
        $zip = "PK\x05\x06" . ImageBuilder::repeat("\0", 18);
        // The VP8 payload of the 16 x 16 lossy sample follows the RIFF header and its chunk header.
        $vp8 = (string) substr($lossy, 12 + 8, ImageBuilder::readUint32($lossy, 16, false));
        $alpha = ImageBuilder::webpChunk('ALPH', "\0" . ImageBuilder::repeat("\xFF", 16 * 16));
        // An 8 x 8 animation of one frame of $frameSize x $frameSize: $animationData follows the fields of ANIM,
        // $frameData those of the frame.
        $animation = static function (string $animationData, string $frameData, int $frameSize = 8): string {
            return ImageBuilder::riff(ImageBuilder::vp8x(0x02, 8, 8) . ImageBuilder::webpChunk('ANIM', "\0\0\0\0\0\0" . $animationData)
                . ImageBuilder::webpChunk('ANMF', ImageBuilder::repeat("\0", 6) . ImageBuilder::uint24($frameSize - 1) . ImageBuilder::uint24($frameSize - 1)
                . ImageBuilder::uint24(100) . "\0" . $frameData));
        };
        $bitstream = ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l(8, 8));

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
            // Decoders skip what follows the bitstream of a simple file inside the RIFF payload, and unknown chunks.
            'webp/valid-zip-inside-riff.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l(24, 12)) . "PK\x05\x06" . ImageBuilder::repeat("\0", 18)),
                self::accept(ImageFormat::WEBP, 24, 12, 1, ['metadata' => false, 'trailing_data' => true])
            ),
            'webp/valid-unknown-chunk.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0, 8, 8) . ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l(8, 8))
                    . ImageBuilder::webpChunk('ZIPS', $zip)),
                self::accept(ImageFormat::WEBP, 8, 8, 1, ['metadata' => true, 'trailing_data' => true])
            ),
            // Decoders read the fields of ANIM and skip anything after them.
            'webp/valid-zip-in-anim.webp' => self::committed($animation($zip, $bitstream), self::accept(ImageFormat::WEBP, 8, 8, 1, ['metadata' => false, 'trailing_data' => true])),
            // libwebp reads what follows the bitstream of a frame as more chunks of the file: a frame holds nothing else.
            'webp/spoof-zip-in-frame.webp' => self::committed($animation('', $bitstream . $zip), self::reject(ErrorCode::INVALID_IMAGE)),
            'webp/spoof-chunk-in-frame.webp' => self::committed($animation('', $bitstream . ImageBuilder::webpChunk('ZIPS', $zip)), self::reject(ErrorCode::INVALID_IMAGE)),
            'webp/spoof-frame-in-frame.webp' => self::committed($animation('', $bitstream . $frame), self::reject(ErrorCode::INVALID_IMAGE)),
            // Alpha data comes right before a lossy bitstream, in a still image or at the start of a frame.
            'webp/valid-lossy-alpha.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0x10, 16, 16) . $alpha . ImageBuilder::webpChunk('VP8 ', $vp8)),
                self::accept(ImageFormat::WEBP, 16, 16, 1, ['metadata' => false, 'trailing_data' => false])
            ),
            'webp/valid-lossy-alpha-frame.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0x12, 16, 16) . ImageBuilder::webpChunk('ANIM', "\0\0\0\0\0\0")
                    . ImageBuilder::webpChunk('ANMF', ImageBuilder::repeat("\0", 6) . ImageBuilder::uint24(15) . ImageBuilder::uint24(15)
                    . ImageBuilder::uint24(100) . "\0" . $alpha . ImageBuilder::webpChunk('VP8 ', $vp8))),
                self::accept(ImageFormat::WEBP, 16, 16, 1, ['trailing_data' => false])
            ),
            // The most chunks an extended file may have, then one too many.
            'webp/valid-most-chunks.webp' => self::generated(self::webpOfChunks(WebpParser::MAX_CHUNKS - 2), self::accept(ImageFormat::WEBP, 8, 8, 1, ['metadata' => true, 'trailing_data' => true])),
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
            // Decoders size a frame from its bitstream, and need one.
            'webp/spoof-frame-bitstream-size.webp' => self::committed($animation('', $bitstream, 1), self::reject(ErrorCode::INVALID_IMAGE)),
            'webp/spoof-frame-without-bitstream.webp' => self::committed($animation('', ImageBuilder::webpChunk('ZIPS', $zip)), self::reject(ErrorCode::INVALID_IMAGE)),
            // Decoders disagree on alpha data anywhere but right before a lossy bitstream.
            'webp/spoof-alpha-before-lossless.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0x10, 8, 8) . $alpha . $bitstream),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'webp/spoof-alpha-after-bitstream.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0x10, 16, 16) . ImageBuilder::webpChunk('VP8 ', $vp8) . $alpha),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'webp/spoof-two-alpha-chunks.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0x10, 16, 16) . $alpha . $alpha . ImageBuilder::webpChunk('VP8 ', $vp8)),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'webp/spoof-alpha-outside-frames.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0x12, 8, 8) . ImageBuilder::webpChunk('ANIM', "\0\0\0\0\0\0") . $alpha . $frame),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            // libwebp decodes a still image only when VP8X has its exact size, while its demuxer takes a longer chunk.
            'webp/spoof-zip-in-vp8x.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::webpChunk('VP8X', "\0\0\0\0" . ImageBuilder::uint24(7) . ImageBuilder::uint24(7) . $zip) . $bitstream),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'webp/spoof-anim-too-short.webp' => self::committed(
                ImageBuilder::riff(ImageBuilder::vp8x(0x02, 8, 8) . ImageBuilder::webpChunk('ANIM', "\0\0\0\0") . $frame),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
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
        $large = ImageBuilder::avifReproducer('ispe-below-av1');
        $ispe = ImageBuilder::find($still, 'ispe');
        $sampleEntry = ImageBuilder::find($sequence, 'av01', ImageBuilder::find($sequence, 'stsd'));
        $sampleSizes = ImageBuilder::find($sequence, 'stsz');
        // The track header of the sample has version 1: the width and height follow 32 bytes of times and IDs and 52
        // bytes of layer, volume and matrix.
        $trackHeader = ImageBuilder::find($sequence, 'tkhd');
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
            // libheif decodes every image item that is not a thumbnail or an auxiliary image, ImageMagick each as a frame.
            'avif/valid-thumbnail.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => "av01\0", 2 => "av01\0"], ImageBuilder::avifReference('thmb', 2, 1)),
                self::accept(ImageFormat::AVIF, 8, 8, 1)
            ),
            'avif/valid-alpha.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => "av01\0", 2 => "av01\0"], ImageBuilder::avifReference('auxl', 2, 1), '', [2 => [4]]),
                self::accept(ImageFormat::AVIF, 8, 8, 1)
            ),
            'avif/valid-xmp.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => "av01\0", 2 => "mime\0application/rdf+xml\0"], ImageBuilder::avifReference('cdsc', 2, 1)),
                self::accept(ImageFormat::AVIF, 8, 8, 1, ['exif' => false, 'metadata' => true])
            ),
            'avif/unsupported-two-images.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => "av01\0", 2 => "av01\0"]),
                self::reject(ErrorCode::UNSUPPORTED_FORMAT)
            ),
            'avif/unsupported-grid-item.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => "av01\0", 2 => "grid\0"]),
                self::reject(ErrorCode::UNSUPPORTED_FORMAT)
            ),
            'avif/unsupported-jpeg-item.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => "av01\0", 2 => "jpeg\0"]),
                self::reject(ErrorCode::UNSUPPORTED_FORMAT)
            ),
            'avif/unsupported-jpeg-in-mime-item.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => "av01\0", 2 => "mime\0image/jpeg\0"]),
                self::reject(ErrorCode::UNSUPPORTED_FORMAT)
            ),
            'avif/spoof-reference-size.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => "av01\0", 2 => "av01\0"], ImageBuilder::isoBox('thmb', pack('nnnn', 2, 1, 1, 0))),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'avif/spoof-infe-over-1-kib.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => 'av01' . str_repeat('n', 1024) . "\0"]),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            // The most reference boxes iref may hold, then one too many.
            'avif/valid-most-references.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => "av01\0"], ImageBuilder::repeat(ImageBuilder::avifReference('cdsc', 1, 1), AvifParser::MAX_REFERENCES)),
                self::accept(ImageFormat::AVIF, 8, 8, 1)
            ),
            'avif/spoof-too-many-references.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => "av01\0"], ImageBuilder::repeat(ImageBuilder::avifReference('cdsc', 1, 1), AvifParser::MAX_REFERENCES + 1)),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            // The most items a file may describe, then one too many: the image and EXIF items.
            'avif/valid-most-items.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => "av01\0"] + array_fill(2, AvifParser::MAX_ITEMS - 1, "Exif\0")),
                self::accept(ImageFormat::AVIF, 8, 8, 1, ['exif' => true, 'metadata' => true])
            ),
            'avif/unsupported-too-many-items.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => "av01\0"] + array_fill(2, AvifParser::MAX_ITEMS, "Exif\0")),
                self::reject(ErrorCode::UNSUPPORTED_FORMAT)
            ),
            // Decoders read the first copy of a box or property, so a second copy must not hide a large first one.
            'avif/spoof-two-pixi.avif' => self::committed(
                ImageBuilder::avifOfItems([1 => "av01\0"], '', ImageBuilder::isoFullBox('pixi', 0, "\x03\x10\x10\x10"), [1 => [5]]),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
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
            'avif/spoof-second-sequence-header-4000x4000.avif' => self::committed(self::avifTwoSequenceHeaders(), self::reject(ErrorCode::INVALID_IMAGE)),
            // Decoders size the frames of a track from its track header (16.16 fixed point): it must match the sample
            // entry, and the AV1 sample entry may not hide behind another.
            'avif/spoof-track-header-16000x16000.avif' => self::committed(
                ImageBuilder::patch($sequence, $trackHeader + 4 + 4 + 32 + 52, pack('NN', 16000 << 16, 16000 << 16)),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'avif/unsupported-second-sample-entry.avif' => self::committed(self::avifWithOtherSampleEntries(1), self::reject(ErrorCode::UNSUPPORTED_FORMAT)),
            // The sample description table may hold the most entries allowed, whatever they are, and not one more.
            'avif/unsupported-most-sample-entries.avif' => self::committed(self::avifWithOtherSampleEntries(AvifParser::MAX_ENTRIES - 1), self::reject(ErrorCode::UNSUPPORTED_FORMAT)),
            'avif/spoof-too-many-sample-entries.avif' => self::committed(self::avifWithOtherSampleEntries(AvifParser::MAX_ENTRIES), self::reject(ErrorCode::INVALID_IMAGE)),
            // libavif writes the sequence header in av1C too; libheif feeds those configuration OBUs to the decoder
            // before the data of the item, so they may not hold a larger sequence header or frame.
            'avif/valid-av1c-sequence-header.avif' => self::committed(self::avifWithConfiguration(self::avifSequenceHeaderObu($still)), self::accept(ImageFormat::AVIF, 8, 8, 1, ['trailing_data' => false])),
            'avif/spoof-av1c-frame-4000x4000.avif' => self::committed(
                self::avifWithConfiguration((string) substr($large, ImageBuilder::find($large, 'mdat') + 4)),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'avif/spoof-track-av1c-sequence-header-4000x4000.avif' => self::committed(self::avifSequenceWithConfiguration(self::avifSequenceHeaderObu($large)), self::reject(ErrorCode::INVALID_IMAGE)),
            // libheif reads every infe box, whatever the entry count of iinf: an item beyond it would go unchecked.
            'avif/spoof-item-beyond-iinf-count.avif' => self::committed(self::avifItemBeyondCount(), self::reject(ErrorCode::INVALID_IMAGE)),
            // The most OBUs and sequence headers a file may have, then one too many.
            'avif/valid-most-obus.avif' => self::generated(self::avifWithObus(1, Av1SequenceHeader::MAX_OBUS - 3), self::accept(ImageFormat::AVIF, 8, 8)),
            'avif/spoof-too-many-obus.avif' => self::generated(self::avifWithObus(1, Av1SequenceHeader::MAX_OBUS - 2), self::reject(ErrorCode::INVALID_IMAGE)),
            'avif/valid-most-sequence-headers.avif' => self::generated(self::avifWithObus(Av1SequenceHeader::MAX_SEQUENCE_HEADERS, 0), self::accept(ImageFormat::AVIF, 8, 8)),
            'avif/spoof-too-many-sequence-headers.avif' => self::generated(self::avifWithObus(Av1SequenceHeader::MAX_SEQUENCE_HEADERS + 1, 0), self::reject(ErrorCode::INVALID_IMAGE)),
            // The most property associations and item extents a file may have, then one too many.
            'avif/valid-most-associations.avif' => self::generated(self::avifOfAssociations(AvifParser::MAX_ASSOCIATIONS), self::accept(ImageFormat::AVIF, 8, 8)),
            'avif/spoof-too-many-associations.avif' => self::generated(self::avifOfAssociations(AvifParser::MAX_ASSOCIATIONS + 1), self::reject(ErrorCode::INVALID_IMAGE)),
            'avif/valid-most-extents.avif' => self::generated(self::avifOfExtents(AvifParser::MAX_ASSOCIATIONS), self::accept(ImageFormat::AVIF, 8, 8)),
            'avif/spoof-too-many-extents.avif' => self::generated(self::avifOfExtents(AvifParser::MAX_ASSOCIATIONS + 1), self::reject(ErrorCode::INVALID_IMAGE)),
            // Each table over its own cap alone: entries of ipma and iloc for more items than allowed, properties.
            'avif/spoof-too-many-association-entries.avif' => self::committed(
                self::avifOfAssociations(AvifParser::MAX_ITEMS + 4, AvifParser::MAX_ITEMS + 1),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'avif/spoof-too-many-location-entries.avif' => self::committed(
                self::avifOfExtents(AvifParser::MAX_ITEMS + 1, AvifParser::MAX_ITEMS + 1),
                self::reject(ErrorCode::INVALID_IMAGE)
            ),
            'avif/valid-most-properties.avif' => self::committed(self::avifOfProperties(AvifParser::MAX_ENTRIES), self::accept(ImageFormat::AVIF, 8, 8)),
            'avif/spoof-too-many-properties.avif' => self::committed(self::avifOfProperties(AvifParser::MAX_ENTRIES + 1), self::reject(ErrorCode::INVALID_IMAGE)),
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
        $pixels = ImageBuilder::repeat("\x80", 64);
        $deflated = ImageBuilder::zlibStored(ImageBuilder::repeat("\0", 64));
        $page = [[256, 4, 1, 8], [257, 4, 1, 8], [258, 3, 1, pack('v', 8)], [259, 3, 1, pack('v', 1)], [262, 3, 1, pack('v', 1)],
            [273, 4, 1, 256], [277, 3, 1, pack('v', 1)], [278, 4, 1, 8], [279, 4, 1, 64]];
        $twoPages = ImageBuilder::tiffEntries($page, $pixels, 256);
        $twoPages = ImageBuilder::patch($twoPages, 8 + 2 + 12 * count($page), pack('V', strlen($twoPages)))
            . pack('v', 9) . pack('vvVV', 256, 4, 1, 16000) . pack('vvVV', 257, 4, 1, 16000) . pack('vvVv', 258, 3, 1, 8) . "\0\0"
            . pack('vvVv', 259, 3, 1, 1) . "\0\0" . pack('vvVv', 262, 3, 1, 1) . "\0\0" . pack('vvVV', 273, 4, 1, 256)
            . pack('vvVv', 277, 3, 1, 1) . "\0\0" . pack('vvVV', 278, 4, 1, 16000) . pack('vvVV', 279, 4, 1, 64) . pack('V', 0);

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
            'tiff/valid-orientation.tif' => self::committed(
                ImageBuilder::tiff(4, 4, false, 1, 1, [274 => [3, pack('v', 7)]]),
                self::accept(ImageFormat::TIFF, 4, 4, 1, ['orientation' => 7, 'trailing_data' => false])
            ),
            'tiff/valid-tiled.tif' => self::committed(self::tiledTiff(20, 16, 1, ImageBuilder::repeat("\x80", 256)), self::accept(ImageFormat::TIFF, 20, 20)),
            // libtiff and GDAL use 256 x 256 tiles by default, even for a smaller image.
            'tiff/valid-tiled-default-size.tif' => self::committed(
                self::tiledTiff(20, 256, 32773, ImageBuilder::repeat("\x81\x00", 512)),
                self::accept(ImageFormat::TIFF, 20, 20)
            ),
            // Only the first page is inspected, and the second makes the file trailing data: a backend decodes page 0.
            'tiff/valid-big-second-page.tif' => self::committed($twoPages, self::accept(ImageFormat::TIFF, 8, 8, 1, ['trailing_data' => true])),
            // The most entries and strips a file may have, then one too many.
            'tiff/valid-most-entries-and-strips.tif' => self::generated(
                self::stripedTiff(intdiv(TiffParser::MAX_DATA_BLOCKS, 16), TiffParser::MAX_ENTRIES),
                self::accept(ImageFormat::TIFF, 1, intdiv(TiffParser::MAX_DATA_BLOCKS, 16))
            ),
            'tiff/spoof-too-many-entries.tif' => self::generated(self::stripedTiff(1, TiffParser::MAX_ENTRIES + 1), self::reject(ErrorCode::INVALID_IMAGE, true)),
            'tiff/spoof-too-many-strips.tif' => self::generated(self::stripedTiff(intdiv(TiffParser::MAX_DATA_BLOCKS, 16) + 1, 10), self::reject(ErrorCode::INVALID_IMAGE)),
            'tiff/unsupported-old-jpeg.tif' => self::committed(ImageBuilder::tiff(4, 4, false, 1, 6), self::reject(ErrorCode::UNSUPPORTED_FORMAT)),
            'tiff/spoof-directory-offset.tif' => self::committed(ImageBuilder::patch($valid, 4, pack('V', 4)), self::reject(ErrorCode::INVALID_IMAGE)),
            'tiff/spoof-zero-width.tif' => self::committed(ImageBuilder::patch($tiny, 18, pack('V', 0)), self::reject(ErrorCode::INVALID_IMAGE)),
            // Decoders disagree on which copy of a tag wins.
            'tiff/spoof-duplicate-width.tif' => self::committed(
                ImageBuilder::tiffEntries(array_merge([[256, 4, 1, 16000], [257, 4, 1, 16000]], $page), $pixels, 256),
                self::reject(ErrorCode::INVALID_IMAGE, true)
            ),
            // An 8 x 8 image in one 30000 x 30000 Deflate tile: the decoder would allocate the tile.
            'tiff/spoof-tile-30000.tif' => self::committed(
                ImageBuilder::tiffEntries([[256, 4, 1, 8], [257, 4, 1, 8], [258, 3, 1, pack('v', 8)], [259, 3, 1, pack('v', 8)],
                    [262, 3, 1, pack('v', 1)], [277, 3, 1, pack('v', 1)], [322, 4, 1, 30000], [323, 4, 1, 30000], [324, 4, 1, 256],
                    [325, 4, 1, strlen($deflated)]], $deflated, 256),
                self::reject(ErrorCode::INVALID_IMAGE, true)
            ),
            'tiff/spoof-tile-size.tif' => self::committed(self::tiledTiff(20, 8, 1, ImageBuilder::repeat("\x80", 64)), self::reject(ErrorCode::INVALID_IMAGE, true)),
            // RowsPerStrip (the 8th entry) says 4, so 2 strips are needed and 1 is present.
            'tiff/spoof-strip-count.tif' => self::committed(ImageBuilder::patch($tiny, 10 + 12 * 7 + 8, pack('V', 4)), self::reject(ErrorCode::INVALID_IMAGE, true)),
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
     * An 8 x 8 GIF whose image follows $count empty comment extensions.
     */
    private static function gifOfExtensions(int $count): string
    {
        return ImageBuilder::gifScreen(8, 8) . ImageBuilder::repeat("\x21\xFE\x00", $count) . ImageBuilder::gifImage(0, 0, 8, 8) . "\x3B";
    }

    /**
     * $bmp with $gap inserted before its pixel data, which the file header points to.
     */
    private static function bmpWithGap(string $bmp, string $gap): string
    {
        $dataOffset = ImageBuilder::readUint32($bmp, 10, false);
        $bytes = substr($bmp, 0, $dataOffset) . $gap . substr($bmp, $dataOffset);

        return ImageBuilder::patch($bytes, 2, pack('VvvV', strlen($bytes), 0, 0, $dataOffset + strlen($gap)));
    }

    /**
     * A 4 x 4 V5 BMP whose embedded colour profile lies between the header and the pixel data, where some writers
     * store it.
     */
    private static function bmpProfileBeforePixels(): string
    {
        $profile = ImageBuilder::iccProfile();
        $bmp = ImageBuilder::bmp(4, 4, 32, 3, 124, false, 'MBED', $profile);
        $bmp = self::bmpWithGap((string) substr($bmp, 0, -strlen($profile)), $profile);

        // The profile offset (at 112 in the V5 header) counts from the start of the header, 124 bytes long.
        return ImageBuilder::patch($bmp, 14 + 112, pack('V', 124));
    }

    /**
     * A 4 x 4 V5 BMP with $gap between its pixel data and its embedded colour profile, which follows them.
     */
    private static function bmpGapBeforeProfile(string $gap): string
    {
        $profile = ImageBuilder::iccProfile();
        $bmp = ImageBuilder::bmp(4, 4, 32, 3, 124, false, 'MBED', $profile);
        $bytes = substr($bmp, 0, -strlen($profile)) . $gap . $profile;
        // The file size follows the signature; the profile offset (at 112 in the V5 header) counts from the start of
        // the header.
        $bytes = ImageBuilder::patch($bytes, 2, pack('V', strlen($bytes)));

        return ImageBuilder::patch($bytes, 14 + 112, pack('V', ImageBuilder::readUint32($bytes, 14 + 112, false) + strlen($gap)));
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
     * The sample whose ispe property says 8 x 8 and whose AV1 data codes 4000 x 4000 frames, with the 8 x 8 sequence
     * header of the still sample inserted before its own: decoders use the last sequence header they read.
     */
    private static function avifTwoSequenceHeaders(): string
    {
        $header = self::avifSequenceHeaderObu(ImageBuilder::avifStill());
        $large = ImageBuilder::avifReproducer('ispe-below-av1');
        $at = ImageBuilder::find($large, 'mdat') + 4 + 2;
        $bytes = substr($large, 0, $at) . $header . substr($large, $at);
        // The mdat box and the extent of the item (its length follows the offset in iloc) grow with the data.
        $bytes = ImageBuilder::addToUint32($bytes, ImageBuilder::find($bytes, 'mdat') - 4, strlen($header));

        return ImageBuilder::addToUint32($bytes, ImageBuilder::find($bytes, 'iloc') + 4 + 18, strlen($header));
    }

    /**
     * The sequence header OBU of the still sample or of a reproducer: their data starts with a temporal delimiter (2
     * bytes), then the sequence header OBU, made of a header byte, a size byte and the payload.
     */
    private static function avifSequenceHeaderObu(string $avif): string
    {
        $at = ImageBuilder::find($avif, 'mdat') + 4 + 2;

        return (string) substr($avif, $at, 2 + ord($avif[$at + 1]));
    }

    /**
     * The 8 x 8 still sample whose av1C property carries $obus as configuration OBUs.
     */
    private static function avifWithConfiguration(string $obus): string
    {
        // av1C holds 4 bytes of fields before its configuration OBUs.
        list($bytes, $delta) = self::avifWithBox('av1C', "\x81\x00\x0C\x00" . $obus, ['meta', 'iprp', 'ipco']);

        // iloc precedes iprp: the extent offset of the image item moves with mdat.
        return ImageBuilder::addToUint32($bytes, ImageBuilder::find($bytes, 'iloc') + 4 + 14, $delta);
    }

    /**
     * The 3-frame sequence sample whose av01 sample entry has an av1C box that carries $obus as configuration OBUs.
     */
    private static function avifSequenceWithConfiguration(string $obus): string
    {
        $sequence = ImageBuilder::avifSequence();
        $box = ImageBuilder::find($sequence, 'av1C', ImageBuilder::find($sequence, 'stsd')) - 4;
        $size = ImageBuilder::readUint32($sequence, $box);
        $payload = "\x81\x00\x0C\x00" . $obus;
        $delta = 8 + strlen($payload) - $size;
        $bytes = substr($sequence, 0, $box) . pack('N', 8 + strlen($payload)) . 'av1C' . $payload . substr($sequence, $box + $size);
        $at = 0;
        foreach (['moov', 'trak', 'mdia', 'minf', 'stbl', 'stsd'] as $type) {
            $at = ImageBuilder::find($bytes, $type, $at);
            $bytes = ImageBuilder::addToUint32($bytes, $at - 4, $delta);
        }
        // The av01 entry follows the type, the version and flags, and the entry count of stsd.
        $bytes = ImageBuilder::addToUint32($bytes, $at + 12, $delta);
        // mdat follows moov: the chunk offset of the track and the extent offset of the still item move with it.
        $bytes = ImageBuilder::addToUint32($bytes, ImageBuilder::find($bytes, 'stco') + 12, $delta);

        return ImageBuilder::addToUint32($bytes, ImageBuilder::find($bytes, 'iloc') + 4 + 14, $delta);
    }

    /**
     * The 8 x 8 still sample whose iinf box says it holds one entry, and holds a second infe box for another AV1 item.
     */
    private static function avifItemBeyondCount(): string
    {
        $still = ImageBuilder::avifStill();
        $iinf = ImageBuilder::find($still, 'iinf') - 4;
        $payload = (string) substr($still, $iinf + 8, ImageBuilder::readUint32($still, $iinf) - 8);
        // The infe box of item 1 follows the version and flags and the entry count; its version 2 item ID follows its
        // own header and version and flags.
        $infe = (string) substr($payload, 6);
        list($bytes, $delta) = self::avifWithBox('iinf', $payload . ImageBuilder::patch($infe, 12, pack('n', 2)), ['meta']);

        // iloc precedes iinf: the extent offset of the image item moves with mdat.
        return ImageBuilder::addToUint32($bytes, ImageBuilder::find($bytes, 'iloc') + 4 + 14, $delta);
    }

    /**
     * The 8 x 8 still sample whose AV1 data holds $sequenceHeaders copies of its sequence header, then its frame and
     * $padding empty padding OBUs.
     */
    private static function avifWithObus(int $sequenceHeaders, int $padding): string
    {
        $still = ImageBuilder::avifStill();
        $header = self::avifSequenceHeaderObu($still);
        // The data fills mdat: a temporal delimiter (2 bytes), the sequence header, the frame.
        $data = (string) substr($still, ImageBuilder::find($still, 'mdat') + 4);
        $data = substr($data, 0, 2) . ImageBuilder::repeat($header, $sequenceHeaders) . substr($data, 2 + strlen($header))
            . ImageBuilder::repeat("\x7A\x00", $padding);
        list($bytes) = self::avifWithBox('mdat', $data, []);

        // The extent length of the item follows its offset in iloc.
        return ImageBuilder::patch($bytes, ImageBuilder::find($bytes, 'iloc') + 4 + 18, pack('N', strlen($data)));
    }

    /**
     * The 3-frame sequence sample whose stsd box holds $count empty "mp4v" sample entries before its av01 entry:
     * decoders look for the AV1 entry, so it must not hide behind another.
     */
    private static function avifWithOtherSampleEntries(int $count): string
    {
        $sequence = ImageBuilder::avifSequence();
        $entry = ImageBuilder::repeat(pack('N', 16) . 'mp4v' . ImageBuilder::repeat("\0", 6) . pack('n', 1), $count);
        // The entries follow the type, the version and flags, and the entry count of stsd.
        $stsd = ImageBuilder::find($sequence, 'stsd');
        $bytes = substr($sequence, 0, $stsd + 12) . $entry . substr($sequence, $stsd + 12);
        $bytes = ImageBuilder::patch($bytes, $stsd + 8, pack('N', $count + 1));
        $at = 0;
        foreach (['moov', 'trak', 'mdia', 'minf', 'stbl', 'stsd'] as $type) {
            $at = ImageBuilder::find($bytes, $type, $at);
            $bytes = ImageBuilder::addToUint32($bytes, $at - 4, strlen($entry));
        }
        // mdat follows moov: the chunk offset of the track and the extent offset of the still item move with it.
        $bytes = ImageBuilder::addToUint32($bytes, ImageBuilder::find($bytes, 'stco') + 12, strlen($entry));

        return ImageBuilder::addToUint32($bytes, ImageBuilder::find($bytes, 'iloc') + 4 + 14, strlen($entry));
    }

    /**
     * The 8 x 8 still sample whose ipma box (wide indices) has $items entries, the most allowed by default, and $total
     * associations in all: the image item keeps its four properties, the other items share the rest, all numbered 0
     * (no property).
     */
    private static function avifOfAssociations(int $total, int $items = AvifParser::MAX_ITEMS): string
    {
        $payload = pack('NN', 1, $items) . pack('nC', 1, 4) . pack('nnnn', 1, 2, 0x8003, 4);
        for ($item = 2; $item <= $items; ++$item) {
            $count = intdiv($total - 4, $items - 1) + ($item - 2 < ($total - 4) % ($items - 1) ? 1 : 0);
            $payload .= pack('nC', $item, $count) . ImageBuilder::repeat("\0\0", $count);
        }
        list($bytes, $delta) = self::avifWithBox('ipma', $payload, ['meta', 'iprp']);

        // iloc precedes iprp: the extent offset of the image item is where the still sample has it.
        return ImageBuilder::addToUint32($bytes, ImageBuilder::find($bytes, 'iloc') + 4 + 14, $delta);
    }

    /**
     * The 8 x 8 still sample whose iloc box (version 1, every field 64 bits) has $items entries, the most allowed by
     * default, and $total extents in all: the image item keeps its single extent, the other items share the rest.
     */
    private static function avifOfExtents(int $total, int $items = AvifParser::MAX_ITEMS): string
    {
        $payload = pack('NCCn', 0x01000000, 0x88, 0x88, $items);
        // Item ID, construction method, data reference, base offset, extent count, then extent index, offset, length.
        $extentOffset = strlen($payload) + 2 + 2 + 2 + 8 + 2 + 8;
        $payload .= pack('nnnJnJJJ', 1, 0, 0, 0, 1, 0, 0, 39);
        for ($item = 2; $item <= $items; ++$item) {
            $count = intdiv($total - 1, $items - 1) + ($item - 2 < ($total - 1) % ($items - 1) ? 1 : 0);
            $payload .= pack('nnnJn', $item, 0, 0, 0, $count) . ImageBuilder::repeat("\0", 24 * $count);
        }
        list($bytes) = self::avifWithBox('iloc', $payload, ['meta']);

        return ImageBuilder::patch($bytes, ImageBuilder::find($bytes, 'iloc') + 4 + $extentOffset, pack('J', ImageBuilder::find($bytes, 'mdat') + 4));
    }

    /**
     * The 8 x 8 still sample whose ipco box holds $count properties: its own, then empty free boxes.
     */
    private static function avifOfProperties(int $count): string
    {
        $still = ImageBuilder::avifStill();
        $box = ImageBuilder::find($still, 'ipco') - 4;
        $payload = (string) substr($still, $box + 8, ImageBuilder::readUint32($still, $box) - 8);
        $own = 0;
        for ($at = 0; $at < strlen($payload); $at += ImageBuilder::readUint32($payload, $at)) {
            ++$own;
        }
        list($bytes, $delta) = self::avifWithBox('ipco', $payload . ImageBuilder::repeat(pack('N', 8) . 'free', $count - $own), ['meta', 'iprp']);

        // iloc precedes iprp: the extent offset of the image item is where the still sample has it.
        return ImageBuilder::addToUint32($bytes, ImageBuilder::find($bytes, 'iloc') + 4 + 14, $delta);
    }

    /**
     * An 8-bit (or with $rle4 4-bit) run-length encoded BMP whose pixel data is $data, which its compressed size
     * covers.
     */
    private static function bmpOfRuns(int $width, int $height, string $data, bool $rle4 = false): string
    {
        $bmp = $rle4 ? ImageBuilder::bmp($width, $height, 4, 2) : ImageBuilder::bmp($width, $height, 8, 1);
        $bytes = substr($bmp, 0, ImageBuilder::readUint32($bmp, 10, false)) . $data;

        // The file size (at 2) and the compressed size of the info header (at 34) grow with the data.
        return ImageBuilder::patch(ImageBuilder::patch($bytes, 2, pack('V', strlen($bytes))), 34, pack('V', strlen($data)));
    }

    /**
     * The 8 x 8 still sample with the payload of its $type box replaced by $payload, and the boxes in $parents, which
     * enclose it, grown to match.
     *
     * @param list<string> $parents
     *
     * @return array{0: string, 1: int} the bytes, and how far the boxes after it (mdat) moved
     */
    private static function avifWithBox(string $type, string $payload, array $parents): array
    {
        $still = ImageBuilder::avifStill();
        $box = ImageBuilder::find($still, $type) - 4;
        $size = ImageBuilder::readUint32($still, $box);
        $delta = 8 + strlen($payload) - $size;
        $bytes = substr($still, 0, $box) . pack('N', 8 + strlen($payload)) . $type . $payload . substr($still, $box + $size);
        foreach ($parents as $parent) {
            $bytes = ImageBuilder::addToUint32($bytes, ImageBuilder::find($bytes, $parent) - 4, $delta);
        }

        return [$bytes, $delta];
    }

    /**
     * A square greyscale TIFF of $size pixels in tiles of $tileSize, each holding the bytes $tile.
     */
    private static function tiledTiff(int $size, int $tileSize, int $compression, string $tile): string
    {
        $tilesPerSide = intdiv($size + $tileSize - 1, $tileSize);
        $tiles = $tilesPerSide * $tilesPerSide;
        $entries = [[256, 3, 1, pack('v', $size)], [257, 3, 1, pack('v', $size)], [258, 3, 1, pack('v', 8)],
            [259, 3, 1, pack('v', $compression)], [262, 3, 1, pack('v', 1)], [277, 3, 1, pack('v', 1)],
            [322, 3, 1, pack('v', $tileSize)], [323, 3, 1, pack('v', $tileSize)]];
        // The tile offsets and byte counts follow the directory (10 entries), in line when there is one tile.
        $dataOffset = 8 + 2 + 12 * 10 + 4;
        $firstTile = $dataOffset + ($tiles > 1 ? 8 * $tiles : 0);
        $offsets = '';
        for ($index = 0; $index < $tiles; ++$index) {
            $offsets .= pack('V', $firstTile + $index * strlen($tile));
        }
        $counts = ImageBuilder::repeat(pack('V', strlen($tile)), $tiles);
        $entries[] = [324, 4, $tiles, $tiles > 1 ? $dataOffset : $offsets];
        $entries[] = [325, 4, $tiles, $tiles > 1 ? $dataOffset + 4 * $tiles : $counts];

        return ImageBuilder::tiffEntries($entries, ($tiles > 1 ? $offsets . $counts : '') . ImageBuilder::repeat($tile, $tiles), $dataOffset);
    }

    /**
     * A 1 x $height TIFF of 16 separate sample planes stored in strips of one row: 16 x $height strips of one byte.
     * Unknown tags fill the directory up to $entries entries.
     */
    private static function stripedTiff(int $height, int $entries): string
    {
        $strips = 16 * $height;
        $dataOffset = 8 + 2 + 12 * $entries + 4;
        $offsetsAt = $dataOffset + 32;
        $countsAt = $offsetsAt + 4 * $strips;
        $pixelsAt = $countsAt + 4 * $strips;
        $directory = [[256, 4, 1, 1], [257, 4, 1, $height], [258, 3, 16, $dataOffset], [259, 3, 1, pack('v', 1)],
            [262, 3, 1, pack('v', 1)], [273, 4, $strips, $offsetsAt], [277, 3, 1, pack('v', 16)], [278, 4, 1, 1],
            [279, 4, $strips, $countsAt], [284, 3, 1, pack('v', 2)]];
        for ($tag = 40000; count($directory) < $entries; ++$tag) {
            $directory[] = [$tag, 3, 1, pack('v', 0)];
        }
        $offsets = '';
        for ($strip = 0; $strip < $strips; ++$strip) {
            $offsets .= pack('V', $pixelsAt + $strip);
        }

        return ImageBuilder::tiffEntries(
            $directory,
            ImageBuilder::repeat(pack('v', 8), 16) . $offsets . ImageBuilder::repeat(pack('V', 1), $strips) . ImageBuilder::repeat("\0", $strips),
            $dataOffset
        );
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
