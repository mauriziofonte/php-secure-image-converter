<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Fake;

use Mfonte\SecureImageConverter\Backend\BackendCapabilities;
use Mfonte\SecureImageConverter\Backend\BackendInterface;
use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\BackendResult;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;

/**
 * A backend with fixed capabilities and memory model that records the jobs it converts without running anything.
 */
final class FakeBackend implements BackendInterface
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var BackendCapabilities
     */
    private $capabilities;

    /**
     * @var int
     */
    private $baseBytes;

    /**
     * @var int
     */
    private $bytesPerPixel;

    /**
     * @var list<BackendJob>
     */
    public $converted = [];

    public function __construct(BackendCapabilities $capabilities, int $baseBytes = 33554432, int $bytesPerPixel = 4)
    {
        $this->name = $capabilities->getBackend();
        $this->capabilities = $capabilities;
        $this->baseBytes = $baseBytes;
        $this->bytesPerPixel = $bytesPerPixel;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCapabilities(): BackendCapabilities
    {
        return $this->capabilities;
    }

    public function estimateMemoryBytes(BackendJob $job): int
    {
        return $this->baseBytes + $this->bytesPerPixel * $job->getInputPixels();
    }

    public function convert(BackendJob $job): BackendResult
    {
        $this->converted[] = $job;

        return new BackendResult($this->name, $job, 1, IsolationLevel::NONE);
    }
}
