<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\ValidatedSerializationTrait;

/**
 * What the header of an image declares, as read by ImageInspector without decoding any pixel: format, dimensions,
 * frame count, file size, and a few facts later stages need (bit depth, colour profile and metadata presence).
 *
 * - Width and height are those of the canvas the image is displayed on: the logical screen of a GIF, the canvas of
 *   an animated PNG or WEBP, the frame header of a JPEG.
 * - The frame count is 1 for a still image, and the number of frames of an animation (GIF images, APNG frames,
 *   WEBP ANMF frames, samples of an AVIF image sequence). Only ImageFormat::animatable() formats have more than one.
 * - The bit depth is the precision per colour channel a decoder produces at most: 8 for every 8-bit or lower depth
 *   (paletted images, 1-bit PNG, 5-6-5 BMP and every accepted JPEG included), 10 or 12 for high-precision AVIF, 16
 *   for 16-bit PNG and 64-bit BMP, the bits per sample of TIFF (from 8 to 64).
 * - hasIccProfile() reports an embedded colour profile, hasExif() an EXIF block, and hasMetadata() any metadata
 *   other than the colour profile: EXIF, XMP, IPTC, comments, text chunks and unknown ancillary data, which
 *   metadata stripping removes.
 *
 * Immutable value object. Every property has a default, and the defaults form a valid instance (a one-pixel JPEG of
 * one byte without metadata); an unserialized instance is validated again, see ValidatedSerializationTrait.
 */
final class ImageInfo
{
    use ValidatedSerializationTrait;

    public const BIT_DEPTH_MIN = 8;
    public const BIT_DEPTH_MAX = 64;

    /**
     * @var string
     */
    private $format = ImageFormat::JPEG;

    /**
     * @var int
     */
    private $width = 1;

    /**
     * @var int
     */
    private $height = 1;

    /**
     * @var int
     */
    private $frameCount = 1;

    /**
     * @var int
     */
    private $byteSize = 1;

    /**
     * @var int
     */
    private $bitDepth = self::BIT_DEPTH_MIN;

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
     * Describes an 8-bit image without colour profile or metadata; see withBitDepth() and withMetadata().
     *
     * @param string $format     ImageFormat value of a raster format (every format except svg)
     * @param int    $width      canvas width in pixels, from 1 to Capabilities::LIMIT_VALUE_MAX
     * @param int    $height     canvas height in pixels, from 1 to Capabilities::LIMIT_VALUE_MAX
     * @param int    $frameCount number of frames, from 1 to Capabilities::LIMIT_VALUE_MAX; above 1 only for an
     *                           ImageFormat::animatable() format
     * @param int    $byteSize   size of the file in bytes, at least 1
     *
     * @throws InvalidArgumentException when a value is outside its documented domain
     */
    public function __construct(string $format, int $width, int $height, int $frameCount, int $byteSize)
    {
        if (!ImageFormat::isValid($format) || $format === ImageFormat::SVG) {
            throw new InvalidArgumentException(sprintf(
                'ImageInfo: the format must be a raster format among: %s.',
                implode(', ', array_values(array_diff(ImageFormat::all(), [ImageFormat::SVG])))
            ));
        }
        self::assertRange('width', $width, 1, Capabilities::LIMIT_VALUE_MAX);
        self::assertRange('height', $height, 1, Capabilities::LIMIT_VALUE_MAX);
        self::assertRange('frame count', $frameCount, 1, Capabilities::LIMIT_VALUE_MAX);
        if ($frameCount > 1 && !ImageFormat::supportsAnimation($format)) {
            throw new InvalidArgumentException(sprintf(
                'ImageInfo: only animatable formats (%s) can have more than one frame.',
                implode(', ', ImageFormat::animatable())
            ));
        }
        self::assertRange('byte size', $byteSize, 1, PHP_INT_MAX);

        $this->format = $format;
        $this->width = $width;
        $this->height = $height;
        $this->frameCount = $frameCount;
        $this->byteSize = $byteSize;
    }

    /**
     * @param int $bitDepth precision per colour channel, from BIT_DEPTH_MIN to BIT_DEPTH_MAX
     *
     * @throws InvalidArgumentException when the bit depth is out of range
     */
    public function withBitDepth(int $bitDepth): self
    {
        self::assertRange('bit depth', $bitDepth, self::BIT_DEPTH_MIN, self::BIT_DEPTH_MAX);
        $clone = clone $this;
        $clone->bitDepth = $bitDepth;

        return $clone;
    }

    /**
     * @param bool $iccProfile    whether an ICC colour profile is embedded
     * @param bool $exif          whether an EXIF block is present
     * @param bool $otherMetadata whether other strippable metadata is present (XMP, IPTC, comments, text, unknown
     *                            ancillary data)
     */
    public function withMetadata(bool $iccProfile, bool $exif, bool $otherMetadata): self
    {
        $clone = clone $this;
        $clone->iccProfile = $iccProfile;
        $clone->exif = $exif;
        $clone->otherMetadata = $otherMetadata;

        return $clone;
    }

    /**
     * ImageFormat value identified from the content.
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Canonical MIME type of the format.
     */
    public function getMimeType(): string
    {
        return ImageFormat::mimeType($this->format);
    }

    /**
     * Canvas width in pixels.
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * Canvas height in pixels.
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Number of frames: 1 for a still image.
     */
    public function getFrameCount(): int
    {
        return $this->frameCount;
    }

    /**
     * Whether the image has more than one frame.
     */
    public function isAnimated(): bool
    {
        return $this->frameCount > 1;
    }

    /**
     * Size of the file in bytes.
     */
    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    /**
     * Precision per colour channel a decoder produces at most, from BIT_DEPTH_MIN to BIT_DEPTH_MAX.
     */
    public function getBitDepth(): int
    {
        return $this->bitDepth;
    }

    /**
     * Whether an ICC colour profile is embedded.
     */
    public function hasIccProfile(): bool
    {
        return $this->iccProfile;
    }

    /**
     * Whether an EXIF block is present.
     */
    public function hasExif(): bool
    {
        return $this->exif;
    }

    /**
     * Whether any metadata other than the colour profile is present (EXIF included).
     */
    public function hasMetadata(): bool
    {
        return $this->exif || $this->otherMetadata;
    }

    /**
     * Every value with snake_case keys, e.g. for logs.
     *
     * @return array{format: string, mime_type: string, width: int, height: int, frame_count: int, byte_size: int, bit_depth: int, icc_profile: bool, exif: bool, metadata: bool}
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'mime_type' => $this->getMimeType(),
            'width' => $this->width,
            'height' => $this->height,
            'frame_count' => $this->frameCount,
            'byte_size' => $this->byteSize,
            'bit_depth' => $this->bitDepth,
            'icc_profile' => $this->iccProfile,
            'exif' => $this->exif,
            'metadata' => $this->hasMetadata(),
        ];
    }

    /**
     * Copy of this instance rebuilt from its property values through the constructor and the with*() methods, so that
     * an unserialized instance cannot hold a value outside the documented domain; see ValidatedSerializationTrait.
     *
     * @throws InvalidArgumentException when a property value is not valid or not of its documented type
     */
    private function validatedCopy(): self
    {
        try {
            return (new self($this->format, $this->width, $this->height, $this->frameCount, $this->byteSize))
                ->withBitDepth($this->bitDepth)
                ->withMetadata($this->iccProfile, $this->exif, $this->otherMetadata);
        } catch (\TypeError $exception) {
            // unserialize() can give a property any type, whatever its @var tag says; strict_types makes a property
            // of the wrong type a TypeError instead of a silent conversion.
            throw new InvalidArgumentException('An ImageInfo property does not hold a value of its type.');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertRange(string $name, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(sprintf('ImageInfo: the %s must be from %d to %d, got %d.', $name, $min, $max, $value));
        }
    }
}
