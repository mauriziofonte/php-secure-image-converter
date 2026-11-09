<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;

/**
 * What a backend reports after a successful conversion: which backend ran, the job it performed, how long the process
 * took and the isolation level it ran under. The output file is at the job's output path; its content is not verified
 * yet. Immutable value object.
 */
final class BackendResult
{
    /**
     * @var string
     */
    private $backend;

    /**
     * @var BackendJob
     */
    private $job;

    /**
     * @var int
     */
    private $durationMs;

    /**
     * @var string
     */
    private $isolationLevel;

    /**
     * @param string     $backend        backend name
     * @param BackendJob $job            the job performed
     * @param int        $durationMs     wall-clock duration of the process, at least 0
     * @param string     $isolationLevel IsolationLevel value the process ran under
     *
     * @throws InvalidArgumentException when a value is outside its documented domain
     */
    public function __construct(string $backend, BackendJob $job, int $durationMs, string $isolationLevel)
    {
        if (!Capabilities::isValidBackendName($backend)) {
            throw new InvalidArgumentException('BackendResult: the backend name is not valid.');
        }
        if ($durationMs < 0) {
            throw new InvalidArgumentException('BackendResult: the duration must be at least 0.');
        }
        if (!IsolationLevel::isValid($isolationLevel)) {
            throw new InvalidArgumentException('BackendResult: the isolation level is not valid.');
        }
        $this->backend = $backend;
        $this->job = $job;
        $this->durationMs = $durationMs;
        $this->isolationLevel = $isolationLevel;
    }

    /**
     * Name of the backend that ran.
     */
    public function getBackend(): string
    {
        return $this->backend;
    }

    /**
     * The job performed.
     */
    public function getJob(): BackendJob
    {
        return $this->job;
    }

    /**
     * Absolute path of the output file.
     */
    public function getOutputPath(): string
    {
        return $this->job->getOutputPath();
    }

    /**
     * ImageFormat value of the output.
     */
    public function getOutputFormat(): string
    {
        return $this->job->getOutputFormat();
    }

    /**
     * Wall-clock duration of the conversion process, in milliseconds.
     */
    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    /**
     * IsolationLevel value the process ran under.
     */
    public function getIsolationLevel(): string
    {
        return $this->isolationLevel;
    }
}
