<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation\Parser;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\Limits;

/**
 * JPEG header parser: walks the marker segments from SOI to the first scan (SOS), reads the frame header (SOFn) and
 * notes the metadata segments, skipping every segment payload by seeking.
 *
 * - At most MAX_SEGMENTS segments are accepted before the first scan, and at most MAX_FILL_BYTES fill bytes before a
 *   marker: a flood of EXIF or other segments is refused instead of walked.
 * - Exactly one frame header must precede the first scan, of a process 8-bit libjpeg decodes: baseline, extended
 *   sequential or progressive, Huffman or arithmetic coded (SOF0, SOF1, SOF2, SOF9, SOF10), with a sample precision
 *   of 8. Lossless (SOF3, SOF11) and hierarchical (SOF5 to SOF7, SOF13 to SOF15) processes and 12 or 16-bit
 *   precision are refused as unsupported. Width and height from 1 (a zero height announcing a later DNL marker is
 *   refused), 1 to 4 components with sampling factors from 1 to 4; four components are a CMYK or YCCK image
 *   (ImageInfo::VARIANT_JPEG_CMYK). The dimensions are checked against the limits as soon as the frame header is
 *   read.
 * - Every segment must fit in the file; a truncated header is refused. Once the first scan is reached, the image
 *   must end with an EOI marker (FF D9). The entropy-coded data of a scan holds no marker but RSTn (FF D0 to FF D7)
 *   and stuffed FF 00 bytes: the parser searches it for the next marker, without decoding it, and skips the segments
 *   that come between scans (tables, further scan headers, APPn and comments, which decoders read wherever they
 *   are), up to MAX_SEGMENTS segments in all. An image of more than MAX_SCANS scans is refused as unsupported. A file
 *   without EOI is truncated; bytes after the EOI (the video of a motion photo, an appended archive) are trailing data.
 * - The search runs PCRE on chunks of the scan data: with the JIT compiler (pcre.jit=1, the default) it takes about
 *   30 ms for 20 MiB on PHP 7.2, and several times longer without it.
 *
 * Metadata, before or after the first scan: APP1 "Exif" is EXIF, APP2 "ICC_PROFILE" is the colour profile; APP0 JFIF
 * and APP14 Adobe carry decoding parameters, not metadata; every other APPn segment (XMP, IPTC, MPF, FlashPix...) and
 * every comment is other metadata. The orientation comes from the first EXIF segment.
 *
 * @internal
 */
final class JpegParser implements ParserInterface
{
    /**
     * Largest number of marker segments, before the first scan and after it. Real files have a few dozen at most, even
     * with an ICC profile split over many APP2 segments, extended XMP or the scans of a progressive image.
     */
    public const MAX_SEGMENTS = 1024;

    /**
     * Largest number of scans. Decoders go over the whole image once per scan, so a small image of many scans takes
     * long to decode; progressive encoders write about ten.
     */
    public const MAX_SCANS = 100;

    /**
     * Largest number of FF fill bytes before one marker.
     */
    private const MAX_FILL_BYTES = 64;

    /**
     * Frame header markers of the processes an 8-bit libjpeg decodes: baseline, extended sequential and progressive,
     * with Huffman (C0 to C2) or arithmetic (C9, CA) coding.
     *
     * @var list<int>
     */
    private const SUPPORTED_FRAMES = [0xC0, 0xC1, 0xC2, 0xC9, 0xCA];

    /**
     * Sample precisions a frame header may declare; only 8 is supported.
     *
     * @var list<int>
     */
    private const PRECISIONS = [8, 12, 16];

    /**
     * Entropy-coded data up to the next marker: bytes other than FF, and FF bytes followed by a stuffed zero or a
     * restart marker (RST0 to RST7), which decoders read as data.
     */
    private const SCAN_DATA_PATTERN = '/\G(?:[^\xFF]++|\xFF++[\x00\xD0-\xD7])*+/';

    /**
     * Bytes read by the first step of a marker search, each next step reading twice as many up to SCAN_CHUNK_SIZE: the
     * next marker usually follows a segment at once.
     */
    private const FIRST_SCAN_CHUNK_SIZE = 256;

    private const SCAN_CHUNK_SIZE = 65536;

    private const MARKER_SOS = 0xDA;
    private const MARKER_EOI = 0xD9;
    private const MARKER_COM = 0xFE;

    /**
     * @var bool
     */
    private $iccProfile = false;

    /**
     * @var bool
     */
    private $exif = false;

    /**
     * @var bool
     */
    private $otherMetadata = false;

    /**
     * @var int
     */
    private $orientation = ImageInfo::ORIENTATION_NORMAL;

    public function parse(ByteReader $reader, Limits $limits): ImageInfo
    {
        $reader->seek(0);
        if ($reader->read(2) !== "\xFF\xD8") {
            throw self::invalid('the file does not start with an SOI marker');
        }

        $frame = null;
        $this->iccProfile = false;
        $this->exif = false;
        $this->otherMetadata = false;
        $this->orientation = ImageInfo::ORIENTATION_NORMAL;

        for ($segments = 1; ; ++$segments) {
            if ($segments > self::MAX_SEGMENTS) {
                throw self::tooManySegments();
            }
            $marker = self::readMarker($reader);
            if ($marker === self::MARKER_EOI) {
                throw self::invalid('the image ends before its first scan');
            }
            if (self::isStandalone($marker)) {
                throw self::invalid('a marker without payload appears before the first scan');
            }

            $length = $reader->readUint16Be();
            if ($length < 2) {
                throw self::invalid('a segment has a length below 2');
            }
            $payloadLength = $length - 2;
            $payloadEnd = $reader->getPosition() + $payloadLength;
            if ($payloadLength > $reader->getRemaining()) {
                throw ByteReader::truncated();
            }

            if (self::isFrameHeader($marker)) {
                if ($frame !== null) {
                    throw self::invalid('the image has more than one frame header');
                }
                if (!in_array($marker, self::SUPPORTED_FRAMES, true)) {
                    throw new UnsupportedFormatException('Lossless and hierarchical JPEG images are not supported.');
                }
                $frame = self::readFrameHeader($reader, $payloadLength);
                $limits->assertImage($frame[0], $frame[1], 1);
            } elseif ($marker === self::MARKER_SOS) {
                if ($frame === null) {
                    throw self::invalid('a scan precedes the frame header');
                }
                self::checkScanHeader($reader, $payloadLength);
                $endOfImage = $this->findEndOfImage($reader, $payloadEnd, $segments);

                return (new ImageInfo(ImageFormat::JPEG, $frame[0], $frame[1], 1, $reader->getSize()))
                    ->withMetadata($this->iccProfile, $this->exif, $this->otherMetadata)
                    ->withOrientation($this->orientation)
                    ->withTrailingData($endOfImage + 2 < $reader->getSize())
                    ->withVariants($frame[2] === 4 ? [ImageInfo::VARIANT_JPEG_CMYK] : []);
            } else {
                $this->readMetadata($reader, $marker, $payloadLength, $payloadEnd);
            }
            $reader->seek($payloadEnd);
        }
    }

    /**
     * Offset of the EOI marker: searches the entropy-coded data after a scan header for the next marker, and skips the
     * segments between scans, noting their metadata.
     *
     * @param int $from     offset of the entropy-coded data of the first scan
     * @param int $segments segments walked before it
     *
     * @throws InvalidImageException
     * @throws UnsupportedFormatException
     */
    private function findEndOfImage(ByteReader $reader, int $from, int $segments): int
    {
        $position = $from;
        $scans = 1;
        while (true) {
            $markerStart = self::findMarker($reader, $position);
            if ($markerStart < 0) {
                throw ByteReader::truncated();
            }
            $reader->seek($markerStart + 1);
            $marker = $reader->readUint8();
            if ($marker === self::MARKER_EOI) {
                return $markerStart;
            }
            if (++$segments > self::MAX_SEGMENTS) {
                throw self::tooManySegments();
            }
            if ($marker === self::MARKER_SOS && ++$scans > self::MAX_SCANS) {
                throw new UnsupportedFormatException(sprintf('JPEG images of more than %d scans are not supported.', self::MAX_SCANS));
            }
            if (self::isStandalone($marker)) {
                $position = $markerStart + 2;
                continue;
            }

            $length = $reader->readUint16Be();
            if ($length < 2) {
                throw self::invalid('a segment has a length below 2');
            }
            if ($length - 2 > $reader->getRemaining()) {
                throw ByteReader::truncated();
            }
            $position = $markerStart + 2 + $length;
            $this->readMetadata($reader, $marker, $length - 2, $position);
        }
    }

    /**
     * Offset of the FF byte that the code of the next marker follows (the last one when fill bytes precede it), from
     * $from, or -1. Reads the bytes in growing chunks, without keeping them in memory.
     *
     * @throws InvalidImageException
     */
    private static function findMarker(ByteReader $reader, int $from): int
    {
        $size = $reader->getSize();
        $reader->seek($from);
        $chunkStart = $from;
        $chunk = '';
        $chunkSize = self::FIRST_SCAN_CHUNK_SIZE;
        while ($reader->getPosition() < $size) {
            $chunk .= $reader->read(min($chunkSize, $size - $reader->getPosition()));
            $chunkSize = min(2 * $chunkSize, self::SCAN_CHUNK_SIZE);
            if (preg_match(self::SCAN_DATA_PATTERN, $chunk, $data) !== 1) {
                throw self::invalid('the scan data cannot be searched');
            }
            // The data stops at an FF byte, or a run of fill bytes, that a marker code follows.
            $at = strlen($data[0]);
            $at += strspn($chunk, "\xFF", $at);
            if ($at < strlen($chunk)) {
                return $chunkStart + $at - 1;
            }
            // The chunk ends with data, or with FF bytes whose next byte is in the next chunk: the last one is kept.
            $kept = $chunk[strlen($chunk) - 1] === "\xFF" ? 1 : 0;
            $chunkStart += strlen($chunk) - $kept;
            $chunk = $kept === 1 ? "\xFF" : '';
        }

        return -1;
    }

    /**
     * Notes the metadata of an APPn or comment segment; does nothing for other segments.
     *
     * @param int $payloadEnd offset of the byte after the payload, which starts at the position
     *
     * @throws InvalidImageException
     */
    private function readMetadata(ByteReader $reader, int $marker, int $payloadLength, int $payloadEnd): void
    {
        if (($marker < 0xE0 || $marker > 0xEF) && $marker !== self::MARKER_COM) {
            return;
        }
        $identifier = $reader->read(min($payloadLength, 12));
        if ($marker === 0xE1 && strncmp($identifier, "Exif\0", 5) === 0) {
            if (!$this->exif) {
                $tiffStart = $payloadEnd - $payloadLength + 6;
                $this->orientation = ExifOrientation::read($reader, $tiffStart, $payloadEnd - $tiffStart);
            }
            $this->exif = true;
        } elseif ($marker === 0xE2 && $identifier === "ICC_PROFILE\0") {
            $this->iccProfile = true;
        } elseif (!($marker === 0xE0 && strncmp($identifier, "JFIF\0", 5) === 0) && !($marker === 0xEE && strncmp($identifier, 'Adobe', 5) === 0)) {
            $this->otherMetadata = true;
        }
    }

    /**
     * Reads the next marker: an FF byte, optional FF fill bytes, then the marker code.
     *
     * @throws InvalidImageException
     */
    private static function readMarker(ByteReader $reader): int
    {
        if ($reader->readUint8() !== 0xFF) {
            throw self::invalid('a marker was expected between two segments');
        }
        $marker = $reader->readUint8();
        for ($fill = 0; $marker === 0xFF; ++$fill) {
            if ($fill >= self::MAX_FILL_BYTES) {
                throw self::invalid('too many fill bytes precede a marker');
            }
            $marker = $reader->readUint8();
        }

        return $marker;
    }

    /**
     * Markers without a length: TEM, RSTn, SOI and the stuffed zero. EOI is handled by the caller.
     */
    private static function isStandalone(int $marker): bool
    {
        return $marker === 0x00 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8);
    }

    /**
     * SOF0 to SOF15, except DHT (C4), JPG (C8) and DAC (CC).
     */
    private static function isFrameHeader(int $marker): bool
    {
        return $marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC;
    }

    /**
     * @throws InvalidImageException
     * @throws UnsupportedFormatException
     *
     * @return array{0: int, 1: int, 2: int} width, height and number of components
     */
    private static function readFrameHeader(ByteReader $reader, int $payloadLength): array
    {
        if ($payloadLength < 6) {
            throw self::invalid('the frame header is too short');
        }
        $precision = $reader->readUint8();
        $height = $reader->readUint16Be();
        $width = $reader->readUint16Be();
        $components = $reader->readUint8();

        if (!in_array($precision, self::PRECISIONS, true)) {
            throw self::invalid('the frame header declares an invalid sample precision');
        }
        if ($precision !== 8) {
            throw new UnsupportedFormatException('JPEG images of 12 or 16 bits per sample are not supported.');
        }
        if ($width === 0 || $height === 0) {
            throw self::invalid('the frame header declares a zero width or height');
        }
        if ($components < 1 || $components > 4 || $payloadLength !== 6 + 3 * $components) {
            throw self::invalid('the frame header has an invalid component count or length');
        }
        for ($index = 0; $index < $components; ++$index) {
            $component = $reader->read(3);
            $horizontal = ord($component[1]) >> 4;
            $vertical = ord($component[1]) & 0x0F;
            if ($horizontal < 1 || $horizontal > 4 || $vertical < 1 || $vertical > 4 || ord($component[2]) > 3) {
                throw self::invalid('the frame header has invalid component parameters');
            }
        }

        return [$width, $height, $components];
    }

    /**
     * @throws InvalidImageException
     */
    private static function checkScanHeader(ByteReader $reader, int $payloadLength): void
    {
        if ($payloadLength < 1) {
            throw self::invalid('the scan header is too short');
        }
        $components = $reader->readUint8();
        if ($components < 1 || $components > 4 || $payloadLength !== 4 + 2 * $components) {
            throw self::invalid('the scan header has an invalid component count or length');
        }
    }

    private static function tooManySegments(): InvalidImageException
    {
        return self::invalid(sprintf('the image has more than %d segments', self::MAX_SEGMENTS));
    }

    private static function invalid(string $problem): InvalidImageException
    {
        return new InvalidImageException('Invalid JPEG: ' . $problem . '.');
    }
}
