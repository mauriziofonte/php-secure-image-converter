<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;

/**
 * What a converter can do on this host: accepted input formats, producible output formats (with and without
 * animation), available backends, the isolation level it enforces and the limits it applies.
 *
 * Limits bound both sides of a conversion. max_bytes bounds the input file. The dimension, pixel and frame limits
 * bound the input, checked on header data before any decoder runs (assertInputWithinLimits()), and the output
 * resolved from the request, checked before any encoder runs (assertOutputWithinLimits() on
 * ConversionRequest::resolveOutputSize() and resolveOutputFrameCount()); the output width and height are also capped
 * at OUTPUT_DIMENSION_MAX, the largest size every output format can encode. A violation throws
 * LimitExceededException: error code file_too_large (413) for max_bytes, image_too_large (422) for every other limit.
 * The checks use 64-bit integer arithmetic, exact for every value up to LIMIT_VALUE_MAX: composer.json requires a
 * 64-bit PHP (php-64bit).
 *
 * Immutable value object. Format lists are normalised to the canonical order of ImageFormat::all(); backends keep
 * their order, which is the order of preference. Every property has a default, and the defaults form the most
 * restrictive valid capabilities (no format, no backend, isolation level none, every limit 1), so that an instance
 * created without the constructor refuses everything instead of holding a value outside the documented domain; see
 * ValidatedSerializationTrait. The conversion API publishes toArray() as JSON on /healthz and the
 * proxy reads it back with fromArray(), which is strict and versioned: the array carries a "schema" key whose value
 * is SCHEMA_VERSION, and any other version, missing key, unknown key or invalid value is rejected. Shape of schema 1:
 *
 *     {
 *       "schema": 1,
 *       "input_formats": ["jpeg", "png", "gif", "bmp"],
 *       "output_formats": ["jpeg", "png", "gif", "bmp", "webp", "avif"],
 *       "animated_output_formats": ["gif", "webp"],
 *       "backends": ["vips", "cwebp", "gif2webp"],
 *       "isolation_level": "rlimit",
 *       "limits": {
 *         "max_bytes": 20971520,
 *         "max_width": 8192,
 *         "max_height": 8192,
 *         "max_megapixels": 40,
 *         "max_frames": 300,
 *         "max_total_megapixels": 400
 *       }
 *     }
 */
final class Capabilities
{
    use ValidatedSerializationTrait;

    /**
     * Version of the array shape produced by toArray() and accepted by fromArray().
     */
    public const SCHEMA_VERSION = 1;

    /**
     * Maximum input file size in bytes. It does not apply to the output.
     */
    public const LIMIT_MAX_BYTES = 'max_bytes';

    /**
     * Maximum width in pixels of the input and of the resolved output (the latter never above OUTPUT_DIMENSION_MAX).
     */
    public const LIMIT_MAX_WIDTH = 'max_width';

    /**
     * Maximum height in pixels of the input and of the resolved output (the latter never above OUTPUT_DIMENSION_MAX).
     */
    public const LIMIT_MAX_HEIGHT = 'max_height';

    /**
     * Maximum area of one frame in millions of pixels (width x height must not exceed this value x 1,000,000), for
     * the input and the resolved output.
     */
    public const LIMIT_MAX_MEGAPIXELS = 'max_megapixels';

    /**
     * Maximum number of frames of the input and of the resolved output.
     */
    public const LIMIT_MAX_FRAMES = 'max_frames';

    /**
     * Maximum area of all frames together in millions of pixels (frames x width x height must not exceed this value x
     * 1,000,000), for the input and the resolved output: the decoding and encoding budget of an animation.
     */
    public const LIMIT_MAX_TOTAL_MEGAPIXELS = 'max_total_megapixels';

    /**
     * Largest value of any limit, 2^31 - 1, so that limits read exactly in any JSON consumer and that pixel
     * arithmetic on values within the limits cannot overflow a 64-bit integer (32-bit PHP is not supported:
     * composer.json requires php-64bit).
     */
    public const LIMIT_VALUE_MAX = 2147483647;

    /**
     * Largest width and height of a resolved output, whatever max_width and max_height allow: 16383 px
     * (ConversionRequest::DIMENSION_MAX), the largest dimension WEBP can encode and the smallest maximum among the
     * output formats. assertOutputWithinLimits() refuses a wider or taller output with image_too_large (422) before
     * any encoder runs, so that a size no encoder of the fallback chain could write never ends as conversion_failed
     * (500). Inputs may be larger, up to max_width and max_height, and be resized down.
     */
    public const OUTPUT_DIMENSION_MAX = ConversionRequest::DIMENSION_MAX;

    /**
     * @var list<string>
     */
    private const LIMIT_NAMES = [
        self::LIMIT_MAX_BYTES,
        self::LIMIT_MAX_WIDTH,
        self::LIMIT_MAX_HEIGHT,
        self::LIMIT_MAX_MEGAPIXELS,
        self::LIMIT_MAX_FRAMES,
        self::LIMIT_MAX_TOTAL_MEGAPIXELS,
    ];

    /**
     * @var list<string>
     */
    private const KEYS = [
        'schema',
        'input_formats',
        'output_formats',
        'animated_output_formats',
        'backends',
        'isolation_level',
        'limits',
    ];

    /**
     * @var list<string>
     */
    private $inputFormats = [];

    /**
     * @var list<string>
     */
    private $outputFormats = [];

    /**
     * @var list<string>
     */
    private $animatedOutputFormats = [];

    /**
     * @var list<string>
     */
    private $backends = [];

    /**
     * @var string
     */
    private $isolationLevel = IsolationLevel::NONE;

    /**
     * @var array<string, int>
     */
    private $limits = [
        self::LIMIT_MAX_BYTES => 1,
        self::LIMIT_MAX_WIDTH => 1,
        self::LIMIT_MAX_HEIGHT => 1,
        self::LIMIT_MAX_MEGAPIXELS => 1,
        self::LIMIT_MAX_FRAMES => 1,
        self::LIMIT_MAX_TOTAL_MEGAPIXELS => 1,
    ];

    /**
     * @param list<string>       $inputFormats          ImageFormat values accepted as input
     * @param list<string>       $outputFormats         ImageFormat values that can be produced
     * @param list<string>       $animatedOutputFormats subset of $outputFormats that can be produced with several
     *                                                  frames; only ImageFormat::animatable() formats qualify
     * @param list<string>       $backends              names of the available backends, in order of preference (see
     *                                                  isValidBackendName())
     * @param string             $isolationLevel        IsolationLevel value enforced for conversions on this host
     * @param array<string, int> $limits                exactly the six LIMIT_* keys, each value from 1 to
     *                                                  LIMIT_VALUE_MAX
     *
     * @throws InvalidArgumentException when a value is outside its documented domain, a list contains duplicates,
     *                                  or an animated output format is not an animatable output format
     */
    public function __construct(
        array $inputFormats,
        array $outputFormats,
        array $animatedOutputFormats,
        array $backends,
        string $isolationLevel,
        array $limits
    ) {
        $this->inputFormats = self::checkFormats('input_formats', $inputFormats);
        $this->outputFormats = self::checkFormats('output_formats', $outputFormats);
        $this->animatedOutputFormats = self::checkFormats('animated_output_formats', $animatedOutputFormats);
        if (array_diff($this->animatedOutputFormats, array_intersect($this->outputFormats, ImageFormat::animatable())) !== []) {
            throw new InvalidArgumentException(sprintf(
                'Capabilities: every animated output format must be an output format among: %s.',
                implode(', ', ImageFormat::animatable())
            ));
        }
        $this->backends = self::checkBackends($backends);
        $this->isolationLevel = self::checkIsolationLevel($isolationLevel);
        $this->limits = self::checkLimits($limits);
    }

    /**
     * Rebuilds capabilities from toArray() output, typically decoded from the conversion API's /healthz JSON.
     *
     * @param array<mixed> $data
     *
     * @throws InvalidArgumentException when the schema version is not SCHEMA_VERSION, a key is missing or unknown,
     *                                  or a value is invalid
     */
    public static function fromArray(array $data): self
    {
        if (!array_key_exists('schema', $data)) {
            throw new InvalidArgumentException('Capabilities: the "schema" key is missing.');
        }
        if ($data['schema'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Capabilities: unsupported schema version; this library reads version %d only.',
                self::SCHEMA_VERSION
            ));
        }
        self::checkKeys('capabilities', $data, self::KEYS);

        $isolationLevel = $data['isolation_level'];
        if (!is_string($isolationLevel)) {
            throw new InvalidArgumentException('Capabilities: "isolation_level" must be a string.');
        }
        $limits = $data['limits'];
        if (!is_array($limits)) {
            throw new InvalidArgumentException('Capabilities: "limits" must be an object.');
        }

        return new self(
            self::stringList('input_formats', $data['input_formats']),
            self::stringList('output_formats', $data['output_formats']),
            self::stringList('animated_output_formats', $data['animated_output_formats']),
            self::stringList('backends', $data['backends']),
            $isolationLevel,
            self::checkLimits($limits)
        );
    }

    /**
     * Whether a string is a valid backend name: 1 to 32 lowercase letters, digits, "_" or "-", starting with a
     * letter or digit, and not "none", which is reserved for ConversionResult::BACKEND_NONE.
     */
    public static function isValidBackendName(string $name): bool
    {
        return $name !== ConversionResult::BACKEND_NONE && preg_match('/\A[a-z0-9][a-z0-9_-]{0,31}\z/', $name) === 1;
    }

    /**
     * Limit names, in canonical order.
     *
     * @return list<string>
     */
    public static function limitNames(): array
    {
        return self::LIMIT_NAMES;
    }

    /**
     * ImageFormat values accepted as input, in canonical order.
     *
     * @return list<string>
     */
    public function getInputFormats(): array
    {
        return $this->inputFormats;
    }

    /**
     * ImageFormat values that can be produced, in canonical order.
     *
     * @return list<string>
     */
    public function getOutputFormats(): array
    {
        return $this->outputFormats;
    }

    /**
     * ImageFormat values that can be produced with several frames, in canonical order.
     *
     * @return list<string>
     */
    public function getAnimatedOutputFormats(): array
    {
        return $this->animatedOutputFormats;
    }

    /**
     * Names of the available backends, in order of preference.
     *
     * @return list<string>
     */
    public function getBackends(): array
    {
        return $this->backends;
    }

    /**
     * IsolationLevel value enforced for conversions on this host.
     */
    public function getIsolationLevel(): string
    {
        return $this->isolationLevel;
    }

    /**
     * Limits keyed by LIMIT_* name, in canonical order.
     *
     * @return array<string, int>
     */
    public function getLimits(): array
    {
        return $this->limits;
    }

    /**
     * Value of one limit.
     *
     * @param string $name one of the LIMIT_* constants
     *
     * @throws InvalidArgumentException when the name is not a LIMIT_* value
     */
    public function getLimit(string $name): int
    {
        if (!isset($this->limits[$name])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown limit; expected one of: %s.',
                implode(', ', self::LIMIT_NAMES)
            ));
        }

        return $this->limits[$name];
    }

    /**
     * Whether images of this format are accepted as input.
     */
    public function supportsInput(string $format): bool
    {
        return in_array($format, $this->inputFormats, true);
    }

    /**
     * Whether images of this format can be produced.
     */
    public function supportsOutput(string $format): bool
    {
        return in_array($format, $this->outputFormats, true);
    }

    /**
     * Whether animated images of this format can be produced.
     */
    public function supportsAnimatedOutput(string $format): bool
    {
        return in_array($format, $this->animatedOutputFormats, true);
    }

    /**
     * Checks the size of an input file against max_bytes.
     *
     * @param int $bytes size of the input file, at least 0
     *
     * @throws LimitExceededException   with error code file_too_large when the file is larger than max_bytes
     * @throws InvalidArgumentException when the size is negative (a programming error)
     */
    public function assertFileSizeWithinLimits(int $bytes): void
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException(sprintf('The file size must be at least 0, got %d.', $bytes));
        }
        if ($bytes > $this->limits[self::LIMIT_MAX_BYTES]) {
            throw LimitExceededException::forInput(self::LIMIT_MAX_BYTES, $this->limits[self::LIMIT_MAX_BYTES], $bytes);
        }
    }

    /**
     * Checks an input, as described by its header, against the dimension, pixel and frame limits. Limits are
     * checked in the order of limitNames() and the first one exceeded is reported.
     *
     * @param int $width      input width in pixels, at least 1
     * @param int $height     input height in pixels, at least 1
     * @param int $frameCount number of frames of the input, at least 1
     *
     * @throws LimitExceededException   with error code image_too_large when a limit is exceeded
     * @throws InvalidArgumentException when a value is below 1 (a programming error)
     */
    public function assertInputWithinLimits(int $width, int $height, int $frameCount): void
    {
        $this->assertImageWithinLimits($width, $height, $frameCount, false);
    }

    /**
     * Checks the output resolved from a request (ConversionRequest::resolveOutputSize() and
     * resolveOutputFrameCount()) against the dimension, pixel and frame limits, before any encoder runs. Limits are
     * checked in the order of limitNames() and the first one exceeded is reported. Width and height are checked
     * against max_width and max_height capped at OUTPUT_DIMENSION_MAX, which the exception then reports as the
     * maximum.
     *
     * @param int $width      resolved output width in pixels, at least 1
     * @param int $height     resolved output height in pixels, at least 1
     * @param int $frameCount resolved number of output frames, at least 1
     *
     * @throws LimitExceededException   with error code image_too_large when a limit is exceeded
     * @throws InvalidArgumentException when a value is below 1 (a programming error)
     */
    public function assertOutputWithinLimits(int $width, int $height, int $frameCount): void
    {
        $this->assertImageWithinLimits($width, $height, $frameCount, true);
    }

    /**
     * Versioned array representation, ready for JSON encoding. fromArray() turns it back into equal capabilities.
     *
     * @return array{schema: int, input_formats: list<string>, output_formats: list<string>, animated_output_formats: list<string>, backends: list<string>, isolation_level: string, limits: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'input_formats' => $this->inputFormats,
            'output_formats' => $this->outputFormats,
            'animated_output_formats' => $this->animatedOutputFormats,
            'backends' => $this->backends,
            'isolation_level' => $this->isolationLevel,
            'limits' => $this->limits,
        ];
    }

    /**
     * Copy of these capabilities rebuilt from their property values by fromArray(), so that unserialized
     * capabilities cannot hold a value outside the documented domain; see ValidatedSerializationTrait. toArray()
     * copies the properties without converting them, so fromArray() sees them as they are.
     *
     * @throws InvalidArgumentException when a property value is not valid
     */
    private function validatedCopy(): self
    {
        return self::fromArray($this->toArray());
    }

    /**
     * @throws LimitExceededException
     * @throws InvalidArgumentException
     */
    private function assertImageWithinLimits(int $width, int $height, int $frameCount, bool $output): void
    {
        if ($width < 1 || $height < 1 || $frameCount < 1) {
            throw new InvalidArgumentException(sprintf(
                'The width, height and frame count must be at least 1, got %d x %d and %d frames.',
                $width,
                $height,
                $frameCount
            ));
        }

        $maxWidth = $this->limits[self::LIMIT_MAX_WIDTH];
        $maxHeight = $this->limits[self::LIMIT_MAX_HEIGHT];
        if ($output) {
            $maxWidth = min($maxWidth, self::OUTPUT_DIMENSION_MAX);
            $maxHeight = min($maxHeight, self::OUTPUT_DIMENSION_MAX);
        }
        self::assertAtMost(self::LIMIT_MAX_WIDTH, $maxWidth, $width, $output);
        self::assertAtMost(self::LIMIT_MAX_HEIGHT, $maxHeight, $height, $output);

        // Width and height are now at most LIMIT_VALUE_MAX, and so is every limit: none of these products overflows
        // a 64-bit integer.
        $pixels = $width * $height;
        self::assertAtMost(self::LIMIT_MAX_MEGAPIXELS, $this->limits[self::LIMIT_MAX_MEGAPIXELS] * 1000000, $pixels, $output);
        self::assertAtMost(self::LIMIT_MAX_FRAMES, $this->limits[self::LIMIT_MAX_FRAMES], $frameCount, $output);

        // frames x pixels can overflow: compare by division, and report the product saturated at PHP_INT_MAX.
        $maxTotalPixels = $this->limits[self::LIMIT_MAX_TOTAL_MEGAPIXELS] * 1000000;
        if ($frameCount > intdiv($maxTotalPixels, $pixels)) {
            $totalPixels = $frameCount > intdiv(PHP_INT_MAX, $pixels) ? PHP_INT_MAX : $frameCount * $pixels;

            throw self::limitExceeded(self::LIMIT_MAX_TOTAL_MEGAPIXELS, $maxTotalPixels, $totalPixels, $output);
        }
    }

    /**
     * @throws LimitExceededException
     */
    private static function assertAtMost(string $limit, int $maximum, int $actual, bool $output): void
    {
        if ($actual > $maximum) {
            throw self::limitExceeded($limit, $maximum, $actual, $output);
        }
    }

    private static function limitExceeded(string $limit, int $maximum, int $actual, bool $output): LimitExceededException
    {
        return $output
            ? LimitExceededException::forOutput($limit, $maximum, $actual)
            : LimitExceededException::forInput($limit, $maximum, $actual);
    }

    /**
     * @param array<mixed> $formats
     *
     * @throws InvalidArgumentException
     *
     * @return list<string>
     */
    private static function checkFormats(string $field, array $formats): array
    {
        $formats = self::stringList($field, $formats);
        foreach ($formats as $format) {
            if (!ImageFormat::isValid($format)) {
                throw new InvalidArgumentException(sprintf(
                    'Capabilities: "%s" contains an unknown format; expected values among: %s.',
                    $field,
                    implode(', ', ImageFormat::all())
                ));
            }
        }
        self::assertUnique($field, $formats);

        return array_values(array_intersect(ImageFormat::all(), $formats));
    }

    /**
     * @param array<mixed> $backends
     *
     * @throws InvalidArgumentException
     *
     * @return list<string>
     */
    private static function checkBackends(array $backends): array
    {
        $backends = self::stringList('backends', $backends);
        foreach ($backends as $backend) {
            if (!self::isValidBackendName($backend)) {
                throw new InvalidArgumentException(
                    'Capabilities: backend names must be 1 to 32 lowercase letters, digits, "_" or "-", starting with a letter or digit, and not "none".'
                );
            }
        }
        self::assertUnique('backends', $backends);

        return $backends;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function checkIsolationLevel(string $level): string
    {
        if (!IsolationLevel::isValid($level)) {
            throw new InvalidArgumentException(sprintf(
                'Capabilities: "isolation_level" must be one of: %s.',
                implode(', ', IsolationLevel::all())
            ));
        }

        return $level;
    }

    /**
     * @param array<mixed> $limits
     *
     * @throws InvalidArgumentException
     *
     * @return array<string, int>
     */
    private static function checkLimits(array $limits): array
    {
        self::checkKeys('limits', $limits, self::LIMIT_NAMES);

        $checked = [];
        foreach (self::LIMIT_NAMES as $name) {
            $value = $limits[$name];
            if (!is_int($value) || $value < 1 || $value > self::LIMIT_VALUE_MAX) {
                throw new InvalidArgumentException(sprintf(
                    'Capabilities: limit "%s" must be an integer from 1 to %d.',
                    $name,
                    self::LIMIT_VALUE_MAX
                ));
            }
            $checked[$name] = $value;
        }

        return $checked;
    }

    /**
     * Requires exactly the expected keys. Unknown keys are reported without echoing them, as they may come from an
     * untrusted source.
     *
     * @param array<mixed> $data
     * @param list<string> $expected
     *
     * @throws InvalidArgumentException
     */
    private static function checkKeys(string $what, array $data, array $expected): void
    {
        foreach ($expected as $key) {
            if (!array_key_exists($key, $data)) {
                throw new InvalidArgumentException(sprintf('Capabilities: the "%s" key is missing from %s.', $key, $what));
            }
        }
        if (count($data) !== count($expected)) {
            throw new InvalidArgumentException(sprintf(
                'Capabilities: %s contains unknown keys; expected exactly: %s.',
                $what,
                implode(', ', $expected)
            ));
        }
    }

    /**
     * @param mixed $value
     *
     * @throws InvalidArgumentException
     *
     * @return list<string>
     */
    private static function stringList(string $field, $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('Capabilities: "%s" must be a list of strings.', $field));
        }

        $list = [];
        $expectedKey = 0;
        foreach ($value as $key => $item) {
            if ($key !== $expectedKey || !is_string($item)) {
                throw new InvalidArgumentException(sprintf('Capabilities: "%s" must be a list of strings.', $field));
            }
            $list[] = $item;
            ++$expectedKey;
        }

        return $list;
    }

    /**
     * @param list<string> $values
     *
     * @throws InvalidArgumentException
     */
    private static function assertUnique(string $field, array $values): void
    {
        if (count(array_unique($values)) !== count($values)) {
            throw new InvalidArgumentException(sprintf('Capabilities: "%s" contains duplicates.', $field));
        }
    }
}
