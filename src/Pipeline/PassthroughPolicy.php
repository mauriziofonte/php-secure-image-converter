<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Pipeline;

use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;

/**
 * The one place that decides whether the input bytes may be returned unchanged instead of a converted output: the
 * "keep the smaller file" rule (P-12) uses it, and nothing else passes an input through.
 *
 * The input may be returned only when it already satisfies the whole request (ConverterInterface::convert()):
 *
 * - it holds nothing that decoders skip (ImageInfo::hasTrailingData(): an appended archive or script must never be
 *   served, so such a file is always re-encoded);
 * - when metadata stripping is requested, it carries no metadata (ImageInfo::hasMetadata(): EXIF, XMP, comments and
 *   the like), and no ICC profile unless the request keeps it;
 * - it is displayed as stored (EXIF orientation 1): an oriented input is re-encoded upright;
 * - its size is the requested size (no resize), and under ANIMATION_FIRST_FRAME it is not animated;
 * - its format is acceptable where the requested format is: the input format itself for FORMAT_ORIGINAL; JPEG, PNG,
 *   GIF or WEBP for a WEBP request; those or AVIF for an AVIF request (every client that decodes AVIF decodes WEBP).
 *   BMP and TIFF, which browsers do not all display, are never passed through for a WEBP or AVIF request.
 */
final class PassthroughPolicy
{
    /**
     * Input formats acceptable for each target format other than FORMAT_ORIGINAL.
     */
    private const ACCEPTABLE = [
        ImageFormat::WEBP => [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::WEBP],
        ImageFormat::AVIF => [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::WEBP, ImageFormat::AVIF],
    ];

    /**
     * Why the input may not be returned unchanged for the request, or null when it may.
     *
     * @param ImageInfo         $input     what the inspector read from the input
     * @param ConversionRequest $request   the request
     * @param BackendJob        $requested the job the request resolves to, its orientation applied
     */
    public function refusal(ImageInfo $input, ConversionRequest $request, BackendJob $requested): ?string
    {
        if ($input->hasTrailingData()) {
            return 'it holds data that decoders skip';
        }
        if ($request->shouldStripMetadata()) {
            if ($input->hasMetadata()) {
                return 'it carries metadata to strip';
            }
            if ($input->hasIccProfile() && !$request->shouldKeepIccProfile()) {
                return 'it carries an ICC profile to strip';
            }
        }
        if ($input->getOrientation() !== ImageInfo::ORIENTATION_NORMAL) {
            return 'it is not stored upright';
        }
        if ($requested->getWidth() !== $input->getWidth() || $requested->getHeight() !== $input->getHeight()) {
            return 'it must be resized';
        }
        if ($input->isAnimated() && $request->getAnimationPolicy() === ConversionRequest::ANIMATION_FIRST_FRAME) {
            return 'its animation must be reduced to the first frame';
        }
        $target = $request->getFormat();
        if ($target !== ConversionRequest::FORMAT_ORIGINAL && !in_array($input->getFormat(), self::ACCEPTABLE[$target] ?? [], true)) {
            return sprintf('%s is not acceptable for a %s request', $input->getFormat(), $target);
        }

        return null;
    }

    /**
     * Whether the input may be returned unchanged for the request (refusal() is null).
     */
    public function allows(ImageInfo $input, ConversionRequest $request, BackendJob $requested): bool
    {
        return $this->refusal($input, $request, $requested) === null;
    }
}
