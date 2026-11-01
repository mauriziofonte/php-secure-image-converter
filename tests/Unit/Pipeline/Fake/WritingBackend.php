<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Pipeline\Fake;

use Mfonte\SecureImageConverter\Backend\BackendCapabilities;
use Mfonte\SecureImageConverter\Backend\BackendInterface;
use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\BackendResult;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;

/**
 * A backend with fixed capabilities that "converts" by calling a writer with the job: the writer puts whatever bytes
 * the test needs at the job's output path, or throws. Nothing runs.
 */
final class WritingBackend implements BackendInterface
{
    /**
     * @var BackendCapabilities
     */
    private $capabilities;

    /**
     * @var callable(BackendJob): void
     */
    private $writer;

    /**
     * @var list<BackendJob>
     */
    public $jobs = [];

    /**
     * @param callable(BackendJob): void $writer
     */
    public function __construct(BackendCapabilities $capabilities, callable $writer)
    {
        $this->capabilities = $capabilities;
        $this->writer = $writer;
    }

    public function getName(): string
    {
        return $this->capabilities->getBackend();
    }

    public function getCapabilities(): BackendCapabilities
    {
        return $this->capabilities;
    }

    public function estimateMemoryBytes(BackendJob $job): int
    {
        return 33554432;
    }

    public function convert(BackendJob $job): BackendResult
    {
        $this->jobs[] = $job;
        ($this->writer)($job);

        return new BackendResult($this->getName(), $job, 5, IsolationLevel::RLIMIT);
    }
}
