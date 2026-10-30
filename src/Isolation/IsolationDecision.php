<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;

/**
 * The outcome of IsolationPolicy::resolve(): the level conversions run under, and the warnings to report with them.
 * Immutable value object.
 *
 * Backends create their ProcessRunner with getLevel() (or run in-process when it is none) and copy getWarnings()
 * into every ConversionResult, whose warning format they satisfy.
 */
final class IsolationDecision
{
    /**
     * @var string
     */
    private $mode;

    /**
     * @var string
     */
    private $configuredLevel;

    /**
     * @var string
     */
    private $level;

    /**
     * @var list<string>
     */
    private $warnings;

    /**
     * @param string       $mode            IsolationPolicy::STRICT or IsolationPolicy::BEST_EFFORT
     * @param string       $configuredLevel IsolationLevel value configured in the policy
     * @param string       $level           IsolationLevel value to run at
     * @param list<string> $warnings        each 1 to ConversionResult::WARNING_MAX_LENGTH printable ASCII characters
     *
     * @throws InvalidArgumentException when a value is outside its documented domain
     */
    public function __construct(string $mode, string $configuredLevel, string $level, array $warnings)
    {
        if ($mode !== IsolationPolicy::STRICT && $mode !== IsolationPolicy::BEST_EFFORT) {
            throw new InvalidArgumentException('The isolation policy must be one of: strict, best_effort.');
        }
        if (!IsolationLevel::isValid($configuredLevel) || !IsolationLevel::isValid($level)) {
            throw new InvalidArgumentException(sprintf(
                'Isolation levels must be one of: %s.',
                implode(', ', IsolationLevel::all())
            ));
        }
        $this->mode = $mode;
        $this->configuredLevel = $configuredLevel;
        $this->level = $level;
        $this->warnings = self::checkWarnings($warnings);
    }

    /**
     * IsolationPolicy::STRICT or IsolationPolicy::BEST_EFFORT.
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * The IsolationLevel value configured in the policy (minimum or expectation).
     */
    public function getConfiguredLevel(): string
    {
        return $this->configuredLevel;
    }

    /**
     * The IsolationLevel value conversions run at: the strongest level the host provides.
     */
    public function getLevel(): string
    {
        return $this->level;
    }

    /**
     * Whether the effective level is below the configured one (only possible with a best_effort policy).
     */
    public function isDegraded(): bool
    {
        return !IsolationLevel::isAtLeast($this->level, $this->configuredLevel);
    }

    /**
     * Warnings to add to every ConversionResult produced under this decision; empty unless degraded.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array<mixed> $warnings
     *
     * @throws InvalidArgumentException when a warning is not acceptable in a ConversionResult
     *
     * @return list<string>
     */
    private static function checkWarnings(array $warnings): array
    {
        $checked = [];
        foreach ($warnings as $warning) {
            if (!is_string($warning)
                || preg_match('/\A[\x20-\x7E]{1,' . ConversionResult::WARNING_MAX_LENGTH . '}\z/', $warning) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Isolation warnings must be strings of 1 to %d printable ASCII characters.',
                    ConversionResult::WARNING_MAX_LENGTH
                ));
            }
            $checked[] = $warning;
        }

        return $checked;
    }
}
