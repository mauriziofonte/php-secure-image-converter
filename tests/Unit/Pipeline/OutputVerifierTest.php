<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Pipeline;

use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\OutputVerificationException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Pipeline\OutputVerifier;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageBuilder;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\Limits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A backend output is accepted only when its content matches the job (C-11, library side).
 *
 * @covers \Mfonte\SecureImageConverter\Pipeline\OutputVerifier
 */
#[CoversClass(OutputVerifier::class)]
final class OutputVerifierTest extends TestCase
{
    /**
     * @var WorkingDirectory|null
     */
    private $root;

    protected function set_up(): void
    {
        parent::set_up();
        $this->root = WorkingDirectory::create();
    }

    protected function tear_down(): void
    {
        if ($this->root !== null) {
            $this->root->remove();
        }
        parent::tear_down();
    }

    public function testAMatchingOutputIsAccepted(): void
    {
        $info = $this->verify(self::job(40, 30), ImageBuilder::webpLossless(40, 30));

        self::assertSame([ImageFormat::WEBP, 40, 30, 1], [$info->getFormat(), $info->getWidth(), $info->getHeight(), $info->getFrameCount()]);
    }

    public function testAnAnimationMayHaveFewerFramesThanTheInput(): void
    {
        $job = new BackendJob('/srv/in', new ImageInfo(ImageFormat::GIF, 40, 30, 5, 900), '/srv/out', ImageFormat::WEBP, 40, 30);

        self::assertSame(3, $this->verify($job, ImageBuilder::webpAnimation(40, 30, 3))->getFrameCount());
    }

    public function testAJobThatKeepsTheStoredPixelsAcceptsTheTurnedSize(): void
    {
        $input = (new ImageInfo(ImageFormat::JPEG, 40, 30, 1, 900))->withOrientation(6);
        $job = new BackendJob('/srv/in', $input, '/srv/out', ImageFormat::WEBP, 40, 30);

        self::assertSame(30, $this->verify($job, ImageBuilder::webpLossless(30, 40))->getWidth());
    }

    /**
     * @return array<string, array{BackendJob, string, string}>
     */
    public static function mismatches(): array
    {
        $oriented = BackendJob::fromRequest('/srv/in', (new ImageInfo(ImageFormat::JPEG, 40, 30, 1, 900))->withOrientation(6), '/srv/out', new ConversionRequest(ConversionRequest::FORMAT_ORIGINAL));
        $animation = new BackendJob('/srv/in', new ImageInfo(ImageFormat::GIF, 40, 30, 2, 900), '/srv/out', ImageFormat::WEBP, 40, 30);

        return [
            'not an image' => [self::job(40, 30), "#!/bin/sh\necho owned\n", 'The output is not a valid image.'],
            'empty' => [self::job(40, 30), '', 'The output is not a valid image.'],
            'svg' => [self::job(40, 30), ImageBuilder::svg(), 'The output is not a valid image.'],
            'another format' => [self::job(40, 30), ImageBuilder::png(40, 30), 'The output is png instead of webp.'],
            'another size' => [self::job(40, 30), ImageBuilder::webpLossless(41, 30), 'The output is 41x30 instead of 40x30.'],
            'turned without being asked' => [self::job(40, 30), ImageBuilder::webpLossless(30, 40), 'The output is 30x40 instead of 40x30.'],
            'frames for a still job' => [self::job(40, 30), ImageBuilder::webpAnimation(40, 30, 2), 'The output has 2 frames instead of 1.'],
            'more frames than the input' => [$animation, ImageBuilder::webpAnimation(40, 30, 3), 'The output has 3 frames instead of 2.'],
            'still declares an orientation' => [$oriented, ImageBuilder::jpeg(30, 40, 1, ImageBuilder::jpegSegment(0xE1, "Exif\0\0" . ImageBuilder::exifOrientation(6))), 'The output was turned upright but still declares an orientation.'],
            'above the output size' => [self::job(40, 30), ImageBuilder::webpLossless(40, 30) . str_repeat("\0", 5000), 'The output is larger than the allowed output size.'],
        ];
    }

    /**
     * @dataProvider mismatches
     */
    #[DataProvider('mismatches')]
    public function testAMismatchedOutputIsRejected(BackendJob $job, string $bytes, string $message): void
    {
        $this->expectException(OutputVerificationException::class);
        $this->expectExceptionMessage($message);

        $this->verify($job, $bytes);
    }

    public function testAnOutputAboveTheConverterLimitsIsRejected(): void
    {
        $capabilities = new Capabilities([], [ImageFormat::WEBP], [], [], IsolationLevel::NONE, (new Limits())->withMaxWidth(20)->toArray());

        $this->expectException(OutputVerificationException::class);
        $this->expectExceptionMessage('The output exceeds the limits of the converter.');

        (new OutputVerifier())->verify(self::job(40, 30), $this->write(ImageBuilder::webpLossless(40, 30)), $capabilities, 4096);
    }

    private function verify(BackendJob $job, string $bytes): ImageInfo
    {
        $capabilities = new Capabilities([], [ImageFormat::WEBP], [], [], IsolationLevel::NONE, (new Limits())->toArray());

        return (new OutputVerifier())->verify($job, $this->write($bytes), $capabilities, 4096);
    }

    private function write(string $bytes): string
    {
        self::assertNotNull($this->root);
        $path = $this->root->pathOf('out');
        file_put_contents($path, $bytes);

        return $path;
    }

    private static function job(int $width, int $height): BackendJob
    {
        return new BackendJob('/srv/in', new ImageInfo(ImageFormat::PNG, $width, $height, 1, 900), '/srv/out', ImageFormat::WEBP, $width, $height);
    }
}
