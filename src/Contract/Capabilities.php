<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * What a converter can do on this host: accepted input formats, producible output formats (with and without
 * animation), available backends, the isolation level it enforces and the input limits it applies.
 *
 * Immutable value object. Format lists are normalised to the canonical order of ImageFormat::all(); backends keep
 * their order, which is the order of preference. The conversion API publishes toArray() as JSON on /healthz and the
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
 *       "limits": {"max_bytes": 20971520, "max_width": 8192, "max_height": 8192, "max_megapixels": 40, "max_frames": 300}
 *     }
 */
final class Capabilities
{
    /**
     * Version of the array shape produced by toArray() and accepted by fromArray().
     */
    public const SCHEMA_VERSION = 1;

    /**
     * Maximum input file size in bytes.
     */
    public const LIMIT_MAX_BYTES = 'max_bytes';

    /**
     * Maximum input width in pixels.
     */
    public const LIMIT_MAX_WIDTH = 'max_width';

    /**
     * Maximum input height in pixels.
     */
    public const LIMIT_MAX_HEIGHT = 'max_height';

    /**
     * Maximum input area in millions of pixels (width x height / 1,000,000), per frame.
     */
    public const LIMIT_MAX_MEGAPIXELS = 'max_megapixels';

    /**
     * Maximum number of frames of an animated input.
     */
    public const LIMIT_MAX_FRAMES = 'max_frames';

    /**
     * @var list<string>
     */
    private const LIMIT_NAMES = [
        self::LIMIT_MAX_BYTES,
        self::LIMIT_MAX_WIDTH,
        self::LIMIT_MAX_HEIGHT,
        self::LIMIT_MAX_MEGAPIXELS,
        self::LIMIT_MAX_FRAMES,
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
    private $inputFormats;

    /**
     * @var list<string>
     */
    private $outputFormats;

    /**
     * @var list<string>
     */
    private $animatedOutputFormats;

    /**
     * @var list<string>
     */
    private $backends;

    /**
     * @var string
     */
    private $isolationLevel;

    /**
     * @var array<string, int>
     */
    private $limits;

    /**
     * @param list<string>       $inputFormats          ImageFormat values accepted as input
     * @param list<string>       $outputFormats         ImageFormat values that can be produced
     * @param list<string>       $animatedOutputFormats subset of $outputFormats that can be produced with several
     *                                                  frames
     * @param list<string>       $backends              names of the available backends, in order of preference
     *                                                  (lowercase letters, digits, "_" or "-", at most 32 characters)
     * @param string             $isolationLevel        IsolationLevel value enforced for conversions on this host
     * @param array<string, int> $limits                exactly the five LIMIT_* keys, each value at least 1
     *
     * @throws InvalidArgumentException when a value is outside its documented domain, a list contains duplicates,
     *                                  or an animated output format is not an output format
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
        if (array_diff($this->animatedOutputFormats, $this->outputFormats) !== []) {
            throw new InvalidArgumentException('Capabilities: every animated output format must also be an output format.');
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
     * letter or digit.
     */
    public static function isValidBackendName(string $name): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9_-]{0,31}\z/', $name) === 1;
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
     * Input limits keyed by LIMIT_* name, in canonical order.
     *
     * @return array<string, int>
     */
    public function getLimits(): array
    {
        return $this->limits;
    }

    /**
     * Value of one input limit.
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
                    'Capabilities: backend names must be 1 to 32 lowercase letters, digits, "_" or "-", starting with a letter or digit.'
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
            if (!is_int($value) || $value < 1) {
                throw new InvalidArgumentException(sprintf('Capabilities: limit "%s" must be an integer of at least 1.', $name));
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
