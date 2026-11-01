<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Pipeline;

use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;

/**
 * The wall-clock timeout and CPU time limit of one conversion, derived from the work it does instead of one fixed value
 * for every job. Immutable value object.
 *
 * The time a backend takes grows with the pixels it decodes (every frame of a kept animation) and encodes (every
 * output frame), and with the output format, the lossless mode and the effort: effort-9 AVIF of a 13-megapixel image
 * took about 1880 seconds of CPU time in measurements, the default WEBP of the same image a few seconds. The budget of
 * a job is
 *
 *     scale x (BASE_SECONDS + DECODE_SECONDS_PER_MEGAPIXEL x decoded megapixels
 *              + encode seconds per megapixel x encoded megapixels)
 *
 * at least the floor and at most the ceiling (configurable). Encode seconds per megapixel, by output and effort; the
 * values are about twice the slowest single-thread time measured with the packages of tests/docker (Ubuntu 24.04) on
 * screenshots and noise, so that a typical machine finishes well inside them:
 *
 * | Output                   | Effort 0-2 | 3-4 | 5-6 | 7  | 8-9 |
 * | ------------------------ | ---------- | --- | --- | -- | --- |
 * | JPEG, PNG, TIFF, BMP     | 4          | 4   | 4   | 4  | 4   |
 * | GIF                      | 8          | 8   | 8   | 8  | 8   |
 * | WEBP                     | 6          | 6   | 15  | 60 | 60  |
 * | WEBP lossless            | 10         | 20  | 80  | 160| 160 |
 * | AVIF                     | 6          | 15  | 60  | 150| 300 |
 * | AVIF lossless            | 12         | 30  | 120 | 300| 600 |
 *
 * The CPU time limit is CPU_FACTOR times the wall-clock budget (AV1 encoders use helper threads), so that the
 * wall-clock timeout normally stops a job first.
 *
 * Admission: a job whose budget before the ceiling is applied exceeds the ceiling is refused before anything runs
 * (ConversionTimeoutException, conversion_timeout, 422: the input exceeds the processing budget, a retry will not
 * help), never started to be stopped later. With the default ceiling of
 * DEFAULT_MAX_SECONDS (one hour) and the default Limits (40 megapixels, 400 megapixels over all frames), every request
 * with the default parameters (WEBP, effort 4) fits: at most about 300 seconds for a still image and 2800 for the
 * largest animation. The requests that do not fit, in megapixels over all frames: AVIF above about 23 (effort 7) and
 * 11 (effort 8-9); lossless AVIF above about 29 (effort 5-6), 11 (effort 7) and 5 (effort 8-9); WEBP above about 58
 * (effort 7-9); lossless WEBP above about 44 (effort 5-6) and 22 (effort 7-9). Raise the ceiling (withMaxSeconds())
 * to accept them; the measured rates (single thread, 0.5-megapixel screenshot and noise) were about 1 s per megapixel
 * for WEBP at effort 4, 25 at effort 9, 77 for lossless WEBP at effort 9, 4 for GIF, 7 to 14 for AVIF at effort 4-5
 * and 146 at effort 8-9.
 */
final class ProcessingBudget
{
    /**
     * Default ceiling of the wall-clock budget of one job: one hour.
     */
    public const DEFAULT_MAX_SECONDS = 3600.0;

    /**
     * Default floor: the start of a sandboxed process and of a PHP worker on a loaded host.
     */
    public const DEFAULT_MIN_SECONDS = 30.0;

    /**
     * Fixed part of every budget, in seconds.
     */
    public const BASE_SECONDS = 15.0;

    /**
     * Seconds per decoded megapixel.
     */
    public const DECODE_SECONDS_PER_MEGAPIXEL = 1.0;

    /**
     * CPU time limit as a multiple of the wall-clock budget.
     */
    public const CPU_FACTOR = 2;

    /**
     * Encode seconds per megapixel by profile, for efforts 0-2, 3-4, 5-6, 7 and 8-9 (see the class description).
     */
    private const ENCODE_SECONDS = [
        'still' => [4.0, 4.0, 4.0, 4.0, 4.0],
        'gif' => [8.0, 8.0, 8.0, 8.0, 8.0],
        'webp' => [6.0, 6.0, 15.0, 60.0, 60.0],
        'webp_lossless' => [10.0, 20.0, 80.0, 160.0, 160.0],
        'avif' => [6.0, 15.0, 60.0, 150.0, 300.0],
        'avif_lossless' => [12.0, 30.0, 120.0, 300.0, 600.0],
    ];

    /**
     * @var float
     */
    private $minSeconds = self::DEFAULT_MIN_SECONDS;

    /**
     * @var float
     */
    private $maxSeconds = self::DEFAULT_MAX_SECONDS;

    /**
     * @var float
     */
    private $scale = 1.0;

    /**
     * Ceiling of the wall-clock budget of one job, in seconds.
     */
    public function getMaxSeconds(): float
    {
        return $this->maxSeconds;
    }

    /**
     * @param float $seconds from the floor to ProcessOptions::MAX_TIMEOUT_SECONDS
     *
     * @throws InvalidArgumentException when out of range
     */
    public function withMaxSeconds(float $seconds): self
    {
        if (!($seconds >= $this->minSeconds) || $seconds > ProcessOptions::MAX_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'ProcessingBudget: the ceiling must be from the floor (%s s) to %d seconds.',
                (string) $this->minSeconds,
                (int) ProcessOptions::MAX_TIMEOUT_SECONDS
            ));
        }
        $clone = clone $this;
        $clone->maxSeconds = $seconds;

        return $clone;
    }

    /**
     * Floor of the wall-clock budget of one job, in seconds.
     */
    public function getMinSeconds(): float
    {
        return $this->minSeconds;
    }

    /**
     * @param float $seconds above 0 and at most the ceiling
     *
     * @throws InvalidArgumentException when out of range
     */
    public function withMinSeconds(float $seconds): self
    {
        if (!($seconds > 0.0) || $seconds > $this->maxSeconds) {
            throw new InvalidArgumentException('ProcessingBudget: the floor must be above 0 and at most the ceiling.');
        }
        $clone = clone $this;
        $clone->minSeconds = $seconds;

        return $clone;
    }

    /**
     * Factor applied to every budget before the floor and the ceiling: above 1 for a slower host.
     */
    public function getScale(): float
    {
        return $this->scale;
    }

    /**
     * @param float $scale from 0.1 to 100
     *
     * @throws InvalidArgumentException when out of range
     */
    public function withScale(float $scale): self
    {
        if (!($scale >= 0.1) || $scale > 100.0) {
            throw new InvalidArgumentException('ProcessingBudget: the scale must be from 0.1 to 100.');
        }
        $clone = clone $this;
        $clone->scale = $scale;

        return $clone;
    }

    /**
     * The budget of a job before the floor and the ceiling apply: what the job is expected to need, in seconds.
     */
    public function estimateSeconds(BackendJob $job): float
    {
        $decoded = $job->getInputPixels() / 1000000.0;
        $encoded = $job->getOutputPixels() / 1000000.0;
        $rates = self::ENCODE_SECONDS[self::profile($job)];
        $effort = $job->getEffort();
        $rate = $effort <= 2 ? $rates[0] : ($effort <= 4 ? $rates[1] : ($effort <= 6 ? $rates[2] : ($effort === 7 ? $rates[3] : $rates[4])));

        return $this->scale * (self::BASE_SECONDS + self::DECODE_SECONDS_PER_MEGAPIXEL * $decoded + $rate * $encoded);
    }

    /**
     * Whether the job's estimate fits under the ceiling: a job that does not is refused before it runs.
     */
    public function admits(BackendJob $job): bool
    {
        return $this->estimateSeconds($job) <= $this->maxSeconds;
    }

    /**
     * Wall-clock timeout of the job: its estimate, at least the floor and at most the ceiling.
     */
    public function timeoutSeconds(BackendJob $job): float
    {
        return min($this->maxSeconds, max($this->minSeconds, $this->estimateSeconds($job)));
    }

    /**
     * CPU time limit of the job: CPU_FACTOR times its timeout, rounded up, at most ResourceLimits::MAX_CPU_SECONDS.
     */
    public function cpuSeconds(BackendJob $job): int
    {
        return (int) min((float) ResourceLimits::MAX_CPU_SECONDS, ceil(self::CPU_FACTOR * $this->timeoutSeconds($job)));
    }

    /**
     * The job with its timeout and CPU time limit set.
     */
    public function apply(BackendJob $job): BackendJob
    {
        return $job->withTimeLimits($this->timeoutSeconds($job), $this->cpuSeconds($job));
    }

    private static function profile(BackendJob $job): string
    {
        switch ($job->getOutputFormat()) {
            case ImageFormat::WEBP:
                return $job->needsLosslessMode() ? 'webp_lossless' : 'webp';
            case ImageFormat::AVIF:
                return $job->needsLosslessMode() ? 'avif_lossless' : 'avif';
            case ImageFormat::GIF:
                return 'gif';
            default:
                return 'still';
        }
    }
}
