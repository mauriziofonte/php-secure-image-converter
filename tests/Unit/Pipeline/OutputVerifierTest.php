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
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageCorpus;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
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

    /**
     * Every valid image of the committed corpus, and the outputs of a forging backend, as an output of a job of its own
     * format, size and frame count.
     *
     * @return array<string, array{string}>
     */
    public static function outputsWithExtras(): array
    {
        $cases = [];
        foreach (ImageCorpus::cases() as $name => $case) {
            $format = $case['expect']['format'] ?? null;
            if ($case['committed'] && is_string($format) && $format !== ImageFormat::SVG) {
                $cases[$name] = [$case['bytes']];
            }
        }
        $vp8l = ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l(40, 30));
        $cases['webp followed by a page'] = [ImageBuilder::riff($vp8l) . '<html><script>alert(1)</script></html>'];
        $cases['webp with an EXIF chunk'] = [ImageBuilder::riff(ImageBuilder::vp8x(0x08, 40, 30) . $vp8l . ImageBuilder::webpChunk('EXIF', "MM\x00\x2a\x00\x00\x00\x08\x00\x00GPS-secret"))];
        $cases['png followed by a script'] = [ImageBuilder::png(40, 30) . '<?php echo 1; ?>'];
        $cases['png with a text chunk'] = [ImageBuilder::png(40, 30, 8, 2, ImageBuilder::pngChunk('tEXt', "Comment\0secret"))];
        $cases['jpeg followed by an archive'] = [ImageBuilder::jpeg(40, 30) . "PK\x03\x04payload"];
        $cases['jpeg with an ICC profile'] = [ImageBuilder::jpeg(40, 30, 1, ImageBuilder::jpegIcc())];
        $cases['jpeg with EXIF'] = [ImageBuilder::jpeg(40, 30, 1, ImageBuilder::jpegExif())];
        $cases['gif followed by a page'] = [ImageBuilder::gif(40, 30) . '<html></html>'];
        $cases['gif with a comment'] = [ImageBuilder::gif(40, 30, 1, ImageBuilder::gifComment('secret'))];
        $cases['bmp followed by a script'] = [ImageBuilder::bmp(40, 30) . '<?php echo 1; ?>'];
        $cases['tiff followed by an archive'] = [ImageBuilder::tiff(40, 30) . "PK\x03\x04payload"];

        return $cases;
    }

    /**
     * An output with data that decoders skip is always rejected; one with metadata when the job strips it; one with an
     * ICC profile when the job strips it without keeping the profile. Anything else is accepted.
     *
     * @dataProvider outputsWithExtras
     */
    #[DataProvider('outputsWithExtras')]
    public function testDataThatDecodersSkipAndUnrequestedMetadataAreRejected(string $bytes): void
    {
        $path = $this->write($bytes);
        $info = self::unlimitedInspector()->inspect($path);
        $job = new BackendJob('/srv/in', $info, '/srv/out', $info->getFormat(), $info->getWidth(), $info->getHeight());
        $limits = new Limits();
        foreach (Capabilities::limitNames() as $name) {
            $limits = $limits->withLimit($name, Capabilities::LIMIT_VALUE_MAX);
        }
        $capabilities = new Capabilities([], [], [], [], IsolationLevel::NONE, $limits->toArray());

        foreach ([[false, false], [true, true], [true, false]] as list($strip, $keepIcc)) {
            $expected = null;
            if ($info->hasTrailingData()) {
                $expected = 'The output holds data that decoders skip.';
            } elseif ($strip && $info->hasMetadata()) {
                $expected = 'The output carries metadata that was to be stripped.';
            } elseif ($strip && !$keepIcc && $info->hasIccProfile()) {
                $expected = 'The output carries an ICC profile that was to be stripped.';
            }
            $message = null;

            try {
                (new OutputVerifier())->verify($job->withStripMetadata($strip)->withKeepIccProfile($keepIcc), $path, $capabilities, strlen($bytes));
            } catch (OutputVerificationException $exception) {
                $message = $exception->getMessage();
            }
            self::assertSame($expected, $message, sprintf('strip %s, keep ICC %s', var_export($strip, true), var_export($keepIcc, true)));
        }
    }

    /**
     * The cases above cover every output format: data after the image for each, metadata for each that carries it.
     */
    public function testTheExtrasCoverEveryOutputFormat(): void
    {
        $found = ['trailing' => [], 'metadata' => [], 'icc' => []];
        foreach (self::outputsWithExtras() as $case) {
            $path = $this->write($case[0]);
            $info = self::unlimitedInspector()->inspect($path);
            foreach (['trailing' => $info->hasTrailingData(), 'metadata' => $info->hasMetadata() && !$info->hasTrailingData(), 'icc' => $info->hasIccProfile() && !$info->hasTrailingData()] as $kind => $present) {
                if ($present) {
                    $found[$kind][$info->getFormat()] = true;
                }
            }
        }
        $raster = array_values(array_diff(ImageFormat::all(), [ImageFormat::SVG]));
        sort($raster);
        foreach ($found as $kind => $formats) {
            $formats = array_keys($formats);
            sort($formats);
            $found[$kind] = $formats;
        }

        self::assertSame($raster, $found['trailing']);
        self::assertSame(array_values(array_diff($raster, [ImageFormat::BMP])), $found['metadata']);
        self::assertContains(ImageFormat::JPEG, $found['icc']);
        self::assertContains(ImageFormat::BMP, $found['icc']);
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

    private static function unlimitedInspector(): ImageInspector
    {
        $limits = new Limits();
        foreach (Capabilities::limitNames() as $name) {
            $limits = $limits->withLimit($name, Capabilities::LIMIT_VALUE_MAX);
        }

        return new ImageInspector($limits, array_values(array_diff(ImageFormat::all(), [ImageFormat::SVG])));
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
