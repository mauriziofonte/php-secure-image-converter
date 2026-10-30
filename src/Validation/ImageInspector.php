<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation;

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
 * limits, so a dimension, decompression or frame bomb is refused after reading a few bytes. See each parser in the
 * Parser namespace for the exact checks.
 *
 * Input formats: JPEG, PNG, GIF and BMP are enabled by default (DEFAULT_INPUT_FORMATS); WEBP, AVIF, TIFF and SVG are
 * refused unless enabled explicitly. SVG is identified but never accepted: this library does not rasterise SVG, so
 * an SVG input is refused with an UnsupportedFormatException even when SVG is enabled, and getInputFormats() never
 * lists it.
 *
 * Errors (see Contract\Exception\ErrorCode): the file is above max_bytes, LimitExceededException (file_too_large);
 * its format cannot be identified, is not enabled, is SVG or is an unsupported variant, UnsupportedFormatException
 * (unsupported_format); its header is malformed, truncated, inconsistent or spoofed, InvalidImageException
 * (invalid_image); a dimension, pixel or frame limit is exceeded, LimitExceededException (image_too_large). Messages
 * never quote the file content or its path.
 */
final class ImageInspector
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
     * @param string $path path of a regular, readable local file; URLs and stream wrappers ("scheme:..." with a scheme
     *                     of two characters or more, such as phar:// or data:) are refused: use inspectStream()
     *
     * @throws InvalidArgumentException   when the path is not a readable regular local file
     * @throws LimitExceededException     when the file or the image exceeds a limit
     * @throws UnsupportedFormatException when the format is unidentified, not enabled, SVG or an unsupported variant
     * @throws InvalidImageException      when the header is malformed, truncated, inconsistent or spoofed
     */
    public function inspect(string $path): ImageInfo
    {
        if ($path === '' || strpos($path, "\0") !== false || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]+:/', $path) === 1) {
            throw new InvalidArgumentException('The input path must be a local file path, not a URL or stream wrapper.');
        }
        $handle = is_file($path) && is_readable($path) ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new InvalidArgumentException('The input path is not a readable file.');
        }

        try {
            $stat = fstat($handle);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
                throw new InvalidArgumentException('The input path is not a regular file.');
            }

            return $this->inspectReader(new ByteReader($handle, $stat['size']));
        } finally {
            fclose($handle);
        }
    }

    /**
     * Inspects the image held by a stream, from its offset 0 to the size reported by fstat(). The stream is left
     * open, at an unspecified position.
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

        return self::parserFor($format)->parse($reader, $this->limits);
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
    private static function parserFor(string $format): ParserInterface
    {
        switch ($format) {
            case ImageFormat::JPEG:
                return new JpegParser();
            case ImageFormat::PNG:
                return new PngParser();
            case ImageFormat::GIF:
                return new GifParser();
            case ImageFormat::BMP:
                return new BmpParser();
            case ImageFormat::WEBP:
                return new WebpParser();
            case ImageFormat::AVIF:
                return new AvifParser();
            case ImageFormat::TIFF:
                return new TiffParser();
            default:
                throw new UnsupportedFormatException('SVG input is not supported: this library never rasterises SVG.');
        }
    }
}
