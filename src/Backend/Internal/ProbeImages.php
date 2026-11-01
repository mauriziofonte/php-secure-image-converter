<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Internal;

use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\ImageInspector;

/**
 * The small images shipped in resources/probe that backends convert to establish their capabilities.
 *
 * | File       | Content                                                                       |
 * | ---------- | ----------------------------------------------------------------------------- |
 * | still.*    | a 16 x 12 still image of every raster format; the JPEG carries EXIF and an ICC |
 * |            | profile, the TIFF a larger second page that must be ignored                    |
 * | anim.gif   | 3 frames of 16 x 12                                                           |
 * | anim.webp  | 3 frames of 16 x 12                                                           |
 * | anim.png   | an APNG of 4 frames of 12 x 6                                                 |
 * | oriented.* | a 16 x 12 JPEG with EXIF orientation 6 (displayed 12 x 16), its quadrants red, |
 * |            | green, blue and yellow, so that a turn or a mirror shows                       |
 * | meta.*     | the still image of PNG, GIF, JPEG, WEBP and TIFF with metadata other than an   |
 * |            | ICC profile added: tEXt and eXIf chunks, a comment extension, a COM segment,   |
 * |            | an EXIF chunk (VP8X), description and software tags                            |
 * | variant-*  | a 16 x 12 still of each variant of ImageInfo::VARIANTS, such as                |
 * |            | variant-jpeg_cmyk.jpg (CMYK) and variant-bmp_bit_fields.bmp (32-bit, V4); the  |
 * |            | 64-bit BMP is 40 x 30                                                          |
 *
 * Each file is inspected with ImageInspector before use, so that a probe never relies on a damaged installation.
 *
 * A probe proves what a decoder does with its probe image only. An ImageMagick build crashed (signal 11) on a
 * 64-bit BMP of 40 x 30 after it had converted one of 16 x 12, so that probe image is the 40 x 30 one that crashed;
 * a crash on a probe image counts as "cannot decode", like any failure. A decoder that fails or crashes on a later
 * input anyway makes the conversion invalid_image (see AbstractBackend).
 *
 * @internal
 */
final class ProbeImages
{
    /**
     * @var string
     */
    private $directory;

    /**
     * @var ImageInspector
     */
    private $inspector;

    /**
     * @var array<string, ImageInfo|null>
     */
    private $infos = [];

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory !== null ? $directory : \dirname(__DIR__, 3) . '/resources/probe';
        $this->inspector = new ImageInspector(null, ImageFormat::all());
    }

    /**
     * The inspector that also checks probe outputs.
     */
    public function getInspector(): ImageInspector
    {
        return $this->inspector;
    }

    /**
     * Path and header of the still image of a format, or null when it is missing or invalid.
     *
     * @return array{0: string, 1: ImageInfo}|null
     */
    public function still(string $format): ?array
    {
        return $this->image('still.' . ImageFormat::extension($format), $format, false);
    }

    /**
     * Path and header of the still image of a format with metadata other than an ICC profile, or null when there is
     * none (AVIF, and BMP, which carries none) or it is missing or invalid.
     *
     * @return array{0: string, 1: ImageInfo}|null
     */
    public function metadata(string $format): ?array
    {
        $image = $this->image('meta.' . ImageFormat::extension($format), $format, false);

        return $image !== null && $image[1]->hasMetadata() ? $image : null;
    }

    /**
     * Path and header of the still image of a variant (ImageInfo::VARIANT_*), or null when it is missing or invalid.
     *
     * @return array{0: string, 1: ImageInfo}|null
     */
    public function variant(string $variant): ?array
    {
        $format = ImageInfo::VARIANTS[$variant] ?? null;
        $image = $format !== null ? $this->image('variant-' . $variant . '.' . ImageFormat::extension($format), $format, false) : null;

        return $image !== null && in_array($variant, $image[1]->getVariants(), true) ? $image : null;
    }

    /**
     * Path and header of the JPEG whose EXIF orientation is 6 (a quarter turn clockwise), or null when it is missing
     * or invalid.
     *
     * @return array{0: string, 1: ImageInfo}|null
     */
    public function oriented(): ?array
    {
        $image = $this->image('oriented.jpg', ImageFormat::JPEG, false);

        return $image !== null && $image[1]->getOrientation() === 6 ? $image : null;
    }

    /**
     * Path and header of the animation of a format, or null when there is none.
     *
     * @return array{0: string, 1: ImageInfo}|null
     */
    public function animation(string $format): ?array
    {
        return in_array($format, [ImageFormat::GIF, ImageFormat::WEBP, ImageFormat::PNG], true)
            ? $this->image('anim.' . ImageFormat::extension($format), $format, true)
            : null;
    }

    /**
     * @return array{0: string, 1: ImageInfo}|null
     */
    private function image(string $name, string $format, bool $animated): ?array
    {
        $path = $this->directory . '/' . $name;
        if (!array_key_exists($name, $this->infos)) {
            try {
                $this->infos[$name] = $this->inspector->inspect($path);
            } catch (ExceptionInterface $exception) {
                $this->infos[$name] = null;
            }
        }
        $info = $this->infos[$name];
        if ($info === null || $info->getFormat() !== $format || $info->isAnimated() !== $animated) {
            return null;
        }

        return [$path, $info];
    }
}
