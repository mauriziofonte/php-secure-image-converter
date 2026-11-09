<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

use Mfonte\SecureImageConverter\Contract\Capabilities;

/**
 * The input, or the output resolved from the request, exceeds one of the converter's limits (Capabilities::LIMIT_*).
 *
 * Input limits are checked on the file size and on header data before any decoder runs. The resolved output (its
 * size after resize, fit and enlargement, and its frame count after the animation policy) is checked against the
 * same dimension, pixel and frame limits before any encoder runs; the byte size limit applies to the input only.
 * Capabilities::assertFileSizeWithinLimits(), assertInputWithinLimits() and assertOutputWithinLimits() perform these
 * checks.
 *
 * Error code and expected HTTP status in the API:
 * - ErrorCode::FILE_TOO_LARGE, 413 Content Too Large, for Capabilities::LIMIT_MAX_BYTES;
 * - ErrorCode::IMAGE_TOO_LARGE, 422 Unprocessable Content, for every dimension, pixel and frame limit.
 *
 * Instances are created with forInput() and forOutput() only, so that the limit, the maximum and the actual value
 * are always available to callers.
 */
final class LimitExceededException extends \RangeException implements ExceptionInterface
{
    /**
     * Quantity and unit of each limit, as used in messages. Megapixel limits are reported in pixels.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DESCRIPTIONS = [
        Capabilities::LIMIT_MAX_BYTES => ['file size', 'bytes'],
        Capabilities::LIMIT_MAX_WIDTH => ['width', 'px'],
        Capabilities::LIMIT_MAX_HEIGHT => ['height', 'px'],
        Capabilities::LIMIT_MAX_MEGAPIXELS => ['area', 'pixels'],
        Capabilities::LIMIT_MAX_FRAMES => ['frame count', 'frames'],
        Capabilities::LIMIT_MAX_TOTAL_MEGAPIXELS => ['area of all frames', 'pixels'],
    ];

    /**
     * @var string
     */
    private $limit;

    /**
     * @var int
     */
    private $maximum;

    /**
     * @var int
     */
    private $actual;

    /**
     * @var bool
     */
    private $outputLimit;

    private function __construct(string $limit, int $maximum, int $actual, bool $outputLimit)
    {
        if (!isset(self::DESCRIPTIONS[$limit])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown limit; expected one of: %s.',
                implode(', ', array_keys(self::DESCRIPTIONS))
            ));
        }
        if ($outputLimit && $limit === Capabilities::LIMIT_MAX_BYTES) {
            throw new InvalidArgumentException('The max_bytes limit applies to the input only.');
        }
        if ($maximum < 1 || $actual <= $maximum) {
            throw new InvalidArgumentException(sprintf(
                'A limit is exceeded only when the actual value is above a maximum of at least 1, got %d for a maximum of %d.',
                $actual,
                $maximum
            ));
        }

        list($quantity, $unit) = self::DESCRIPTIONS[$limit];
        parent::__construct(sprintf(
            '%s %s is %d %s, above the limit of %d %s (%s).',
            $outputLimit ? 'Resolved output' : 'Input',
            $quantity,
            $actual,
            $unit,
            $maximum,
            $unit,
            $limit
        ));

        $this->limit = $limit;
        $this->maximum = $maximum;
        $this->actual = $actual;
        $this->outputLimit = $outputLimit;
    }

    /**
     * Creates the exception for an input that exceeds a limit.
     *
     * @param string $limit   one of the Capabilities::LIMIT_* constants
     * @param int    $maximum the limit, in the unit of getMaximum()
     * @param int    $actual  the input's value, above $maximum
     *
     * @throws InvalidArgumentException when the limit is unknown or $actual does not exceed $maximum
     */
    public static function forInput(string $limit, int $maximum, int $actual): self
    {
        return new self($limit, $maximum, $actual, false);
    }

    /**
     * Creates the exception for a resolved output that exceeds a dimension, pixel or frame limit.
     *
     * @param string $limit   one of the Capabilities::LIMIT_* constants except LIMIT_MAX_BYTES
     * @param int    $maximum the limit, in the unit of getMaximum()
     * @param int    $actual  the resolved output's value, above $maximum
     *
     * @throws InvalidArgumentException when the limit is unknown or max_bytes, or $actual does not exceed $maximum
     */
    public static function forOutput(string $limit, int $maximum, int $actual): self
    {
        return new self($limit, $maximum, $actual, true);
    }

    /**
     * ErrorCode::FILE_TOO_LARGE for max_bytes, ErrorCode::IMAGE_TOO_LARGE for every other limit.
     */
    public function getErrorCode(): string
    {
        return $this->limit === Capabilities::LIMIT_MAX_BYTES ? ErrorCode::FILE_TOO_LARGE : ErrorCode::IMAGE_TOO_LARGE;
    }

    /**
     * Name of the exceeded limit: one of the Capabilities::LIMIT_* constants.
     */
    public function getLimit(): string
    {
        return $this->limit;
    }

    /**
     * Value of the limit, in bytes, pixels or frames. Megapixel limits are converted to pixels. For the width and
     * height of a resolved output it is the effective maximum: max_width or max_height capped at
     * Capabilities::OUTPUT_DIMENSION_MAX.
     */
    public function getMaximum(): int
    {
        return $this->maximum;
    }

    /**
     * Value that exceeded the limit, in the unit of getMaximum(). Saturates at PHP_INT_MAX.
     */
    public function getActual(): int
    {
        return $this->actual;
    }

    /**
     * Whether the resolved output exceeded the limit, rather than the input.
     */
    public function isOutputLimit(): bool
    {
        return $this->outputLimit;
    }
}
