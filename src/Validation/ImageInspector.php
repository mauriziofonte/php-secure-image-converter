<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\Parser\AvifParser;
use Mfonte\SecureImageConverter\Validation\Parser\BmpParser;
use Mfonte\SecureImageConverter\Validation\Parser\ByteReader;
use Mfonte\SecureImageConverter\Validation\Parser\GifParser;
use Mfonte\SecureImageConverter\Validation\Parser\JpegParser;
use Mfonte\SecureImageConverter\Validation\Parser\ParserInterface;
use Mfonte\SecureImageConverter\Validation\Parser\PngParser;
use Mfonte\SecureImageConverter\Validation\Parser\TiffParser;
use Mfonte\SecureImageConverter\Validation\Parser\WebpParser;

/**
 * First line of defence against untrusted images: identifies the format from the content, refuses formats that are
 * not enabled, and reads the header to check dimensions and frame count against the limits, before any decoder runs.
 *
 * Nothing here decodes an image: no GD, Imagick, vips or other decoder is called, no LZW, Deflate, Huffman, VP8 or
 * AV1 data is expanded, and no external program is started. Each format has a header parser that reads the few
 * structures holding dimensions, frames and metadata markers and skips everything else; every loop is bounded by a
 * constant or by the file size, and the file size itself is checked against max_bytes before anything is read. The
 * parsers check the dimensions as soon as the header declares them and the frame count as soon as it exceeds the
 * limits, so a dimension, decompression or frame bomb is refused after reading a few bytes. Gaps remain for AVIF: the
 * sequence headers (in the item or sample data and in the av1C configuration OBUs) bound the AV1 frame size, but a
 * frame header can override it (see Av1SequenceHeader), and only the first sample of an image sequence is read, so
 * AVIF input, which is opt-in, also relies on the decoder and process limits of the conversion backend. See each
 * parser in the Parser namespace for the exact checks.
 *
 * Input formats: JPEG, PNG, GIF and BMP are enabled by default (DEFAULT_INPUT_FORMATS); WEBP, AVIF, TIFF and SVG are
 * refused unless enabled explicitly. SVG is identified but never accepted: this library does not rasterise SVG, so
 * an SVG input is refused with an UnsupportedFormatException even when SVG is enabled, and getInputFormats() never
 * lists it.
 *
 * Inspect what you decode: inspect() opens the path and checks that it is a regular file, but the file can change once
 * inspect() returns, and a decoder that opens the same path later may read other bytes (time of check to time of use).
 * A consumer that decodes the image must inspect and decode the same private copy of the input, one that no other
 * process can write (such as a temporary file it created itself), or call inspectStream() on the very stream it then
 * decodes. The ImageInfo flags give the rest of what a consumer needs: hasTrailingData() reports bytes that decoders
 * skip (an appended archive or script, and the places listed in ImageInfo), so a consumer must re-encode the image
 * rather than pass the original bytes through; getOrientation() reports the EXIF or AVIF orientation the decoder will
 * not apply by itself. Only the first image is inspected (the first page of a TIFF, the first image of a BMP, the
 * primary item of a still AVIF): a backend must decode it alone.
 *
 * Errors (see Contract\Exception\ErrorCode): the file is above max_bytes, LimitExceededException (file_too_large);
 * its format cannot be identified, is not enabled, is SVG or is an unsupported variant, UnsupportedFormatException
 * (unsupported_format); its header is malformed, truncated, inconsistent or spoofed, InvalidImageException
 * (invalid_image); a dimension, pixel or frame limit is exceeded, LimitExceededException (image_too_large). Messages
 * never quote the file content or its path.
 */
final class ImageInspector implements ImageInspectorInterface
{
    /**
     * Input formats enabled when none are given: JPEG, PNG, GIF and BMP.
     *
     * @var list<string>
     */
    public const DEFAULT_INPUT_FORMATS = [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::BMP];

    /**
     * @var Limits
     */
    private $limits;

    /**
     * Enabled formats, in canonical order.
     *
     * @var list<string>
     */
    private $enabledFormats;

    /**
     * @var FormatSniffer
     */
    private $sniffer;

    /**
     * Size of the largest file whose structures the parsers walk in full (see forOutput()), or null for input files,
     * whose walks stop at the constants of the parsers.
     *
     * @var int|null
     */
    private $outputBytes;

    /**
     * @param Limits|null       $limits       limits to enforce; the defaults of Limits when null
     * @param list<string>|null $inputFormats ImageFormat values to accept; DEFAULT_INPUT_FORMATS when null. An empty
     *                                        list refuses every input.
     *
     * @throws InvalidArgumentException when a format is unknown or listed twice
     */
    public function __construct(?Limits $limits = null, ?array $inputFormats = null)
    {
        $this->limits = $limits === null ? new Limits() : $limits;
        $this->enabledFormats = self::checkFormats($inputFormats === null ? self::DEFAULT_INPUT_FORMATS : $inputFormats);
        $this->sniffer = new FormatSniffer();
    }

    /**
     * An inspector for the files a backend writes, of up to $maxBytes bytes: every dimension, pixel and frame limit
     * at Capabilities::LIMIT_VALUE_MAX (the caller compares the output with what it expects), max_bytes at $maxBytes,
     * and walks bounded by the file size instead of the constants that bound untrusted input. Encoders legitimately
     * write more structures than an input may hold: vips splits PNG data into 8 KiB IDAT chunks, so a PNG output
     * above 134 MB has more than PngParser::MAX_CHUNKS chunks. A PNG chunk takes at least 12 bytes, a WEBP chunk 8 and
     * each unit of GifParser::MAX_BLOCKS at least one byte per GifParser::BLOCK_COST units, so these walks stay
     * linear in the file size, which $maxBytes bounds. The other parsers' constants already exceed what any output
     * of OUTPUT_DIMENSION_MAX pixels per side holds (a TIFF of at most 16383 rows has at most 16383 strips per plane
     * in the layouts the backends write). A TIFF output may hold TiffParser::OUTPUT_EXTRA_SAMPLES samples per pixel
     * more than an input.
     *
     * @param list<string> $formats  ImageFormat values to accept
     * @param int          $maxBytes largest accepted file size, from 1 to Capabilities::LIMIT_VALUE_MAX (clamped)
     *
     * @throws InvalidArgumentException when a format is unknown or listed twice
     */
    public static function forOutput(array $formats, int $maxBytes): self
    {
        $limits = new Limits();
        foreach (Capabilities::limitNames() as $name) {
            $limits = $limits->withLimit($name, Capabilities::LIMIT_VALUE_MAX);
        }
        $maxBytes = max(1, min(Capabilities::LIMIT_VALUE_MAX, $maxBytes));
        $inspector = new self($limits->withMaxBytes($maxBytes), $formats);
        $inspector->outputBytes = $maxBytes;

        return $inspector;
    }

    /**
     * The limits this inspector enforces.
     */
    public function getLimits(): Limits
    {
        return $this->limits;
    }

    /**
     * Formats inspect() can accept, in canonical order: the enabled formats except SVG, which is never accepted. This
     * is the list a converter publishes as Capabilities input formats.
     *
     * @return list<string>
     */
    public function getInputFormats(): array
    {
        return array_values(array_diff($this->enabledFormats, [ImageFormat::SVG]));
    }

    /**
     * Inspects the image file at a local path.
     *
     * The path must name a regular file itself, not a symbolic link, a directory, a FIFO, a socket or a device: it is
     * checked with lstat() before it is opened, opened without blocking (so a FIFO put in its place cannot stall the
     * call), and checked again with fstat() on the open handle, which must be the same regular file (same device and
     * inode) as the one lstat() saw. Resolve a symbolic link with realpath() first if it is trusted.
     *
     * The check covers this call only: decode the bytes inspected here, not the path again (see the class summary).
     *
     * @param string $path path of a regular, readable local file; URLs and stream wrappers ("scheme:..." with a scheme
     *                     of two characters or more, such as phar:// or data:) are refused: use inspectStream()
     *
     * @throws InvalidArgumentException   when the path is not a readable regular local file, or changed while opened
     * @throws LimitExceededException     when the file or the image exceeds a limit
     * @throws UnsupportedFormatException when the format is unidentified, not enabled, SVG or an unsupported variant
     * @throws InvalidImageException      when the header is malformed, truncated, inconsistent or spoofed
     */
    public function inspect(string $path): ImageInfo
    {
        if ($path === '' || strpos($path, "\0") !== false || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]+:/', $path) === 1) {
            throw new InvalidArgumentException('The input path must be a local file path, not a URL or stream wrapper.');
        }
        clearstatcache(true, $path);
        $link = @lstat($path);
        if (!is_array($link) || !self::isRegularFile($link['mode'])) {
            throw new InvalidArgumentException('The input path is not a regular file.');
        }
        // "n" opens with O_NONBLOCK where the platform has it: opening a FIFO swapped in after lstat() returns at once.
        $handle = @fopen($path, 'rbn');
        if ($handle === false) {
            throw new InvalidArgumentException('The input path is not a readable file.');
        }

        try {
            $stat = fstat($handle);
            if (!is_array($stat) || !self::isRegularFile($stat['mode'])
                || $stat['dev'] !== $link['dev'] || $stat['ino'] !== $link['ino']
            ) {
                throw new InvalidArgumentException('The input path changed while it was being opened.');
            }
            stream_set_blocking($handle, true);

            return $this->inspectReader(new ByteReader($handle, $stat['size']));
        } finally {
            fclose($handle);
        }
    }

    /**
     * Inspects the image held by a stream, from its offset 0 to the size reported by fstat(). The stream is left
     * open, at an unspecified position. Decode from this same stream (rewound), and only if nothing else can write
     * to what it reads: the result describes the bytes read now.
     *
     * @param resource $stream a seekable stream whose fstat() reports its size
     *
     * @throws InvalidArgumentException   when the argument is not a seekable stream of known size
     * @throws LimitExceededException     when the stream or the image exceeds a limit
     * @throws UnsupportedFormatException when the format is unidentified, not enabled, SVG or an unsupported variant
     * @throws InvalidImageException      when the header is malformed, truncated, inconsistent or spoofed
     */
    public function inspectStream($stream): ImageInfo
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('ImageInspector: expected an open stream resource.');
        }
        if (stream_get_meta_data($stream)['seekable'] !== true) {
            throw new InvalidArgumentException('ImageInspector: the stream is not seekable.');
        }
        $stat = fstat($stream);
        if (!is_array($stat)) {
            throw new InvalidArgumentException('ImageInspector: the size of the stream is unknown.');
        }

        return $this->inspectReader(new ByteReader($stream, $stat['size']));
    }

    /**
     * @throws LimitExceededException
     * @throws UnsupportedFormatException
     * @throws InvalidImageException
     */
    private function inspectReader(ByteReader $reader): ImageInfo
    {
        $this->limits->assertFileSize($reader->getSize());

        $format = $this->sniffer->detect($reader->readHead(FormatSniffer::HEAD_LENGTH));
        if ($format === null) {
            throw new UnsupportedFormatException('The input format could not be identified from its content.');
        }
        if (!in_array($format, $this->enabledFormats, true)) {
            throw new UnsupportedFormatException(sprintf('The input format %s is not enabled.', $format));
        }

        return $this->parserFor($format)->parse($reader, $this->limits);
    }

    /**
     * Whether a stat() mode is that of a regular file (S_IFREG).
     */
    private static function isRegularFile(int $mode): bool
    {
        return ($mode & 0170000) === 0100000;
    }

    /**
     * @param array<mixed> $formats
     *
     * @throws InvalidArgumentException
     *
     * @return list<string> the formats in canonical order
     */
    private static function checkFormats(array $formats): array
    {
        foreach ($formats as $format) {
            if (!is_string($format) || !ImageFormat::isValid($format)) {
                throw new InvalidArgumentException(sprintf(
                    'ImageInspector: the input formats must be values among: %s.',
                    implode(', ', ImageFormat::all())
                ));
            }
        }
        if (count(array_unique($formats)) !== count($formats)) {
            throw new InvalidArgumentException('ImageInspector: the input formats contain duplicates.');
        }

        return array_values(array_intersect(ImageFormat::all(), $formats));
    }

    /**
     * @throws UnsupportedFormatException
     */
    private function parserFor(string $format): ParserInterface
    {
        // A file of n bytes holds at most n / 12 PNG chunks, n / 8 WEBP chunks and BLOCK_COST x n GIF units.
        $bytes = $this->outputBytes;
        switch ($format) {
            case ImageFormat::JPEG:
                return new JpegParser();
            case ImageFormat::PNG:
                return new PngParser($bytes !== null ? intdiv($bytes, 12) + 1 : PngParser::MAX_CHUNKS);
            case ImageFormat::GIF:
                return new GifParser($bytes !== null ? GifParser::BLOCK_COST * $bytes + 1 : GifParser::MAX_BLOCKS);
            case ImageFormat::BMP:
                return new BmpParser();
            case ImageFormat::WEBP:
                return new WebpParser($bytes !== null ? intdiv($bytes, 8) + 1 : WebpParser::MAX_CHUNKS);
            case ImageFormat::AVIF:
                return new AvifParser();
            case ImageFormat::TIFF:
                return new TiffParser($bytes !== null ? TiffParser::MAX_SAMPLES + TiffParser::OUTPUT_EXTRA_SAMPLES : TiffParser::MAX_SAMPLES);
            default:
                throw new UnsupportedFormatException('SVG input is not supported: this library never rasterises SVG.');
        }
    }
}
