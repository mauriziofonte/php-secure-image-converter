<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidRequestException;

/**
 * What a conversion must produce: target format, quality, encoder effort, resize box and metadata handling.
 *
 * Immutable value object: every with*() method validates its argument and returns a modified copy, leaving the
 * original untouched; an invalid argument throws InvalidRequestException and creates nothing. A new instance holds
 * the defaults (webp, default quality, effort 4, no resize, metadata stripped but ICC profile kept, animation
 * preserved). An unserialized instance is validated again and rebuilt by value, see ValidatedSerializationTrait. Every
 * property has a default, and the defaults form the default request, so that an instance created without the
 * constructor is the default request too.
 *
 * This class is the single source of truth for conversion parameters across the library, the conversion API and the
 * proxy: the API maps HTTP parameters with fromParameters(), the proxy sends toParameters() to the API, and caches
 * key their entries with fingerprint(). Canonical parameter names and values:
 *
 * | Name        | Values                                            | Default    |
 * | ----------- | ------------------------------------------------- | ---------- |
 * | `format`    | `webp`, `avif`, `original`                        | `webp`     |
 * | `quality`   | integer 1-100; omitted for the per-format default | (omitted)  |
 * | `effort`    | integer 0-9 (0 fastest, 9 smallest output)        | `4`        |
 * | `lossless`  | boolean                                           | `0`        |
 * | `width`     | integer 1-16383; omitted for no constraint        | (omitted)  |
 * | `height`    | integer 1-16383; omitted for no constraint        | (omitted)  |
 * | `fit`       | `contain`, `cover`, `fill`                        | `contain`  |
 * | `enlarge`   | boolean                                           | `0`        |
 * | `strip`     | boolean                                           | `1`        |
 * | `keep_icc`  | boolean                                           | `1`        |
 * | `animation` | `preserve`, `first_frame`, `reject`               | `preserve` |
 *
 * These ranges are static: they are the same on every host, they are checked here, and a value outside them is a
 * bad request (InvalidRequestException, error code invalid_request, 400). Whether the output resolved from a valid
 * request (resolveOutputSize(), resolveOutputFrameCount()) fits a converter's dimension, pixel and frame limits
 * depends on the host and on the input; it is checked at conversion time with Capabilities::assertOutputWithinLimits()
 * (LimitExceededException, error code image_too_large, 422). For example width=16383, height=16383, fit=fill and
 * enlarge=1 is a valid request, and its 268-megapixel output is refused by any converter with a lower limit.
 *
 * Spelling: toParameters() writes integers in decimal digits without sign, spaces or leading zeros, and booleans as
 * `1` or `0`. fromParameters() accepts that canonical spelling plus a closed set of alternatives, and rejects
 * everything else: PHP ints for integers; PHP bools, the PHP ints 0 and 1, and the strings `true` and `false` for
 * booleans. Every accepted spelling of a value parses to the same value, so equivalent requests have equal
 * toParameters() and fingerprint().
 */
final class ConversionRequest
{
    use ValidatedSerializationTrait;

    /**
     * Target format meaning "keep the input format": the image is only resized and/or stripped of metadata, and
     * re-encoded in its own format.
     */
    public const FORMAT_ORIGINAL = 'original';

    /**
     * Scale the image to fit inside the width x height box, preserving its aspect ratio.
     */
    public const FIT_CONTAIN = 'contain';

    /**
     * Scale the image to cover the whole width x height box, preserving its aspect ratio, then crop the overflow
     * around the centre so that the output is exactly width x height.
     */
    public const FIT_COVER = 'cover';

    /**
     * Stretch the image to exactly width x height, ignoring its aspect ratio.
     */
    public const FIT_FILL = 'fill';

    /**
     * Keep every frame of an animated input when the output format supports animation
     * (Capabilities::supportsAnimatedOutput()); otherwise behave as ANIMATION_FIRST_FRAME.
     */
    public const ANIMATION_PRESERVE = 'preserve';

    /**
     * Convert only the first frame of an animated input, producing a still image.
     */
    public const ANIMATION_FIRST_FRAME = 'first_frame';

    /**
     * Refuse animated input with InvalidImageException. Still images are converted normally.
     */
    public const ANIMATION_REJECT = 'reject';

    public const QUALITY_MIN = 1;
    public const QUALITY_MAX = 100;

    /**
     * Quality used for WEBP output when no quality is requested.
     */
    public const DEFAULT_QUALITY_WEBP = 80;

    /**
     * Quality used for AVIF output when no quality is requested. AVIF reaches the visual quality of WEBP at a lower
     * nominal value.
     */
    public const DEFAULT_QUALITY_AVIF = 60;

    /**
     * Quality used for JPEG output, and for any other output format with a quality setting, when no quality is
     * requested. Lossless formats (PNG, GIF, BMP) ignore quality.
     */
    public const DEFAULT_QUALITY_JPEG = 85;

    public const EFFORT_MIN = 0;
    public const EFFORT_MAX = 9;
    public const DEFAULT_EFFORT = 4;

    public const DIMENSION_MIN = 1;

    /**
     * Largest width or height that can be requested: 16383 px, the largest dimension WEBP can encode and the smallest
     * maximum among the target formats, so that a valid request never asks for a size its target format cannot hold.
     */
    public const DIMENSION_MAX = 16383;

    public const DEFAULT_FORMAT = ImageFormat::WEBP;

    public const PARAM_FORMAT = 'format';
    public const PARAM_QUALITY = 'quality';
    public const PARAM_EFFORT = 'effort';
    public const PARAM_LOSSLESS = 'lossless';
    public const PARAM_WIDTH = 'width';
    public const PARAM_HEIGHT = 'height';
    public const PARAM_FIT = 'fit';
    public const PARAM_ENLARGE = 'enlarge';
    public const PARAM_STRIP = 'strip';
    public const PARAM_KEEP_ICC = 'keep_icc';
    public const PARAM_ANIMATION = 'animation';

    /**
     * Version of the canonical form hashed by fingerprint(). Bump it whenever the meaning of a parameter changes, so
     * that cache entries produced under the old meaning stop matching.
     */
    private const FINGERPRINT_VERSION = 1;

    /**
     * @var list<string>
     */
    private const PARAMETERS = [
        self::PARAM_FORMAT,
        self::PARAM_QUALITY,
        self::PARAM_EFFORT,
        self::PARAM_LOSSLESS,
        self::PARAM_WIDTH,
        self::PARAM_HEIGHT,
        self::PARAM_FIT,
        self::PARAM_ENLARGE,
        self::PARAM_STRIP,
        self::PARAM_KEEP_ICC,
        self::PARAM_ANIMATION,
    ];

    /**
     * Parameter name of each property, used to validate an unserialized instance.
     *
     * @var array<string, string>
     */
    private const PROPERTY_PARAMETERS = [
        'format' => self::PARAM_FORMAT,
        'quality' => self::PARAM_QUALITY,
        'effort' => self::PARAM_EFFORT,
        'lossless' => self::PARAM_LOSSLESS,
        'width' => self::PARAM_WIDTH,
        'height' => self::PARAM_HEIGHT,
        'fit' => self::PARAM_FIT,
        'enlargementAllowed' => self::PARAM_ENLARGE,
        'stripMetadata' => self::PARAM_STRIP,
        'keepIccProfile' => self::PARAM_KEEP_ICC,
        'animationPolicy' => self::PARAM_ANIMATION,
    ];

    /**
     * @var list<string>
     */
    private const TARGET_FORMATS = [ImageFormat::WEBP, ImageFormat::AVIF, self::FORMAT_ORIGINAL];

    /**
     * @var list<string>
     */
    private const FITS = [self::FIT_CONTAIN, self::FIT_COVER, self::FIT_FILL];

    /**
     * @var list<string>
     */
    private const ANIMATION_POLICIES = [self::ANIMATION_PRESERVE, self::ANIMATION_FIRST_FRAME, self::ANIMATION_REJECT];

    /**
     * @var array<string, int>
     */
    private const DEFAULT_QUALITIES = [
        ImageFormat::WEBP => self::DEFAULT_QUALITY_WEBP,
        ImageFormat::AVIF => self::DEFAULT_QUALITY_AVIF,
        ImageFormat::JPEG => self::DEFAULT_QUALITY_JPEG,
    ];

    /**
     * @var string
     */
    private $format = self::DEFAULT_FORMAT;

    /**
     * @var int|null
     */
    private $quality;

    /**
     * @var int
     */
    private $effort = self::DEFAULT_EFFORT;

    /**
     * @var bool
     */
    private $lossless = false;

    /**
     * @var int|null
     */
    private $width;

    /**
     * @var int|null
     */
    private $height;

    /**
     * @var string
     */
    private $fit = self::FIT_CONTAIN;

    /**
     * @var bool
     */
    private $enlargementAllowed = false;

    /**
     * @var bool
     */
    private $stripMetadata = true;

    /**
     * @var bool
     */
    private $keepIccProfile = true;

    /**
     * @var string
     */
    private $animationPolicy = self::ANIMATION_PRESERVE;

    /**
     * Creates a request with default values for the given target format.
     *
     * @param string $format ImageFormat::WEBP, ImageFormat::AVIF or self::FORMAT_ORIGINAL
     *
     * @throws InvalidRequestException when the format is not a target format
     */
    public function __construct(string $format = self::DEFAULT_FORMAT)
    {
        $this->format = self::checkChoice(self::PARAM_FORMAT, $format, self::TARGET_FORMATS);
    }

    /**
     * Canonical parameter names, in canonical order (the order used by toParameters()).
     *
     * @return list<string>
     */
    public static function parameterNames(): array
    {
        return self::PARAMETERS;
    }

    /**
     * Accepted target formats: webp, avif and original.
     *
     * @return list<string>
     */
    public static function targetFormats(): array
    {
        return self::TARGET_FORMATS;
    }

    /**
     * Accepted fit modes: contain, cover and fill.
     *
     * @return list<string>
     */
    public static function fitModes(): array
    {
        return self::FITS;
    }

    /**
     * Accepted animation policies: preserve, first_frame and reject.
     *
     * @return list<string>
     */
    public static function animationPolicies(): array
    {
        return self::ANIMATION_POLICIES;
    }

    /**
     * Builds a request from canonical parameters, as received over HTTP or produced by toParameters().
     *
     * Parsing is strict and accepts exactly these spellings (see the class documentation):
     * - keys: canonical names, case-sensitive;
     * - integers: PHP ints, or strings of decimal digits without sign, spaces or leading zeros;
     * - booleans: PHP bools, the PHP ints 1 and 0, or the strings "1", "0", "true" and "false";
     * - choices (format, fit, animation): strings among the documented values, case-sensitive.
     * Anything else is rejected, including null, floats, arrays, objects, "yes", "TRUE", " 1" and "08". A missing key
     * keeps its default: to obtain the per-format default quality or no resize, omit quality, width or height.
     *
     * @param array<mixed> $parameters parameter name => value
     *
     * @throws InvalidRequestException on the first unknown key or invalid value
     */
    public static function fromParameters(array $parameters): self
    {
        $request = new self();

        foreach ($parameters as $name => $value) {
            // Checked before the switch, which compares loosely: on PHP 7, 0 == 'format' is true.
            if (!is_string($name) || !in_array($name, self::PARAMETERS, true)) {
                $name = (string) $name;

                throw InvalidRequestException::forParameter(null, sprintf(
                    'unknown parameter%s; expected one of: %s',
                    self::isDisplayable($name) ? ' "' . $name . '"' : '',
                    implode(', ', self::PARAMETERS)
                ));
            }

            switch ($name) {
                case self::PARAM_FORMAT:
                    $request = $request->withFormat(self::parseString(self::PARAM_FORMAT, $value));
                    break;
                case self::PARAM_QUALITY:
                    $request = $request->withQuality(self::parseInt(self::PARAM_QUALITY, $value, self::QUALITY_MIN, self::QUALITY_MAX));
                    break;
                case self::PARAM_EFFORT:
                    $request = $request->withEffort(self::parseInt(self::PARAM_EFFORT, $value, self::EFFORT_MIN, self::EFFORT_MAX));
                    break;
                case self::PARAM_LOSSLESS:
                    $request = $request->withLossless(self::parseBool(self::PARAM_LOSSLESS, $value));
                    break;
                case self::PARAM_WIDTH:
                    $request = $request->withWidth(self::parseInt(self::PARAM_WIDTH, $value, self::DIMENSION_MIN, self::DIMENSION_MAX));
                    break;
                case self::PARAM_HEIGHT:
                    $request = $request->withHeight(self::parseInt(self::PARAM_HEIGHT, $value, self::DIMENSION_MIN, self::DIMENSION_MAX));
                    break;
                case self::PARAM_FIT:
                    $request = $request->withFit(self::parseString(self::PARAM_FIT, $value));
                    break;
                case self::PARAM_ENLARGE:
                    $request = $request->withEnlargementAllowed(self::parseBool(self::PARAM_ENLARGE, $value));
                    break;
                case self::PARAM_STRIP:
                    $request = $request->withStripMetadata(self::parseBool(self::PARAM_STRIP, $value));
                    break;
                case self::PARAM_KEEP_ICC:
                    $request = $request->withKeepIccProfile(self::parseBool(self::PARAM_KEEP_ICC, $value));
                    break;
                case self::PARAM_ANIMATION:
                    $request = $request->withAnimationPolicy(self::parseString(self::PARAM_ANIMATION, $value));
                    break;
            }
        }

        return $request;
    }

    /**
     * Canonical parameters of this request, in canonical order, as strings ready for a query string or form body.
     * Optional values that are not set (quality, width, height) are omitted; every other parameter is present.
     * fromParameters() turns the result back into an equal request.
     *
     * @return array<string, string>
     */
    public function toParameters(): array
    {
        $parameters = [self::PARAM_FORMAT => $this->format];
        if ($this->quality !== null) {
            $parameters[self::PARAM_QUALITY] = (string) $this->quality;
        }
        $parameters[self::PARAM_EFFORT] = (string) $this->effort;
        $parameters[self::PARAM_LOSSLESS] = self::formatBool($this->lossless);
        if ($this->width !== null) {
            $parameters[self::PARAM_WIDTH] = (string) $this->width;
        }
        if ($this->height !== null) {
            $parameters[self::PARAM_HEIGHT] = (string) $this->height;
        }
        $parameters[self::PARAM_FIT] = $this->fit;
        $parameters[self::PARAM_ENLARGE] = self::formatBool($this->enlargementAllowed);
        $parameters[self::PARAM_STRIP] = self::formatBool($this->stripMetadata);
        $parameters[self::PARAM_KEEP_ICC] = self::formatBool($this->keepIccProfile);
        $parameters[self::PARAM_ANIMATION] = $this->animationPolicy;

        return $parameters;
    }

    /**
     * Short, stable identifier of what this request asks for, for use in cache keys: 32 lowercase hexadecimal
     * characters (the first 128 bits of a SHA-256 digest).
     *
     * The digest covers the normalised parameters: values that cannot affect the output are folded together, so that
     * equivalent requests share one cache entry. Fit is ignored unless both width and height are set, enlarge is
     * ignored without width or height, and keep_icc is ignored when strip is false (all metadata is kept). An unset
     * quality is not resolved to a number, because the effective value depends on the output format (see
     * getEffectiveQuality()); it is hashed together with the per-format defaults, so a change of default changes the
     * fingerprint. The fingerprint is identical across PHP versions and processes. It identifies the request only:
     * a cache key must combine it with the identity of the input.
     */
    public function fingerprint(): string
    {
        $resizes = $this->width !== null || $this->height !== null;
        $boxed = $this->width !== null && $this->height !== null;

        $defaults = [];
        foreach (self::DEFAULT_QUALITIES as $format => $quality) {
            $defaults[] = $format . ':' . $quality;
        }

        $normalised = [
            'v' => (string) self::FINGERPRINT_VERSION,
            self::PARAM_FORMAT => $this->format,
            self::PARAM_QUALITY => $this->quality !== null ? (string) $this->quality : 'default(' . implode(',', $defaults) . ')',
            self::PARAM_EFFORT => (string) $this->effort,
            self::PARAM_LOSSLESS => self::formatBool($this->lossless),
            self::PARAM_WIDTH => $this->width !== null ? (string) $this->width : 'auto',
            self::PARAM_HEIGHT => $this->height !== null ? (string) $this->height : 'auto',
            self::PARAM_FIT => $boxed ? $this->fit : '-',
            self::PARAM_ENLARGE => $resizes ? self::formatBool($this->enlargementAllowed) : '-',
            self::PARAM_STRIP => self::formatBool($this->stripMetadata),
            self::PARAM_KEEP_ICC => $this->stripMetadata ? self::formatBool($this->keepIccProfile) : '-',
            self::PARAM_ANIMATION => $this->animationPolicy,
        ];

        $lines = [];
        foreach ($normalised as $name => $value) {
            $lines[] = $name . '=' . $value;
        }

        return substr(hash('sha256', implode("\n", $lines)), 0, 32);
    }

    /**
     * Target format: ImageFormat::WEBP, ImageFormat::AVIF or self::FORMAT_ORIGINAL.
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * @throws InvalidRequestException when the format is not webp, avif or original
     */
    public function withFormat(string $format): self
    {
        $format = self::checkChoice(self::PARAM_FORMAT, $format, self::TARGET_FORMATS);
        $clone = clone $this;
        $clone->format = $format;

        return $clone;
    }

    /**
     * Whether the target format is FORMAT_ORIGINAL.
     */
    public function keepsOriginalFormat(): bool
    {
        return $this->format === self::FORMAT_ORIGINAL;
    }

    /**
     * Format of the output for an input of the given format: the target format, or the input format when the target
     * is FORMAT_ORIGINAL. A converter may still fall back to another format when it cannot encode this one; the
     * format actually produced is ConversionResult::getOutputFormat().
     *
     * @param string $inputFormat ImageFormat value detected from the input content
     *
     * @throws InvalidArgumentException when the input format is not known (a programming error)
     */
    public function resolveOutputFormat(string $inputFormat): string
    {
        $inputFormat = self::assertKnownFormat($inputFormat);

        return $this->format === self::FORMAT_ORIGINAL ? $inputFormat : $this->format;
    }

    /**
     * Requested quality from 1 (smallest file) to 100 (best fidelity), or null for the per-format default.
     */
    public function getQuality(): ?int
    {
        return $this->quality;
    }

    /**
     * Quality the encoder uses for the given output format: the requested quality, or the default of that format
     * when none was requested (see defaultQualityFor()).
     *
     * It takes the output format, not the target format, because the two differ for FORMAT_ORIGINAL and when a
     * converter falls back to another format.
     *
     * @param string $outputFormat ImageFormat value of the output being encoded
     *
     * @throws InvalidArgumentException when the format is not known (a programming error)
     */
    public function getEffectiveQuality(string $outputFormat): int
    {
        $default = self::defaultQualityFor($outputFormat);

        return $this->quality !== null ? $this->quality : $default;
    }

    /**
     * Default quality of an output format: DEFAULT_QUALITY_WEBP for webp, DEFAULT_QUALITY_AVIF for avif and
     * DEFAULT_QUALITY_JPEG for every other format (lossless formats ignore it).
     *
     * @throws InvalidArgumentException when the format is not known (a programming error)
     */
    public static function defaultQualityFor(string $outputFormat): int
    {
        $outputFormat = self::assertKnownFormat($outputFormat);

        return isset(self::DEFAULT_QUALITIES[$outputFormat])
            ? self::DEFAULT_QUALITIES[$outputFormat]
            : self::DEFAULT_QUALITY_JPEG;
    }

    /**
     * @param int|null $quality 1 to 100, or null for the per-format default
     *
     * @throws InvalidRequestException when the quality is out of range
     */
    public function withQuality(?int $quality): self
    {
        if ($quality !== null) {
            $quality = self::checkRange(self::PARAM_QUALITY, $quality, self::QUALITY_MIN, self::QUALITY_MAX);
        }
        $clone = clone $this;
        $clone->quality = $quality;

        return $clone;
    }

    /**
     * Encoder effort on a normalised scale from 0 (fastest) to 9 (slowest, smallest output). Each backend maps it to
     * its own scale (for example cwebp -m 0-6 or avifenc --speed 10-0); it never changes the visual quality.
     */
    public function getEffort(): int
    {
        return $this->effort;
    }

    /**
     * @param int $effort 0 (fastest) to 9 (smallest output)
     *
     * @throws InvalidRequestException when the effort is out of range
     */
    public function withEffort(int $effort): self
    {
        $effort = self::checkRange(self::PARAM_EFFORT, $effort, self::EFFORT_MIN, self::EFFORT_MAX);
        $clone = clone $this;
        $clone->effort = $effort;

        return $clone;
    }

    /**
     * Whether lossless encoding is requested. When true the quality does not reduce fidelity. Ignored by output
     * formats without a lossless mode (JPEG) and by formats that are always lossless (PNG, GIF, BMP).
     */
    public function isLossless(): bool
    {
        return $this->lossless;
    }

    public function withLossless(bool $lossless): self
    {
        $clone = clone $this;
        $clone->lossless = $lossless;

        return $clone;
    }

    /**
     * Requested output width in pixels (1 to 16383), or null for no width constraint.
     *
     * With only width or only height set, the other dimension follows the input's aspect ratio and fit has no
     * effect. With both set, fit decides how the image is mapped onto the box. With neither, the image is not
     * resized.
     */
    public function getWidth(): ?int
    {
        return $this->width;
    }

    /**
     * @param int|null $width 1 to 16383 pixels, or null for no width constraint
     *
     * @throws InvalidRequestException when the width is out of range
     */
    public function withWidth(?int $width): self
    {
        if ($width !== null) {
            $width = self::checkRange(self::PARAM_WIDTH, $width, self::DIMENSION_MIN, self::DIMENSION_MAX);
        }
        $clone = clone $this;
        $clone->width = $width;

        return $clone;
    }

    /**
     * Requested output height in pixels (1 to 16383), or null for no height constraint. See getWidth().
     */
    public function getHeight(): ?int
    {
        return $this->height;
    }

    /**
     * @param int|null $height 1 to 16383 pixels, or null for no height constraint
     *
     * @throws InvalidRequestException when the height is out of range
     */
    public function withHeight(?int $height): self
    {
        if ($height !== null) {
            $height = self::checkRange(self::PARAM_HEIGHT, $height, self::DIMENSION_MIN, self::DIMENSION_MAX);
        }
        $clone = clone $this;
        $clone->height = $height;

        return $clone;
    }

    /**
     * Whether a width or a height is requested.
     */
    public function hasResize(): bool
    {
        return $this->width !== null || $this->height !== null;
    }

    /**
     * Size of the output for an input of the given size, after resize, fit and enlargement, as [width, height].
     *
     * - Neither width nor height: the input size.
     * - Only width (only height): that width (height), the other dimension following the input's aspect ratio.
     * - Both, FIT_CONTAIN: the largest size with the input's aspect ratio that fits inside the box.
     * - Both, FIT_COVER or FIT_FILL: the box (cover crops the overflow around the centre, fill stretches).
     * - Without enlargement no output dimension exceeds the corresponding input dimension: an input smaller than the
     *   requested size keeps its size (width only, height only, contain), a cover box larger than the input on
     *   either axis is scaled down with its own aspect ratio until it fits inside the input, and a fill box is
     *   clamped to the input on each axis.
     *
     * Computed dimensions are rounded half up and are never below 1. Only 64-bit integer arithmetic is used
     * (composer.json requires php-64bit), so the result is the same on every platform: the pipeline resizes to exactly
     * this size and the output verification expects it. The result is not bounded by DIMENSION_MAX (only one side up
     * to 16383 is requested; the other follows the input) and must be checked with
     * Capabilities::assertOutputWithinLimits() before any encoder runs, which refuses a side above
     * Capabilities::OUTPUT_DIMENSION_MAX.
     *
     * @param int $inputWidth  input width in pixels, 1 to Capabilities::LIMIT_VALUE_MAX
     * @param int $inputHeight input height in pixels, 1 to Capabilities::LIMIT_VALUE_MAX
     *
     * @throws InvalidArgumentException when an input dimension is out of range (a programming error: the input is
     *                                  checked against the converter's limits first)
     *
     * @return array{0: int, 1: int}
     */
    public function resolveOutputSize(int $inputWidth, int $inputHeight): array
    {
        if ($inputWidth < 1 || $inputHeight < 1 || $inputWidth > Capabilities::LIMIT_VALUE_MAX || $inputHeight > Capabilities::LIMIT_VALUE_MAX) {
            throw new InvalidArgumentException(sprintf(
                'The input width and height must be from 1 to %d, got %d x %d.',
                Capabilities::LIMIT_VALUE_MAX,
                $inputWidth,
                $inputHeight
            ));
        }

        $boxWidth = $this->width;
        $boxHeight = $this->height;
        $enlarge = $this->enlargementAllowed;

        if ($boxWidth === null && $boxHeight === null) {
            return [$inputWidth, $inputHeight];
        }
        if ($boxHeight === null) {
            return !$enlarge && $boxWidth >= $inputWidth
                ? [$inputWidth, $inputHeight]
                : [$boxWidth, self::scale($inputHeight, $boxWidth, $inputWidth)];
        }
        if ($boxWidth === null) {
            return !$enlarge && $boxHeight >= $inputHeight
                ? [$inputWidth, $inputHeight]
                : [self::scale($inputWidth, $boxHeight, $inputHeight), $boxHeight];
        }

        if ($this->fit === self::FIT_FILL) {
            return $enlarge
                ? [$boxWidth, $boxHeight]
                : [min($boxWidth, $inputWidth), min($boxHeight, $inputHeight)];
        }

        if ($this->fit === self::FIT_COVER) {
            if ($enlarge || ($boxWidth <= $inputWidth && $boxHeight <= $inputHeight)) {
                return [$boxWidth, $boxHeight];
            }

            // inputWidth / boxWidth <= inputHeight / boxHeight: the box is scaled to the input width.
            return $inputWidth * $boxHeight <= $inputHeight * $boxWidth
                ? [$inputWidth, self::scale($boxHeight, $inputWidth, $boxWidth)]
                : [self::scale($boxWidth, $inputHeight, $boxHeight), $inputHeight];
        }

        if (!$enlarge && $boxWidth >= $inputWidth && $boxHeight >= $inputHeight) {
            return [$inputWidth, $inputHeight];
        }

        // boxWidth / inputWidth <= boxHeight / inputHeight: the input is scaled to the box width.
        return $boxWidth * $inputHeight <= $boxHeight * $inputWidth
            ? [$boxWidth, self::scale($inputHeight, $boxWidth, $inputWidth)]
            : [self::scale($inputWidth, $boxHeight, $inputHeight), $boxHeight];
    }

    /**
     * Number of frames of the output for an input with the given number of frames, after the animation policy.
     *
     * A still input gives 1. An animated input is refused under ANIMATION_REJECT, gives 1 under
     * ANIMATION_FIRST_FRAME, and keeps every frame under ANIMATION_PRESERVE when the output format can be produced
     * with several frames (1 otherwise). Like resolveOutputSize(), the result must be checked with
     * Capabilities::assertOutputWithinLimits() before any encoder runs.
     *
     * @param int  $inputFrameCount         number of frames of the input, at least 1
     * @param bool $animatedOutputSupported whether the converter can produce the output format with several frames
     *                                      (Capabilities::supportsAnimatedOutput() of that format)
     *
     * @throws InvalidImageException    with error code animation_rejected, when the input is animated and the policy
     *                                  is ANIMATION_REJECT
     * @throws InvalidArgumentException when the frame count is below 1 (a programming error)
     */
    public function resolveOutputFrameCount(int $inputFrameCount, bool $animatedOutputSupported): int
    {
        if ($inputFrameCount < 1) {
            throw new InvalidArgumentException(sprintf('The input frame count must be at least 1, got %d.', $inputFrameCount));
        }
        if ($inputFrameCount === 1) {
            return 1;
        }
        if ($this->animationPolicy === self::ANIMATION_REJECT) {
            throw InvalidImageException::animationRejected($inputFrameCount);
        }

        return $this->animationPolicy === self::ANIMATION_PRESERVE && $animatedOutputSupported ? $inputFrameCount : 1;
    }

    /**
     * How the image is mapped onto a width x height box: FIT_CONTAIN, FIT_COVER or FIT_FILL. Only used when both
     * width and height are set.
     */
    public function getFit(): string
    {
        return $this->fit;
    }

    /**
     * @throws InvalidRequestException when the fit is not contain, cover or fill
     */
    public function withFit(string $fit): self
    {
        $fit = self::checkChoice(self::PARAM_FIT, $fit, self::FITS);
        $clone = clone $this;
        $clone->fit = $fit;

        return $clone;
    }

    /**
     * Whether the image may be scaled up. When false (the default) neither output dimension ever exceeds the
     * corresponding input dimension: a box larger than the input is reduced accordingly.
     */
    public function isEnlargementAllowed(): bool
    {
        return $this->enlargementAllowed;
    }

    public function withEnlargementAllowed(bool $allowed): self
    {
        $clone = clone $this;
        $clone->enlargementAllowed = $allowed;

        return $clone;
    }

    /**
     * Whether metadata (EXIF, XMP, IPTC, comments) is removed from the output. When false, all metadata the output
     * format can carry is kept, including the ICC profile.
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
     * Whether the ICC colour profile survives metadata stripping, so that colours render as in the input. Only
     * meaningful when shouldStripMetadata() is true.
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
     * What to do with animated input: ANIMATION_PRESERVE, ANIMATION_FIRST_FRAME or ANIMATION_REJECT.
     */
    public function getAnimationPolicy(): string
    {
        return $this->animationPolicy;
    }

    /**
     * @throws InvalidRequestException when the policy is not preserve, first_frame or reject
     */
    public function withAnimationPolicy(string $policy): self
    {
        $policy = self::checkChoice(self::PARAM_ANIMATION, $policy, self::ANIMATION_POLICIES);
        $clone = clone $this;
        $clone->animationPolicy = $policy;

        return $clone;
    }

    /**
     * Copy of this request rebuilt from its property values by fromParameters(), so that an unserialized request
     * cannot hold a value outside the documented domain (for example the format "gif"); see
     * ValidatedSerializationTrait.
     *
     * @throws InvalidRequestException when a property value is not a valid parameter value
     */
    private function validatedCopy(): self
    {
        $state = get_object_vars($this);
        $parameters = [];
        foreach (self::PROPERTY_PARAMETERS as $property => $parameter) {
            if (isset($state[$property])) {
                $parameters[$parameter] = $state[$property];
            }
        }

        return self::fromParameters($parameters);
    }

    /**
     * @param list<string> $choices
     *
     * @throws InvalidRequestException
     */
    private static function checkChoice(string $name, string $value, array $choices): string
    {
        if (!in_array($value, $choices, true)) {
            throw InvalidRequestException::forParameter($name, sprintf(
                'expected one of %s, got %s',
                implode(', ', $choices),
                self::describe($value)
            ));
        }

        return $value;
    }

    /**
     * @throws InvalidRequestException
     */
    private static function checkRange(string $name, int $value, int $min, int $max): int
    {
        if ($value < $min || $value > $max) {
            throw InvalidRequestException::forParameter(
                $name,
                sprintf('expected an integer from %d to %d, got %d', $min, $max, $value)
            );
        }

        return $value;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertKnownFormat(string $format): string
    {
        if (!ImageFormat::isValid($format)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown image format %s; expected one of: %s.',
                self::describe($format),
                implode(', ', ImageFormat::all())
            ));
        }

        return $format;
    }

    /**
     * @param mixed $value
     *
     * @throws InvalidRequestException
     */
    private static function parseString(string $name, $value): string
    {
        if (!is_string($value)) {
            throw InvalidRequestException::forParameter($name, sprintf('expected a string, got %s', self::describeType($value)));
        }

        return $value;
    }

    /**
     * @param mixed $value
     *
     * @throws InvalidRequestException
     */
    private static function parseInt(string $name, $value, int $min, int $max): int
    {
        if (is_int($value)) {
            return self::checkRange($name, $value, $min, $max);
        }
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1) {
            // Every bound has at most 9 digits: a longer value is out of range and may not even fit in an int.
            if (strlen($value) > 9) {
                throw InvalidRequestException::forParameter(
                    $name,
                    sprintf('expected an integer from %d to %d, got %s', $min, $max, self::describe($value))
                );
            }

            return self::checkRange($name, (int) $value, $min, $max);
        }

        throw InvalidRequestException::forParameter($name, sprintf(
            'expected an integer written in decimal digits without sign, spaces or leading zeros, got %s',
            self::describeValue($value)
        ));
    }

    /**
     * @param mixed $value
     *
     * @throws InvalidRequestException
     */
    private static function parseBool(string $name, $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        throw InvalidRequestException::forParameter(
            $name,
            sprintf('expected 1, 0, true or false, got %s', self::describeValue($value))
        );
    }

    /**
     * Rounds $value * $numerator / $denominator half up, with a minimum of 1. Callers keep one factor of the product
     * at most DIMENSION_MAX (below 2^14) and the other at most Capabilities::LIMIT_VALUE_MAX (below 2^31), so
     * 2 * $value * $numerator + $denominator stays below 2^47 and cannot overflow a 64-bit integer (32-bit PHP is not
     * supported: composer.json requires php-64bit).
     */
    private static function scale(int $value, int $numerator, int $denominator): int
    {
        return max(1, intdiv(2 * $value * $numerator + $denominator, 2 * $denominator));
    }

    private static function formatBool(bool $value): string
    {
        return $value ? '1' : '0';
    }

    /**
     * Quotes a user-supplied string for an error message only when it is a short token of safe characters, so that
     * messages can be returned to clients and written to logs without escaping.
     */
    private static function describe(string $value): string
    {
        if (self::isDisplayable($value)) {
            return '"' . $value . '"';
        }

        return $value === '' ? 'an empty string' : 'a value that cannot be displayed';
    }

    /**
     * Whether a user-supplied string is a short token of characters that are safe in messages, logs and headers.
     */
    private static function isDisplayable(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9_.+-]{1,32}\z/', $value) === 1;
    }

    /**
     * Describes a user-supplied value of any type for an error message, see describe().
     *
     * @param mixed $value
     */
    private static function describeValue($value): string
    {
        if (is_string($value)) {
            return self::describe($value);
        }

        return is_int($value) ? (string) $value : self::describeType($value);
    }

    /**
     * @param mixed $value
     */
    private static function describeType($value): string
    {
        if ($value === null) {
            return 'null (omit the parameter to use its default)';
        }
        if (is_bool($value)) {
            return 'a boolean';
        }
        if (is_int($value)) {
            return 'an integer';
        }
        if (is_float($value)) {
            return 'a float';
        }
        if (is_string($value)) {
            return 'a string';
        }
        if (is_array($value)) {
            return 'an array';
        }

        return is_object($value) ? 'an object' : 'a resource';
    }
}
