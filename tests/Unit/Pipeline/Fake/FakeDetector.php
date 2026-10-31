<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Pipeline\Fake;

use Mfonte\SecureImageConverter\Isolation\DetectionResult;
use Mfonte\SecureImageConverter\Isolation\IsolationDetectorInterface;

/**
 * A detector that returns a fixed result and counts its calls.
 */
final class FakeDetector implements IsolationDetectorInterface
{
    /**
     * @var int
     */
    public $calls = 0;

    /**
     * @var DetectionResult
     */
    private $result;

    public function __construct(DetectionResult $result)
    {
        $this->result = $result;
    }

    public function detect(): DetectionResult
    {
        ++$this->calls;

        return $this->result;
    }

    public function refresh(): DetectionResult
    {
        return $this->detect();
    }
}
