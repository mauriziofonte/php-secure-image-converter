<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Contract\ValidatedSerializationTrait;

/**
 * The size, dimension, pixel and frame limits a converter applies to its input, with documented safe defaults.
 *
 * The limit names, units and checks are exactly those of Capabilities (Capabilities::limitNames()): toArray() is
 * the "limits" value a converter passes to the Capabilities it reports, fromCapabilities() reads them back, and
 * assertFileSize() and assertImage() delegate to Capabilities::assertFileSizeWithinLimits() and
 * assertInputWithinLimits(), so that the limits a converter enforces and the limits it publishes cannot diverge.
 *
 * Defaults, the values of the example in the Capabilities documentation:
 * - max_bytes 20971520 (20 MiB) bytes of input file: above nearly every image published on the web;
 * - max_width and max_height 8192 pixels: bound per-row buffers and extreme aspect ratios;
 * - max_megapixels 40 millions of pixels per frame: about 160 MB once decoded as 8-bit RGBA;
 * - max_frames 300 frames: a long animation, bounding the per-frame work of decoders and encoders;
 * - max_total_megapixels 400 millions of pixels over all frames: the decoding budget of a whole animation.
 *
 * Every limit is an integer from 1 to Capabilities::LIMIT_VALUE_MAX.
 *
 * Immutable value object: new Limits() holds the defaults, every with*() method validates its argument and returns
 * a modified copy. Every property has a default and an unserialized instance is validated again, see
 * ValidatedSerializationTrait.
 */
final class Limits
{
    use ValidatedSerializationTrait;

    public const DEFAULT_MAX_BYTES = 20971520;
    public const DEFAULT_MAX_WIDTH = 8192;
    public const DEFAULT_MAX_HEIGHT = 8192;
    public const DEFAULT_MAX_MEGAPIXELS = 40;
    public const DEFAULT_MAX_FRAMES = 300;
    public const DEFAULT_MAX_TOTAL_MEGAPIXELS = 400;

    /**
     * @var int
     */
    private $maxBytes = self::DEFAULT_MAX_BYTES;

    /**
     * @var int
     */
    private $maxWidth = self::DEFAULT_MAX_WIDTH;

    /**
     * @var int
     */
    private $maxHeight = self::DEFAULT_MAX_HEIGHT;

    /**
     * @var int
     */
    private $maxMegapixels = self::DEFAULT_MAX_MEGAPIXELS;

    /**
     * @var int
     */
    private $maxFrames = self::DEFAULT_MAX_FRAMES;

    /**
     * @var int
     */
    private $maxTotalMegapixels = self::DEFAULT_MAX_TOTAL_MEGAPIXELS;

    /**
     * Limits from an array keyed by limit name (Capabilities::LIMIT_*), for example read from configuration. A
     * missing name keeps its default.
     *
     * @param array<mixed> $limits limit name => integer from 1 to Capabilities::LIMIT_VALUE_MAX
     *
     * @throws InvalidArgumentException when a name is unknown or a value is not an integer in range
     */
    public static function fromArray(array $limits): self
    {
        $result = new self();
        foreach ($limits as $name => $value) {
            if (!is_string($name) || !in_array($name, Capabilities::limitNames(), true)) {
                throw new InvalidArgumentException(sprintf(
                    'Limits: unknown limit name; expected names among: %s.',
                    implode(', ', Capabilities::limitNames())
                ));
            }
            if (!is_int($value)) {
                throw self::invalidValue($name);
            }
            $result = $result->withLimit($name, $value);
        }

        return $result;
    }

    /**
     * The limits published by a Capabilities instance.
     */
    public static function fromCapabilities(Capabilities $capabilities): self
    {
        return self::fromArray($capabilities->getLimits());
    }

    /**
     * Every limit keyed by name, in the canonical order of Capabilities::limitNames(): the "limits" argument of the
     * Capabilities constructor.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            Capabilities::LIMIT_MAX_BYTES => $this->maxBytes,
            Capabilities::LIMIT_MAX_WIDTH => $this->maxWidth,
            Capabilities::LIMIT_MAX_HEIGHT => $this->maxHeight,
            Capabilities::LIMIT_MAX_MEGAPIXELS => $this->maxMegapixels,
            Capabilities::LIMIT_MAX_FRAMES => $this->maxFrames,
            Capabilities::LIMIT_MAX_TOTAL_MEGAPIXELS => $this->maxTotalMegapixels,
        ];
    }

    /**
     * Value of one limit.
     *
     * @param string $name one of the Capabilities::LIMIT_* constants
     *
     * @throws InvalidArgumentException when the name is not a limit name
     */
    public function get(string $name): int
    {
        $limits = $this->toArray();
        if (!isset($limits[$name])) {
            throw self::unknownName();
        }

        return $limits[$name];
    }

    /**
     * Copy with one limit changed.
     *
     * @param string $name  one of the Capabilities::LIMIT_* constants
     * @param int    $value from 1 to Capabilities::LIMIT_VALUE_MAX
     *
     * @throws InvalidArgumentException when the name is unknown or the value out of range
     */
    public function withLimit(string $name, int $value): self
    {
        if ($value < 1 || $value > Capabilities::LIMIT_VALUE_MAX) {
            throw self::invalidValue($name);
        }

        $clone = clone $this;
        switch ($name) {
            case Capabilities::LIMIT_MAX_BYTES:
                $clone->maxBytes = $value;
                break;
            case Capabilities::LIMIT_MAX_WIDTH:
                $clone->maxWidth = $value;
                break;
            case Capabilities::LIMIT_MAX_HEIGHT:
                $clone->maxHeight = $value;
                break;
            case Capabilities::LIMIT_MAX_MEGAPIXELS:
                $clone->maxMegapixels = $value;
                break;
            case Capabilities::LIMIT_MAX_FRAMES:
                $clone->maxFrames = $value;
                break;
            case Capabilities::LIMIT_MAX_TOTAL_MEGAPIXELS:
                $clone->maxTotalMegapixels = $value;
                break;
            default:
                throw self::unknownName();
        }

        return $clone;
    }

    /**
     * Maximum size of the input file in bytes.
     */
    public function getMaxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * @param int $maxBytes from 1 to Capabilities::LIMIT_VALUE_MAX bytes
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withMaxBytes(int $maxBytes): self
    {
        return $this->withLimit(Capabilities::LIMIT_MAX_BYTES, $maxBytes);
    }

    /**
     * Maximum width in pixels.
     */
    public function getMaxWidth(): int
    {
        return $this->maxWidth;
    }

    /**
     * @param int $maxWidth from 1 to Capabilities::LIMIT_VALUE_MAX pixels
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withMaxWidth(int $maxWidth): self
    {
        return $this->withLimit(Capabilities::LIMIT_MAX_WIDTH, $maxWidth);
    }

    /**
     * Maximum height in pixels.
     */
    public function getMaxHeight(): int
    {
        return $this->maxHeight;
    }

    /**
     * @param int $maxHeight from 1 to Capabilities::LIMIT_VALUE_MAX pixels
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withMaxHeight(int $maxHeight): self
    {
        return $this->withLimit(Capabilities::LIMIT_MAX_HEIGHT, $maxHeight);
    }

    /**
     * Maximum area of one frame in millions of pixels.
     */
    public function getMaxMegapixels(): int
    {
        return $this->maxMegapixels;
    }

    /**
     * @param int $maxMegapixels from 1 to Capabilities::LIMIT_VALUE_MAX millions of pixels
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withMaxMegapixels(int $maxMegapixels): self
    {
        return $this->withLimit(Capabilities::LIMIT_MAX_MEGAPIXELS, $maxMegapixels);
    }

    /**
     * Maximum number of frames.
     */
    public function getMaxFrames(): int
    {
        return $this->maxFrames;
    }

    /**
     * @param int $maxFrames from 1 to Capabilities::LIMIT_VALUE_MAX frames
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withMaxFrames(int $maxFrames): self
    {
        return $this->withLimit(Capabilities::LIMIT_MAX_FRAMES, $maxFrames);
    }

    /**
     * Maximum area of all frames together (frames x width x height) in millions of pixels.
     */
    public function getMaxTotalMegapixels(): int
    {
        return $this->maxTotalMegapixels;
    }

    /**
     * @param int $maxTotalMegapixels from 1 to Capabilities::LIMIT_VALUE_MAX millions of pixels
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withMaxTotalMegapixels(int $maxTotalMegapixels): self
    {
        return $this->withLimit(Capabilities::LIMIT_MAX_TOTAL_MEGAPIXELS, $maxTotalMegapixels);
    }

    /**
     * Checks the size of an input file against max_bytes, as Capabilities::assertFileSizeWithinLimits().
     *
     * @param int $bytes size of the input file, at least 0
     *
     * @throws LimitExceededException   with error code file_too_large when the file is larger than max_bytes
     * @throws InvalidArgumentException when the size is negative (a programming error)
     */
    public function assertFileSize(int $bytes): void
    {
        $this->asCapabilities()->assertFileSizeWithinLimits($bytes);
    }

    /**
     * Checks an input described by its header against the dimension, pixel and frame limits, as
     * Capabilities::assertInputWithinLimits(): the first limit exceeded, in the order of Capabilities::limitNames(),
     * is reported.
     *
     * @param int $width      width in pixels, at least 1
     * @param int $height     height in pixels, at least 1
     * @param int $frameCount number of frames, at least 1
     *
     * @throws LimitExceededException   with error code image_too_large when a limit is exceeded
     * @throws InvalidArgumentException when a value is below 1 (a programming error)
     */
    public function assertImage(int $width, int $height, int $frameCount): void
    {
        $this->asCapabilities()->assertInputWithinLimits($width, $height, $frameCount);
    }

    /**
     * Largest number of frames of the given size that max_frames and max_total_megapixels allow together, so that a
     * parser counting frames can stop as soon as the count goes above it (assertImage() then reports the limit). It
     * is 0 when one frame of this size already exceeds max_total_megapixels.
     *
     * @param int $width  frame width in pixels, from 1 to Capabilities::LIMIT_VALUE_MAX
     * @param int $height frame height in pixels, from 1 to Capabilities::LIMIT_VALUE_MAX
     *
     * @throws InvalidArgumentException when a dimension is out of range (a programming error)
     */
    public function maxFramesFor(int $width, int $height): int
    {
        if ($width < 1 || $height < 1 || $width > Capabilities::LIMIT_VALUE_MAX || $height > Capabilities::LIMIT_VALUE_MAX) {
            throw new InvalidArgumentException(sprintf(
                'The width and height must be from 1 to %d, got %d x %d.',
                Capabilities::LIMIT_VALUE_MAX,
                $width,
                $height
            ));
        }

        // Both factors are below 2^31: neither product overflows a 64-bit integer.
        return min($this->maxFrames, intdiv($this->maxTotalMegapixels * 1000000, $width * $height));
    }

    /**
     * Capabilities that hold these limits and nothing else, so that the checks run the contract's own arithmetic.
     */
    private function asCapabilities(): Capabilities
    {
        return new Capabilities([], [], [], [], IsolationLevel::NONE, $this->toArray());
    }

    /**
     * Copy of these limits rebuilt from their property values, so that an unserialized instance cannot hold a value
     * outside the documented domain; see ValidatedSerializationTrait.
     *
     * @throws InvalidArgumentException when a property value is not valid
     */
    private function validatedCopy(): self
    {
        return self::fromArray($this->toArray());
    }

    private static function invalidValue(string $name): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Limits: every limit must be an integer from 1 to %d%s.',
            Capabilities::LIMIT_VALUE_MAX,
            in_array($name, Capabilities::limitNames(), true) ? ', got an invalid value for ' . $name : ''
        ));
    }

    private static function unknownName(): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Limits: unknown limit name; expected one of: %s.',
            implode(', ', Capabilities::limitNames())
        ));
    }
}
