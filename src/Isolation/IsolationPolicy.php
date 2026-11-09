<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;

/**
 * Decides which isolation level conversions run under, given what IsolationDetector found. Immutable value object.
 *
 * - strict: the configured level is a hard minimum. When the host provides less, resolve() throws
 *   IsolationUnavailableException (error code isolation_unavailable, HTTP 503 in the conversion API) and nothing is
 *   converted with weaker isolation than promised.
 * - best_effort: the configured level is the expected one. When the host provides less, conversions run at the
 *   strongest available level and the decision carries a warning, meant for ConversionResult warnings, logs,
 *   /healthz and check-env, so that the weaker isolation is never silent (L-8).
 *
 * In both modes the effective level is the strongest level the host provides, which may be above the configured
 * one. To keep the sandbox out on purpose, disable bubblewrap in the ProcessOptions given to the detector
 * (withToolPath(ProcessOptions::TOOL_BWRAP, null)).
 */
final class IsolationPolicy
{
    public const STRICT = 'strict';

    public const BEST_EFFORT = 'best_effort';

    /**
     * @var string
     */
    private $mode;

    /**
     * @var string
     */
    private $level;

    private function __construct(string $mode, string $level)
    {
        $this->mode = $mode;
        $this->level = $level;
    }

    /**
     * A policy that refuses to convert below $requiredLevel.
     *
     * @param string $requiredLevel IsolationLevel value
     *
     * @throws InvalidArgumentException when the level is unknown
     */
    public static function strict(string $requiredLevel): self
    {
        return self::create(self::STRICT, $requiredLevel);
    }

    /**
     * A policy that converts at the strongest available level, and warns when it is below $expectedLevel.
     *
     * @param string $expectedLevel IsolationLevel value
     *
     * @throws InvalidArgumentException when the level is unknown
     */
    public static function bestEffort(string $expectedLevel = IsolationLevel::SANDBOX): self
    {
        return self::create(self::BEST_EFFORT, $expectedLevel);
    }

    /**
     * A policy from configuration values, for example ISOLATION_POLICY=strict and ISOLATION_LEVEL=rlimit.
     *
     * @param string $mode  STRICT or BEST_EFFORT
     * @param string $level IsolationLevel value
     *
     * @throws InvalidArgumentException when the mode or the level is unknown
     */
    public static function create(string $mode, string $level): self
    {
        if ($mode !== self::STRICT && $mode !== self::BEST_EFFORT) {
            throw new InvalidArgumentException('The isolation policy must be one of: strict, best_effort.');
        }
        if (!IsolationLevel::isValid($level)) {
            throw new InvalidArgumentException(sprintf(
                'The isolation level must be one of: %s.',
                implode(', ', IsolationLevel::all())
            ));
        }

        return new self($mode, $level);
    }

    /**
     * STRICT or BEST_EFFORT.
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * The configured IsolationLevel value: the minimum of a strict policy, the expectation of a best_effort one.
     */
    public function getLevel(): string
    {
        return $this->level;
    }

    public function isStrict(): bool
    {
        return $this->mode === self::STRICT;
    }

    /**
     * Applies the policy to a detection result.
     *
     * @throws IsolationUnavailableException when the policy is strict and the detected level is below the configured
     *                                       one
     */
    public function resolve(DetectionResult $detection): IsolationDecision
    {
        $detected = $detection->getLevel();
        if (IsolationLevel::isAtLeast($detected, $this->level)) {
            return new IsolationDecision($this->mode, $this->level, $detected, []);
        }
        $reason = (string) $detection->getReason();
        if ($this->mode === self::STRICT) {
            throw new IsolationUnavailableException(sprintf(
                'The strict isolation policy requires the %s level, but this host provides only %s: %s.',
                $this->level,
                $detected,
                $reason
            ));
        }

        return new IsolationDecision($this->mode, $this->level, $detected, [sprintf(
            'Isolation degraded to the %s level, below the expected %s level: %s.',
            $detected,
            $this->level,
            $reason
        )]);
    }
}
