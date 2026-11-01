<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;

/**
 * The strongest isolation level available on this host, as IsolationDetector found it by running real probes, and
 * why each stronger level is unavailable. Immutable value object.
 *
 * Reasons are short fixed English phrases chosen by the detector (for example "bubblewrap (bwrap) failed to run a
 * test command (exit code 1)"): printable ASCII, never a path or the output of a probe, so that IsolationPolicy can
 * put them in a ConversionResult warning, and the conversion API and the proxy can show them on /healthz and in
 * check-env. The probe output itself goes to the detector's logger.
 */
final class DetectionResult
{
    /**
     * Maximum length of a reason, in characters.
     */
    public const REASON_MAX_LENGTH = 160;

    /**
     * @var string
     */
    private $level;

    /**
     * @var array<string, string>
     */
    private $reasons;

    /**
     * @var int
     */
    private $detectedAt;

    /**
     * @var bool
     */
    private $fromCache;

    /**
     * @param string                $level      IsolationLevel value: the strongest level whose probe succeeded
     * @param array<string, string> $reasons    why each level above $level is unavailable, keyed by level: exactly
     *                                          one entry for every stronger level, none for the others; each 1 to
     *                                          REASON_MAX_LENGTH printable ASCII characters
     * @param int                   $detectedAt Unix time of the probes
     * @param bool                  $fromCache  whether the result was read from the detector's file cache
     *
     * @throws InvalidArgumentException when a value is outside its documented domain
     */
    public function __construct(string $level, array $reasons, int $detectedAt, bool $fromCache = false)
    {
        if (!IsolationLevel::isValid($level)) {
            throw new InvalidArgumentException(sprintf(
                'The isolation level must be one of: %s.',
                implode(', ', IsolationLevel::all())
            ));
        }
        $checked = [];
        foreach (IsolationLevel::all() as $candidate) {
            if (IsolationLevel::rank($candidate) <= IsolationLevel::rank($level)) {
                continue;
            }
            $reason = $reasons[$candidate] ?? null;
            if (!is_string($reason) || !self::isValidReason($reason)) {
                throw new InvalidArgumentException(sprintf(
                    'Every level above the detected one needs a reason of 1 to %d printable ASCII characters.',
                    self::REASON_MAX_LENGTH
                ));
            }
            $checked[$candidate] = $reason;
        }
        if (count($reasons) !== count($checked)) {
            throw new InvalidArgumentException('Reasons are accepted only for the levels above the detected one.');
        }
        if ($detectedAt < 0) {
            throw new InvalidArgumentException('The detection time must be a Unix timestamp, at least 0.');
        }

        $this->level = $level;
        $this->reasons = $checked;
        $this->detectedAt = $detectedAt;
        $this->fromCache = $fromCache;
    }

    /**
     * Rebuilds a result from toArray() output, strictly: any missing, unknown or invalid key is rejected.
     *
     * @param array<mixed> $data
     *
     * @throws InvalidArgumentException when the array is not a valid result
     */
    public static function fromArray(array $data, bool $fromCache = false): self
    {
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['detected_at', 'level', 'reasons']) {
            throw new InvalidArgumentException(
                'A detection result has exactly the keys level, reasons and detected_at.'
            );
        }
        if (!is_string($data['level']) || !is_array($data['reasons']) || !is_int($data['detected_at'])) {
            throw new InvalidArgumentException(
                'A detection result has a string level, a reasons map and an integer time.'
            );
        }
        $reasons = [];
        foreach ($data['reasons'] as $level => $reason) {
            if (!is_string($level) || !is_string($reason)) {
                throw new InvalidArgumentException('Detection reasons map level names to strings.');
            }
            $reasons[$level] = $reason;
        }

        return new self($data['level'], $reasons, $data['detected_at'], $fromCache);
    }

    /**
     * Whether a string is acceptable as a reason: 1 to REASON_MAX_LENGTH printable ASCII characters.
     */
    public static function isValidReason(string $reason): bool
    {
        return preg_match('/\A[\x20-\x7E]{1,' . self::REASON_MAX_LENGTH . '}\z/', $reason) === 1;
    }

    /**
     * The strongest available IsolationLevel value.
     */
    public function getLevel(): string
    {
        return $this->level;
    }

    /**
     * Why each level above getLevel() is unavailable, keyed by level from the weakest to the strongest; empty when
     * the sandbox level is available.
     *
     * @return array<string, string>
     */
    public function getReasons(): array
    {
        return $this->reasons;
    }

    /**
     * Why the level right above getLevel() is unavailable, or null when the sandbox level is available.
     */
    public function getReason(): ?string
    {
        foreach ($this->reasons as $reason) {
            return $reason;
        }

        return null;
    }

    /**
     * Unix time at which the probes ran (for a cached result: the time of the original probes).
     */
    public function getDetectedAt(): int
    {
        return $this->detectedAt;
    }

    /**
     * Whether this result was read from the detector's file cache rather than probed by this process.
     */
    public function isFromCache(): bool
    {
        return $this->fromCache;
    }

    /**
     * @return array{level: string, reasons: array<string, string>, detected_at: int}
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'reasons' => $this->reasons,
            'detected_at' => $this->detectedAt,
        ];
    }
}
