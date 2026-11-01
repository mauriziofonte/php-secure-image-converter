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
 * - getOrientation() is the EXIF orientation (1 to 8) a viewer applies when it auto-rotates: the Orientation tag of
 *   the EXIF block of JPEG, PNG and WEBP and of the TIFF directory, or the irot and imir transforms of AVIF; 1 when
 *   there is none or it cannot be read. With 5 to 8 an auto-rotating backend swaps width and height, so output
 *   verification must expect either size.
 * - hasTrailingData() reports bytes that the decoder of the image does not read: data after the end of the image
 *   (an appended archive, script or second file, such as the ZIP of a GIFAR or of a JPEG polyglot); in a WEBP file,
 *   bytes after the bitstream of a simple file, unknown chunks, and bytes of ANIM beyond its fields; in a BMP file,
 *   any byte outside the headers, the palette of an image of 8 bits or less, the pixel data (as far as its declared
 *   size when run-length encoded) and the colour profile; top-level AVIF boxes that are not part of the image; for
 *   TIFF, further pages or bytes beyond every structure of the first page. Where it is computed, the flag errs towards true. Such a file must be
 *   re-encoded, never passed through unconverted. Not detected: bytes inside the AVIF mdat box that no item or sample
 *   uses, unused bytes inside other structures that decoders skip, and data inside the colour profile or the metadata
 *   (which hasIccProfile(), hasExif() and hasMetadata() report). A consumer that must never return bytes it has not
 *   decoded re-encodes every image, and strips or rebuilds the colour profile, whatever the flag.
 * - getVariants() names the variants of the format that some decoders do not read (see VARIANTS): a CMYK JPEG, a BMP
 *   with bit-field masks, 2 or 64 bits per pixel, a V2 or V3 header, or a colour profile before its pixels, a GIF
 *   with a long graphic control extension, a WEBP in the animation layout, a tiled TIFF or one of 2 or more than 4
 *   samples per pixel. Backends probe which of them they decode, so that an image is never given to a decoder that
 *   cannot read it.
 *
 * Only the first image is described, and backends must decode it alone (page, scene or item 0): for TIFF, the first
 * page (IFD0) is read and checked against the limits, later pages are neither counted nor checked. A BMP file from
 * which ImageMagick would read a second image, and a still AVIF holding an image item that libheif would decode
 * besides its primary item, thumbnails and auxiliary images, are refused; other decoders may still find more.
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
     * EXIF orientation of an image displayed as stored.
     */
    public const ORIENTATION_NORMAL = 1;

    /**
     * Largest EXIF orientation value: displayed after a quarter turn anticlockwise.
     */
    public const ORIENTATION_MAX = 8;

    /**
     * A JPEG of four components: CMYK, or YCCK (Adobe transform 2).
     */
    public const VARIANT_JPEG_CMYK = 'jpeg_cmyk';

    /**
     * A BMP whose pixels are described by colour masks (BI_BITFIELDS), with 16 or 32 bits per pixel.
     */
    public const VARIANT_BMP_BIT_FIELDS = 'bmp_bit_fields';

    /**
     * A BMP whose pixels are described by colour and alpha masks (BI_ALPHABITFIELDS), with 16 or 32 bits per pixel.
     */
    public const VARIANT_BMP_ALPHA_BIT_FIELDS = 'bmp_alpha_bit_fields';

    /**
     * A BMP of 2 bits per pixel.
     */
    public const VARIANT_BMP_2_BIT = 'bmp_2_bit';

    /**
     * A BMP of 64 bits per pixel.
     */
    public const VARIANT_BMP_64_BIT = 'bmp_64_bit';

    /**
     * A BMP with a BITMAPV2INFOHEADER (52 bytes).
     */
    public const VARIANT_BMP_V2_HEADER = 'bmp_v2_header';

    /**
     * A BMP with a BITMAPV3INFOHEADER (56 bytes).
     */
    public const VARIANT_BMP_V3_HEADER = 'bmp_v3_header';

    /**
     * A V5 BMP whose embedded colour profile lies before its pixel data, which the specification allows and
     * ImageMagick 6 refuses ("improper image header").
     */
    public const VARIANT_BMP_PROFILE_BEFORE_PIXELS = 'bmp_profile_before_pixels';

    /**
     * A GIF with a graphic control extension whose fields sub-block is longer than 4 bytes, which giflib refuses.
     */
    public const VARIANT_GIF_LONG_GRAPHIC_CONTROL = 'gif_long_graphic_control';

    /**
     * A WEBP in the animation layout (ANIM and ANMF chunks), even of a single frame, which still-image decoders
     * (WebPDecode) refuse.
     */
    public const VARIANT_WEBP_ANIMATION = 'webp_animation';

    /**
     * A TIFF stored in tiles rather than strips.
     */
    public const VARIANT_TIFF_TILED = 'tiff_tiled';

    /**
     * A TIFF of 2 samples per pixel (grey with alpha) or more than 4.
     */
    public const VARIANT_TIFF_UNCOMMON_SAMPLES = 'tiff_uncommon_samples';

    /**
     * Every variant, in canonical order, with the format it belongs to.
     */
    public const VARIANTS = [
        self::VARIANT_JPEG_CMYK => ImageFormat::JPEG,
        self::VARIANT_BMP_BIT_FIELDS => ImageFormat::BMP,
        self::VARIANT_BMP_ALPHA_BIT_FIELDS => ImageFormat::BMP,
        self::VARIANT_BMP_2_BIT => ImageFormat::BMP,
        self::VARIANT_BMP_64_BIT => ImageFormat::BMP,
        self::VARIANT_BMP_V2_HEADER => ImageFormat::BMP,
        self::VARIANT_BMP_V3_HEADER => ImageFormat::BMP,
        self::VARIANT_BMP_PROFILE_BEFORE_PIXELS => ImageFormat::BMP,
        self::VARIANT_GIF_LONG_GRAPHIC_CONTROL => ImageFormat::GIF,
        self::VARIANT_WEBP_ANIMATION => ImageFormat::WEBP,
        self::VARIANT_TIFF_TILED => ImageFormat::TIFF,
        self::VARIANT_TIFF_UNCOMMON_SAMPLES => ImageFormat::TIFF,
    ];

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
     * @var int
     */
    private $orientation = self::ORIENTATION_NORMAL;

    /**
     * @var bool
     */
    private $trailingData = false;

    /**
     * @var list<string>
     */
    private $variants = [];

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
     * @param int $orientation EXIF orientation, from ORIENTATION_NORMAL (1) to ORIENTATION_MAX (8)
     *
     * @throws InvalidArgumentException when the orientation is out of range
     */
    public function withOrientation(int $orientation): self
    {
        self::assertRange('orientation', $orientation, self::ORIENTATION_NORMAL, self::ORIENTATION_MAX);
        $clone = clone $this;
        $clone->orientation = $orientation;

        return $clone;
    }

    /**
     * @param bool $trailingData whether the file holds data that decoders skip (see the class description)
     */
    public function withTrailingData(bool $trailingData): self
    {
        $clone = clone $this;
        $clone->trailingData = $trailingData;

        return $clone;
    }

    /**
     * @param list<string> $variants VARIANT_* values of this image's format, without duplicates
     *
     * @throws InvalidArgumentException when a variant is unknown, repeated or of another format
     */
    public function withVariants(array $variants): self
    {
        foreach ($variants as $variant) {
            if (!in_array($variant, array_keys(self::VARIANTS), true) || self::VARIANTS[$variant] !== $this->format) {
                throw new InvalidArgumentException(sprintf('ImageInfo: a variant is not one of the %s format.', $this->format));
            }
        }
        if (count(array_unique($variants)) !== count($variants)) {
            throw new InvalidArgumentException('ImageInfo: a variant is listed twice.');
        }
        $clone = clone $this;
        $clone->variants = array_values(array_intersect(array_keys(self::VARIANTS), $variants));

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
     * EXIF orientation a viewer applies, from 1 (as stored) to 8; with 5 to 8 the displayed width and height are
     * swapped.
     */
    public function getOrientation(): int
    {
        return $this->orientation;
    }

    /**
     * Whether the file holds data that decoders skip (see the class description).
     */
    public function hasTrailingData(): bool
    {
        return $this->trailingData;
    }

    /**
     * Variants of the format that some decoders do not read (VARIANT_* values), in canonical order; empty for most
     * images.
     *
     * @return list<string>
     */
    public function getVariants(): array
    {
        return $this->variants;
    }

    /**
     * Every value with snake_case keys, e.g. for logs.
     *
     * @return array{format: string, mime_type: string, width: int, height: int, frame_count: int, byte_size: int, bit_depth: int, icc_profile: bool, exif: bool, metadata: bool, orientation: int, trailing_data: bool, variants: list<string>}
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
            'orientation' => $this->orientation,
            'trailing_data' => $this->trailingData,
            'variants' => $this->variants,
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
                ->withMetadata($this->iccProfile, $this->exif, $this->otherMetadata)
                ->withOrientation($this->orientation)
                ->withTrailingData($this->trailingData)
                ->withVariants($this->variants);
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
