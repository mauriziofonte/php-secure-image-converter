<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Internal;

use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\Internal\CapabilityProber;
use Mfonte\SecureImageConverter\Backend\Internal\ProbeImages;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageBuilder;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The orientation probe: a backend applies orientations only when it turned the oriented probe image upright. The
 * variant probe: a backend that crashes on the image of a variant does not decode that variant. The re-encode probe:
 * a backend that keeps the metadata of a PNG re-encoded as PNG lists PNG as unstripped.
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

    public function testABackendThatStripsEveryReencodeListsNoUnstrippedFormat(): void
    {
        self::assertSame([], $this->prober(true)->probe(self::candidates())->getUnstrippedFormats());
    }

    /**
     * vips 8.15 keeps the text chunks of a PNG saved as PNG whatever it is told: the backend below copies a PNG input
     * with metadata to a PNG output as it is, and writes a clean image otherwise.
     */
    public function testABackendThatKeepsTheMetadataOfAReencodeListsItsFormat(): void
    {
        self::assertNotNull($this->directory);
        $image = (new ProbeImages())->metadata(ImageFormat::PNG);
        self::assertNotNull($image);
        $prober = new CapabilityProber(
            'fake',
            static function (BackendJob $job): void {
                if ($job->getInput()->hasMetadata() && $job->getInputFormat() === $job->getOutputFormat()) {
                    copy($job->getInputPath(), $job->getOutputPath());

                    return;
                }
                file_put_contents($job->getOutputPath(), ImageBuilder::png($job->getWidth(), $job->getHeight()));
            },
            new ProbeImages(),
            $this->directory
        );

        $capabilities = $prober->probe(self::candidates());

        self::assertSame([ImageFormat::PNG], $capabilities->getUnstrippedFormats());
        self::assertSame([ImageFormat::JPEG, ImageFormat::PNG], $capabilities->getInputFormats());
        self::assertContains('its png output from a png with metadata kept the metadata', $capabilities->getNotes());
    }

    /**
     * The 64-bit BMP is probed at 40 x 30, the size on which an ImageMagick build crashed after converting 16 x 12, and
     * a crash on it (signal 11, as ProcessFailure reports it) leaves that variant out while the others are declared.
     */
    public function testABackendThatCrashesOnAVariantDoesNotDecodeIt(): void
    {
        self::assertNotNull($this->directory);
        $image = (new ProbeImages())->variant(ImageInfo::VARIANT_BMP_64_BIT);
        self::assertNotNull($image);
        self::assertSame([40, 30], [$image[1]->getWidth(), $image[1]->getHeight()]);
        $prober = new CapabilityProber(
            'fake',
            static function (BackendJob $job): void {
                if (in_array(ImageInfo::VARIANT_BMP_64_BIT, $job->getInput()->getVariants(), true)) {
                    throw new ConversionFailedException('The fake conversion crashed (signal 11).');
                }
                file_put_contents($job->getOutputPath(), ImageBuilder::png($job->getWidth(), $job->getHeight()));
            },
            new ProbeImages(),
            $this->directory
        );
        $candidates = self::candidates();
        $candidates['inputs'] = [ImageFormat::BMP];

        $capabilities = $prober->probe($candidates);

        self::assertSame([ImageFormat::BMP], $capabilities->getInputFormats());
        self::assertNotContains(ImageInfo::VARIANT_BMP_64_BIT, $capabilities->getInputVariants());
        self::assertContains(ImageInfo::VARIANT_BMP_BIT_FIELDS, $capabilities->getInputVariants());
        self::assertContains('it failed to convert the bmp_64_bit variant', $capabilities->getNotes());
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
