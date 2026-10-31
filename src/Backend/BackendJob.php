<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Validation\ImageInfo;

/**
 * One conversion as a backend performs it: the input file and what its header declares, the output file and format,
 * and every encoding decision already taken by the caller.
 *
 * The caller resolves the request before it builds the job (fromRequest() does it from a ConversionRequest): the
 * output format (FORMAT_ORIGINAL becomes the input format), the exact output size after resize, fit and enlargement,
 * and the animation policy (an animated input under ANIMATION_REJECT never becomes a job). A backend never decides any
 * of these; it produces exactly the size given and, for an animated input, every frame when keepsAnimation() is true
 * and the first frame only when it is false. BackendResolver may replace the output format with a fallback and the
 * animation with the first frame (see BackendSelection); the result is a new job.
 *
 * - Size and fit: getWidth() x getHeight() is the exact output size. FIT_CONTAIN and FIT_FILL scale the image to that
 *   size (the size of a contained image already has the input's aspect ratio, rounded); FIT_COVER scales it to cover
 *   the size and crops the overflow around the centre.
 * - Quality: getQuality() is the requested quality, or the default of the output format (see
 *   ConversionRequest::defaultQualityFor()), so that a fallback to another format takes that format's default.
 * - Effort is the normalised 0 (fastest) to 9 (smallest output) scale of ConversionRequest; each backend maps it to
 *   its own encoder setting.
 * - Metadata: with shouldStripMetadata() every metadata block is removed, except the ICC profile when
 *   shouldKeepIccProfile(); without it, every block the output format can carry is kept.
 * - Only the first image of a multi-image input is decoded: page 0 of a TIFF, the primary image of an AVIF.
 * - Orientation: getOrientation() is the EXIF orientation (1 to 8) the backend applies to the decoded pixels before
 *   it resizes them, so that the output is upright; 1, the default, keeps the pixels as stored. With 5 to 8 the
 *   upright image has the input's width and height swapped (getOrientedWidth(), getOrientedHeight()), and the output
 *   size is computed from the upright size. An output with metadata kept carries no orientation other than 1 once it
 *   is applied. Only backends whose capabilities list the input format among their oriented formats apply it
 *   (BackendCapabilities::getOrientedFormats()); fromRequest() asks for it whenever the input declares one.
 *
 * Paths are absolute and normalised (no ".", ".." or empty segment, no NUL byte). The input path is never passed to a
 * program as it is (a backend links it into its private working directory under a fixed name), so it may contain any
 * other character. Immutable value object: every with*() method returns a modified copy.
 */
final class BackendJob
{
    /**
     * The first EXIF orientation that transposes the image (5 to 8 swap width and height).
     */
    public const FIRST_TRANSPOSING_ORIENTATION = 5;

    /**
     * @var string
     */
    private $inputPath;

    /**
     * @var ImageInfo
     */
    private $input;

    /**
     * @var string
     */
    private $outputPath;

    /**
     * @var string
     */
    private $outputFormat;

    /**
     * @var int
     */
    private $width;

    /**
     * @var int
     */
    private $height;

    /**
     * @var string
     */
    private $fit = ConversionRequest::FIT_CONTAIN;

    /**
     * @var int|null
     */
    private $quality;

    /**
     * @var int
     */
    private $effort = ConversionRequest::DEFAULT_EFFORT;

    /**
     * @var bool
     */
    private $lossless = false;

    /**
     * @var bool
     */
    private $stripMetadata = true;

    /**
     * @var bool
     */
    private $keepIccProfile = true;

    /**
     * @var bool
     */
    private $keepAnimation = true;

    /**
     * @var int
     */
    private $orientation = ImageInfo::ORIENTATION_NORMAL;

    /**
     * A job with the defaults of ConversionRequest: fit contain, default quality, effort 4, lossy, metadata stripped
     * but the ICC profile kept, animation kept.
     *
     * @param string    $inputPath    absolute, normalised path of the input file
     * @param ImageInfo $input        what ImageInspector read from the header of that file
     * @param string    $outputPath   absolute, normalised path to write the output to, other than $inputPath
     * @param string    $outputFormat ImageFormat value of the output, any raster format
     * @param int       $width        exact output width, from 1 to Capabilities::OUTPUT_DIMENSION_MAX
     * @param int       $height       exact output height, from 1 to Capabilities::OUTPUT_DIMENSION_MAX
     *
     * @throws InvalidArgumentException when a value is outside its documented domain (a programming error)
     */
    public function __construct(
        string $inputPath,
        ImageInfo $input,
        string $outputPath,
        string $outputFormat,
        int $width,
        int $height
    ) {
        if (!ExecutableFinder::isAbsolutePath($inputPath) || !ExecutableFinder::isAbsolutePath($outputPath)) {
            throw new InvalidArgumentException('BackendJob: the input and output paths must be absolute, normalised paths.');
        }
        if ($inputPath === $outputPath) {
            throw new InvalidArgumentException('BackendJob: the output path must differ from the input path.');
        }
        foreach (['width' => $width, 'height' => $height] as $name => $value) {
            if ($value < 1 || $value > Capabilities::OUTPUT_DIMENSION_MAX) {
                throw new InvalidArgumentException(sprintf(
                    'BackendJob: the output %s must be from 1 to %d, got %d.',
                    $name,
                    Capabilities::OUTPUT_DIMENSION_MAX,
                    $value
                ));
            }
        }

        $this->inputPath = $inputPath;
        $this->input = $input;
        $this->outputPath = $outputPath;
        $this->outputFormat = self::checkOutputFormat($outputFormat);
        $this->width = $width;
        $this->height = $height;
    }

    /**
     * The job a ConversionRequest asks for: output format (ConversionRequest::resolveOutputFormat()), output size
     * (resolveOutputSize() of the upright input when the orientation is applied, of the stored input otherwise), fit
     * (contain unless both width and height are set), quality, effort, lossless, metadata handling, animation policy
     * and orientation.
     *
     * @param string    $inputPath        absolute, normalised path of the input file
     * @param ImageInfo $input            what ImageInspector read from the header of that file
     * @param string    $outputPath       absolute, normalised path to write the output to
     * @param bool      $applyOrientation whether the input's orientation (ImageInfo::getOrientation()) is applied;
     *                                    false keeps the pixels as stored, for a host where no backend applies it
     *
     * @throws InvalidImageException    with error code animation_rejected when the input is animated and the policy is
     *                                  ANIMATION_REJECT
     * @throws InvalidArgumentException when a path is invalid or the resolved size is above
     *                                  Capabilities::OUTPUT_DIMENSION_MAX (the caller checks the output limits first)
     */
    public static function fromRequest(
        string $inputPath,
        ImageInfo $input,
        string $outputPath,
        ConversionRequest $request,
        bool $applyOrientation = true
    ): self {
        // Refuses an animated input under ANIMATION_REJECT before anything else.
        $request->resolveOutputFrameCount($input->getFrameCount(), true);
        $orientation = $applyOrientation ? $input->getOrientation() : ImageInfo::ORIENTATION_NORMAL;
        list($width, $height) = $orientation >= self::FIRST_TRANSPOSING_ORIENTATION
            ? $request->resolveOutputSize($input->getHeight(), $input->getWidth())
            : $request->resolveOutputSize($input->getWidth(), $input->getHeight());
        $boxed = $request->getWidth() !== null && $request->getHeight() !== null;

        return (new self(
            $inputPath,
            $input,
            $outputPath,
            $request->resolveOutputFormat($input->getFormat()),
            $width,
            $height
        ))
            ->withFit($boxed ? $request->getFit() : ConversionRequest::FIT_CONTAIN)
            ->withQuality($request->getQuality())
            ->withEffort($request->getEffort())
            ->withLossless($request->isLossless())
            ->withStripMetadata($request->shouldStripMetadata())
            ->withKeepIccProfile($request->shouldKeepIccProfile())
            ->withAnimation($request->getAnimationPolicy() === ConversionRequest::ANIMATION_PRESERVE)
            ->withOrientation($orientation);
    }

    /**
     * Absolute path of the input file.
     */
    public function getInputPath(): string
    {
        return $this->inputPath;
    }

    /**
     * What the header of the input declares.
     */
    public function getInput(): ImageInfo
    {
        return $this->input;
    }

    /**
     * ImageFormat value of the input.
     */
    public function getInputFormat(): string
    {
        return $this->input->getFormat();
    }

    /**
     * Absolute path the output is written to.
     */
    public function getOutputPath(): string
    {
        return $this->outputPath;
    }

    /**
     * ImageFormat value of the output.
     */
    public function getOutputFormat(): string
    {
        return $this->outputFormat;
    }

    /**
     * @param string $format ImageFormat value of a raster format
     *
     * @throws InvalidArgumentException when the format is unknown or SVG
     */
    public function withOutputFormat(string $format): self
    {
        $clone = clone $this;
        $clone->outputFormat = self::checkOutputFormat($format);

        return $clone;
    }

    /**
     * Exact output width in pixels.
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * Exact output height in pixels.
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Whether the output size differs from the size of the input after its orientation is applied.
     */
    public function isResized(): bool
    {
        return $this->width !== $this->getOrientedWidth() || $this->height !== $this->getOrientedHeight();
    }

    /**
     * EXIF orientation applied to the decoded pixels, from 1 (none) to 8 (see the class description).
     */
    public function getOrientation(): int
    {
        return $this->orientation;
    }

    /**
     * Whether an orientation other than 1 is applied.
     */
    public function needsOrientation(): bool
    {
        return $this->orientation !== ImageInfo::ORIENTATION_NORMAL;
    }

    /**
     * @param int $orientation EXIF orientation to apply, from 1 (none) to 8; the output size is not changed
     *
     * @throws InvalidArgumentException when the orientation is out of range
     */
    public function withOrientation(int $orientation): self
    {
        if ($orientation < ImageInfo::ORIENTATION_NORMAL || $orientation > ImageInfo::ORIENTATION_MAX) {
            throw new InvalidArgumentException(sprintf(
                'BackendJob: the orientation must be from %d to %d.',
                ImageInfo::ORIENTATION_NORMAL,
                ImageInfo::ORIENTATION_MAX
            ));
        }
        $clone = clone $this;
        $clone->orientation = $orientation;

        return $clone;
    }

    /**
     * Width of the input once the orientation is applied: its height for orientations 5 to 8.
     */
    public function getOrientedWidth(): int
    {
        return $this->orientation >= self::FIRST_TRANSPOSING_ORIENTATION ? $this->input->getHeight() : $this->input->getWidth();
    }

    /**
     * Height of the input once the orientation is applied: its width for orientations 5 to 8.
     */
    public function getOrientedHeight(): int
    {
        return $this->orientation >= self::FIRST_TRANSPOSING_ORIENTATION ? $this->input->getWidth() : $this->input->getHeight();
    }

    /**
     * How the input is mapped onto the output size: ConversionRequest::FIT_CONTAIN, FIT_COVER or FIT_FILL.
     */
    public function getFit(): string
    {
        return $this->fit;
    }

    /**
     * @throws InvalidArgumentException when the fit is not contain, cover or fill
     */
    public function withFit(string $fit): self
    {
        if (!in_array($fit, ConversionRequest::fitModes(), true)) {
            throw new InvalidArgumentException(sprintf(
                'BackendJob: the fit must be one of: %s.',
                implode(', ', ConversionRequest::fitModes())
            ));
        }
        $clone = clone $this;
        $clone->fit = $fit;

        return $clone;
    }

    /**
     * Quality to encode with, from 1 to 100: the requested one, or the default of the output format.
     */
    public function getQuality(): int
    {
        return $this->quality !== null ? $this->quality : ConversionRequest::defaultQualityFor($this->outputFormat);
    }

    /**
     * The requested quality, or null for the default of the output format.
     */
    public function getRequestedQuality(): ?int
    {
        return $this->quality;
    }

    /**
     * @param int|null $quality from 1 to 100, or null for the default of the output format
     *
     * @throws InvalidArgumentException when the quality is out of range
     */
    public function withQuality(?int $quality): self
    {
        if ($quality !== null && ($quality < ConversionRequest::QUALITY_MIN || $quality > ConversionRequest::QUALITY_MAX)) {
            throw new InvalidArgumentException(sprintf(
                'BackendJob: the quality must be from %d to %d.',
                ConversionRequest::QUALITY_MIN,
                ConversionRequest::QUALITY_MAX
            ));
        }
        $clone = clone $this;
        $clone->quality = $quality;

        return $clone;
    }

    /**
     * Encoder effort from 0 (fastest) to 9 (smallest output).
     */
    public function getEffort(): int
    {
        return $this->effort;
    }

    /**
     * @param int $effort from 0 to 9
     *
     * @throws InvalidArgumentException when the effort is out of range
     */
    public function withEffort(int $effort): self
    {
        if ($effort < ConversionRequest::EFFORT_MIN || $effort > ConversionRequest::EFFORT_MAX) {
            throw new InvalidArgumentException(sprintf(
                'BackendJob: the effort must be from %d to %d.',
                ConversionRequest::EFFORT_MIN,
                ConversionRequest::EFFORT_MAX
            ));
        }
        $clone = clone $this;
        $clone->effort = $effort;

        return $clone;
    }

    /**
     * Whether lossless encoding is requested. It matters for WEBP and AVIF output only: JPEG has no lossless mode, and
     * PNG, GIF, BMP and TIFF are always encoded losslessly.
     */
    public function isLossless(): bool
    {
        return $this->lossless;
    }

    /**
     * Whether the output format has a lossless mode that must be selected: lossless is requested and the output is
     * WEBP or AVIF.
     */
    public function needsLosslessMode(): bool
    {
        return $this->lossless && in_array($this->outputFormat, [ImageFormat::WEBP, ImageFormat::AVIF], true);
    }

    public function withLossless(bool $lossless): self
    {
        $clone = clone $this;
        $clone->lossless = $lossless;

        return $clone;
    }

    /**
     * Whether metadata is removed from the output (all of it, except the ICC profile when shouldKeepIccProfile()).
     */
    public function shouldStripMetadata(): bool
    {
        return $this->stripMetadata;
    }

    public function withStripMetadata(bool $strip): self
    {
        $clone = clone $this;
        $clone->stripMetadata = $strip;

        return $clone;
    }

    /**
     * Whether the ICC profile survives metadata stripping. Only meaningful when shouldStripMetadata() is true.
     */
    public function shouldKeepIccProfile(): bool
    {
        return $this->keepIccProfile;
    }

    public function withKeepIccProfile(bool $keep): self
    {
        $clone = clone $this;
        $clone->keepIccProfile = $keep;

        return $clone;
    }

    /**
     * Whether the input's ICC profile must be kept while the other metadata is stripped: stripping is requested with
     * the ICC profile kept, and the input has one.
     */
    public function needsIccProfile(): bool
    {
        return $this->stripMetadata && $this->keepIccProfile && $this->input->hasIccProfile();
    }

    /**
     * Whether the input's metadata must be kept: stripping is not requested, and the input has metadata or an ICC
     * profile.
     */
    public function needsMetadata(): bool
    {
        return !$this->stripMetadata && ($this->input->hasMetadata() || $this->input->hasIccProfile());
    }

    /**
     * Whether every frame of an animated input is kept (ConversionRequest::ANIMATION_PRESERVE); false converts its
     * first frame only (ANIMATION_FIRST_FRAME). Irrelevant for a still input.
     */
    public function keepsAnimation(): bool
    {
        return $this->keepAnimation;
    }

    /**
     * @param bool $keep true to keep every frame of an animated input, false to convert its first frame only
     */
    public function withAnimation(bool $keep): self
    {
        $clone = clone $this;
        $clone->keepAnimation = $keep;

        return $clone;
    }

    /**
     * Number of frames of the output: every frame of an animated input when the animation is kept, 1 otherwise.
     */
    public function getFrameCount(): int
    {
        return $this->keepAnimation ? $this->input->getFrameCount() : 1;
    }

    /**
     * Whether the output has several frames.
     */
    public function isAnimated(): bool
    {
        return $this->getFrameCount() > 1;
    }

    /**
     * Number of pixels a backend decodes from the input: one frame, or every frame when the output is animated.
     * Saturates at PHP_INT_MAX.
     */
    public function getInputPixels(): int
    {
        return self::pixels($this->input->getWidth() * $this->input->getHeight(), $this->getFrameCount());
    }

    /**
     * Number of pixels a backend encodes: the output size times the output frame count. Saturates at PHP_INT_MAX.
     */
    public function getOutputPixels(): int
    {
        return self::pixels($this->width * $this->height, $this->getFrameCount());
    }

    /**
     * $framePixels x $frames, saturated at PHP_INT_MAX. Both dimensions of a frame are below 2^31, so $framePixels
     * itself never overflows a 64-bit integer.
     */
    private static function pixels(int $framePixels, int $frames): int
    {
        return $frames > intdiv(PHP_INT_MAX, $framePixels) ? PHP_INT_MAX : $framePixels * $frames;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function checkOutputFormat(string $format): string
    {
        if (!ImageFormat::isValid($format) || $format === ImageFormat::SVG) {
            throw new InvalidArgumentException(sprintf(
                'BackendJob: the output format must be a raster format among: %s.',
                implode(', ', array_values(array_diff(ImageFormat::all(), [ImageFormat::SVG])))
            ));
        }

        return $format;
    }
}
