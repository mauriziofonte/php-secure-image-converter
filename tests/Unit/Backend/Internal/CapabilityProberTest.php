<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Internal;

use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\Internal\CapabilityProber;
use Mfonte\SecureImageConverter\Backend\Internal\ProbeImages;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The orientation probe: a backend applies orientations only when it turned the oriented probe image upright.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\CapabilityProber
 */
#[CoversClass(CapabilityProber::class)]
final class CapabilityProberTest extends TestCase
{
    /**
     * @var WorkingDirectory|null
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = WorkingDirectory::create();
    }

    protected function tear_down(): void
    {
        if ($this->directory !== null) {
            $this->directory->remove();
        }
        parent::tear_down();
    }

    public function testABackendThatTurnsTheProbeUprightAppliesOrientations(): void
    {
        $capabilities = $this->prober(true)->probe(self::candidates());

        self::assertSame([ImageFormat::JPEG, ImageFormat::PNG], $capabilities->getOrientedFormats());
    }

    public function testABackendThatKeepsThePixelsAsStoredAppliesNoOrientation(): void
    {
        $capabilities = $this->prober(false)->probe(self::candidates());

        self::assertTrue($capabilities->isAvailable());
        self::assertSame([], $capabilities->getOrientedFormats());
        self::assertContains('its output of an oriented image was not upright', $capabilities->getNotes());
    }

    public function testNoCandidateMeansNoOrientationProbe(): void
    {
        $candidates = self::candidates();
        $candidates['oriented'] = [];
        $jobs = [];
        $prober = $this->prober(true, $jobs);

        self::assertSame([], $prober->probe($candidates)->getOrientedFormats());
        foreach ($jobs as $job) {
            self::assertFalse($job->needsOrientation());
        }
    }

    /**
     * A prober whose backend writes a PNG of the job's size, or of the stored size when it does not turn images.
     *
     * @param list<BackendJob> $jobs receives every job converted
     */
    private function prober(bool $turns, array &$jobs = []): CapabilityProber
    {
        self::assertNotNull($this->directory);

        return new CapabilityProber(
            'fake',
            static function (BackendJob $job) use ($turns, &$jobs): void {
                $jobs[] = $job;
                $upright = $turns || !$job->needsOrientation();
                $width = $upright ? $job->getWidth() : $job->getInput()->getWidth();
                $height = $upright ? $job->getHeight() : $job->getInput()->getHeight();
                file_put_contents($job->getOutputPath(), ImageBuilder::png($width, $height));
            },
            new ProbeImages(),
            $this->directory
        );
    }

    /**
     * @return array{inputs: list<string>, outputs: list<string>, first_frame: list<string>, animated_inputs: list<string>, animated_outputs: list<string>, fits: list<string>, animated_fits: list<string>, lossless: list<string>, oriented: list<string>, icc_profile: bool, metadata: bool}
     */
    private static function candidates(): array
    {
        $candidates = [
            'inputs' => [ImageFormat::JPEG, ImageFormat::PNG],
            'outputs' => [ImageFormat::PNG],
            'oriented' => [ImageFormat::JPEG, ImageFormat::PNG],
        ];

        return array_merge(
            ['first_frame' => [], 'animated_inputs' => [], 'animated_outputs' => [], 'fits' => [], 'animated_fits' => [], 'lossless' => [], 'icc_profile' => false, 'metadata' => false],
            $candidates
        );
    }
}
