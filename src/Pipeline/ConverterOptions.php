<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Pipeline;

use Mfonte\SecureImageConverter\Concurrency\Backoff;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;

/**
 * How SecureConverter runs its conversions. Immutable value object; every with*() method returns a modified copy.
 *
 * | Option                   | Default            | Meaning                                                       |
 * | ------------------------ | ------------------ | ------------------------------------------------------------- |
 * | working directory parent | sys_get_temp_dir() | where each conversion's private directory is created          |
 * | slot wait                | 30 seconds         | longest wait for a semaphore slot before concurrency_limit    |
 * | max output bytes         | 256 MiB            | largest output accepted (the runner's default file size limit) |
 * | processing budget        | ProcessingBudget   | timeout and CPU time of each job, from its work               |
 * | isolation level          | none               | level reported by getCapabilities() (the factory sets it)     |
 * | isolation warnings       | none               | added to every result, such as a degraded isolation level     |
 */
final class ConverterOptions
{
    /**
     * Default wait for a conversion slot, in seconds.
     */
    public const DEFAULT_SLOT_WAIT_SECONDS = 30.0;

    /**
     * @var string|null
     */
    private $workingDirectoryParent;

    /**
     * @var float
     */
    private $slotWaitSeconds = self::DEFAULT_SLOT_WAIT_SECONDS;

    /**
     * @var int
     */
    private $maxOutputBytes = ResourceLimits::DEFAULT_FILE_SIZE_BYTES;

    /**
     * @var ProcessingBudget|null
     */
    private $budget;

    /**
     * @var string
     */
    private $isolationLevel = IsolationLevel::NONE;

    /**
     * @var list<string>
     */
    private $isolationWarnings = [];

    /**
     * Existing directory in which each conversion creates its private working directory (mode 0700) and removes it
     * when it ends, whatever the outcome; null for sys_get_temp_dir().
     */
    public function getWorkingDirectoryParent(): ?string
    {
        return $this->workingDirectoryParent;
    }

    /**
     * @param string|null $directory absolute, normalised path of an existing directory, or null
     *
     * @throws InvalidArgumentException when the path is not absolute and normalised
     */
    public function withWorkingDirectoryParent(?string $directory): self
    {
        if ($directory !== null && !ExecutableFinder::isAbsolutePath($directory)) {
            throw new InvalidArgumentException('ConverterOptions: the working directory parent must be an absolute, normalised path.');
        }
        $clone = clone $this;
        $clone->workingDirectoryParent = $directory;

        return $clone;
    }

    /**
     * Longest wait, in seconds, for a slot of the converter's semaphore.
     */
    public function getSlotWaitSeconds(): float
    {
        return $this->slotWaitSeconds;
    }

    /**
     * @param float $seconds from 0 (a single attempt) to Backoff::MAX_TIMEOUT
     *
     * @throws InvalidArgumentException when out of range
     */
    public function withSlotWaitSeconds(float $seconds): self
    {
        if (!($seconds >= 0.0) || $seconds > Backoff::MAX_TIMEOUT) {
            throw new InvalidArgumentException(sprintf('ConverterOptions: the slot wait must be from 0 to %s seconds.', (string) Backoff::MAX_TIMEOUT));
        }
        $clone = clone $this;
        $clone->slotWaitSeconds = $seconds;

        return $clone;
    }

    /**
     * Largest output accepted, in bytes. Keep it at or below the file size limit of the runner, which stops a backend
     * that writes more.
     */
    public function getMaxOutputBytes(): int
    {
        return $this->maxOutputBytes;
    }

    /**
     * @param int $bytes from 1 to ResourceLimits::MAX_BYTES
     *
     * @throws InvalidArgumentException when out of range
     */
    public function withMaxOutputBytes(int $bytes): self
    {
        if ($bytes < 1 || $bytes > ResourceLimits::MAX_BYTES) {
            throw new InvalidArgumentException('ConverterOptions: the output size limit is out of range.');
        }
        $clone = clone $this;
        $clone->maxOutputBytes = $bytes;

        return $clone;
    }

    /**
     * The processing budget that sets each job's timeout and CPU time limit.
     */
    public function getBudget(): ProcessingBudget
    {
        return $this->budget !== null ? $this->budget : new ProcessingBudget();
    }

    public function withBudget(ProcessingBudget $budget): self
    {
        $clone = clone $this;
        $clone->budget = $budget;

        return $clone;
    }

    /**
     * IsolationLevel value the converter reports in its capabilities.
     */
    public function getIsolationLevel(): string
    {
        return $this->isolationLevel;
    }

    /**
     * Warnings added to every result, such as the IsolationDecision warning of a degraded level.
     *
     * @return list<string>
     */
    public function getIsolationWarnings(): array
    {
        return $this->isolationWarnings;
    }

    /**
     * @param string       $level    IsolationLevel value
     * @param array<mixed> $warnings list of strings, each in the warning format of ConversionResult
     *
     * @throws InvalidArgumentException when the level is unknown or a warning is not valid
     */
    public function withIsolation(string $level, array $warnings = []): self
    {
        if (!IsolationLevel::isValid($level)) {
            throw new InvalidArgumentException(sprintf('ConverterOptions: the isolation level must be one of: %s.', implode(', ', IsolationLevel::all())));
        }
        foreach ($warnings as $warning) {
            if (!is_string($warning) || preg_match('/\A[\x20-\x7E]{1,' . ConversionResult::WARNING_MAX_LENGTH . '}\z/', $warning) !== 1) {
                throw new InvalidArgumentException('ConverterOptions: an isolation warning is not a valid result warning.');
            }
        }
        $checked = [];
        foreach ($warnings as $warning) {
            $checked[] = (string) $warning;
        }
        $clone = clone $this;
        $clone->isolationLevel = $level;
        $clone->isolationWarnings = $checked;

        return $clone;
    }
}
