<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Pipeline;

use Mfonte\SecureImageConverter\Backend\BackendCapabilities;
use Mfonte\SecureImageConverter\Backend\BackendInterface;
use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\BackendResolver;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\OutputVerificationException;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\ProcessingBudget;
use Mfonte\SecureImageConverter\Pipeline\SecureConverter;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageBuilder;
use Mfonte\SecureImageConverter\Tests\Unit\Pipeline\Fake\FakeSemaphore;
use Mfonte\SecureImageConverter\Tests\Unit\Pipeline\Fake\RecordingInspector;
use Mfonte\SecureImageConverter\Tests\Unit\Pipeline\Fake\WritingBackend;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use Mfonte\SecureImageConverter\Validation\Limits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every branch of the pipeline with fake backends that write chosen bytes, a recording inspector and a fake semaphore:
 * fallback, orientation, passthrough and the smaller-file rule, verification, admission, and cleanup on every path.
 *
 * @covers \Mfonte\SecureImageConverter\Pipeline\SecureConverter
 */
#[CoversClass(SecureConverter::class)]
final class SecureConverterTest extends TestCase
{
    /**
     * @var WorkingDirectory|null
     */
    private $root;

    protected function set_up(): void
    {
        parent::set_up();
        $this->root = WorkingDirectory::create();
        mkdir($this->path('work'), 0700);
    }

    protected function tear_down(): void
    {
        if ($this->root !== null) {
            $this->root->remove();
        }
        parent::tear_down();
    }

    public function testAStillImageIsConvertedVerifiedAndPublished(): void
    {
        $backend = self::backend('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP], self::webpWriter());
        $inspector = self::inspector();
        $input = $this->file('in.png', ImageBuilder::png(40, 30));

        $result = $this->converter([$backend], $inspector)->convert($input, $this->path('out.webp'), new ConversionRequest());

        self::assertSame(ImageFormat::WEBP, $result->getOutputFormat());
        self::assertSame([40, 30, 1], [$result->getWidth(), $result->getHeight(), $result->getFrameCount()]);
        self::assertSame('cwebp', $result->getBackend());
        self::assertSame(IsolationLevel::RLIMIT, $result->getIsolationLevel());
        self::assertFalse($result->isPassthrough());
        self::assertSame([], $result->getWarnings());
        self::assertSame($this->path('out.webp'), $result->getOutputPath());
        self::assertSame(ImageBuilder::webpLossless(40, 30), file_get_contents($this->path('out.webp')));
        self::assertSame(filesize($this->path('out.webp')), $result->getByteSize());
        $this->assertCleanedUp();

        // The inspector read a private copy in the working directory, never the caller's path.
        self::assertCount(1, $inspector->inspected);
        self::assertStringStartsWith($this->path('work') . '/sic-', $inspector->inspected[0][0]);
        self::assertSame(ImageBuilder::png(40, 30), $inspector->inspected[0][1]);
        // The backend decoded that same copy.
        self::assertSame($inspector->inspected[0][0], $backend->jobs[0]->getInputPath());
        self::assertSame(80, $backend->jobs[0]->getQuality());
        self::assertNotNull($backend->jobs[0]->getTimeoutSeconds(), 'every job carries its budget');
    }

    public function testAResizeAndTheSettingsReachTheBackend(): void
    {
        $backend = self::backend('vips', [ImageFormat::PNG], [ImageFormat::WEBP], self::webpWriter());
        $request = (new ConversionRequest())->withWidth(20)->withHeight(20)->withFit(ConversionRequest::FIT_COVER)
            ->withQuality(55)->withEffort(7)->withStripMetadata(false);

        $result = $this->converter([$backend])->convert($this->file('in.png', ImageBuilder::png(40, 30)), $this->path('out.webp'), $request);

        self::assertSame([20, 20], [$result->getWidth(), $result->getHeight()]);
        $job = $backend->jobs[0];
        self::assertSame([20, 20, ConversionRequest::FIT_COVER, 55, 7, false], [$job->getWidth(), $job->getHeight(), $job->getFit(), $job->getQuality(), $job->getEffort(), $job->shouldStripMetadata()]);
    }

    public function testAvifFallsBackToWebpAndSaysWhy(): void
    {
        $backend = self::backend('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP], self::webpWriter());

        $result = $this->converter([$backend])->convert(
            $this->file('in.png', ImageBuilder::png(40, 30)),
            $this->path('out.img'),
            new ConversionRequest(ImageFormat::AVIF)
        );

        self::assertSame(ImageFormat::WEBP, $result->getOutputFormat());
        self::assertSame(['The avif output is not available, fell back to webp: avif: cwebp: it does not encode avif'], $result->getWarnings());
        self::assertSame(ConversionRequest::DEFAULT_QUALITY_WEBP, $backend->jobs[0]->getQuality(), 'the fallback takes its own default quality');
    }

    public function testAnAnimationReducedToItsFirstFrameIsReported(): void
    {
        $backend = self::backend('gd', [ImageFormat::GIF], [ImageFormat::WEBP], self::webpWriter(), static function (BackendCapabilities $capabilities) {
            return $capabilities->withFirstFrameFormats([ImageFormat::GIF]);
        });

        $result = $this->converter([$backend])->convert(
            $this->file('in.gif', ImageBuilder::gifAnimation(40, 30, 3)),
            $this->path('out.webp'),
            new ConversionRequest()
        );

        self::assertSame(1, $result->getFrameCount());
        self::assertSame(['The animation was reduced to its first frame: no available backend writes an animated webp.'], $result->getWarnings());
    }

    public function testAnAnimationIsKeptAndVerifiedFrameByFrame(): void
    {
        $writer = static function (BackendJob $job): void {
            file_put_contents($job->getOutputPath(), ImageBuilder::webpAnimation($job->getWidth(), $job->getHeight(), $job->getFrameCount()));
        };
        $backend = self::backend('gif2webp', [ImageFormat::GIF], [ImageFormat::WEBP], $writer, static function (BackendCapabilities $capabilities) {
            return $capabilities->withAnimatedInputFormats([ImageFormat::GIF])->withAnimatedOutputFormats([ImageFormat::WEBP]);
        });

        $result = $this->converter([$backend])->convert(
            $this->file('in.gif', ImageBuilder::gifAnimation(40, 30, 3)),
            $this->path('out.webp'),
            new ConversionRequest()
        );

        self::assertSame(3, $result->getFrameCount());
        self::assertTrue($result->isAnimated());
    }

    public function testAnInputThatIsSmallerAndSatisfiesTheRequestIsReturnedUnchanged(): void
    {
        $input = ImageBuilder::png(40, 30);
        $backend = self::backend('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP], self::webpWriter(strlen($input)));

        $result = $this->converter([$backend])->convert($this->file('in.png', $input), $this->path('out.webp'), new ConversionRequest());

        self::assertTrue($result->isPassthrough());
        self::assertSame($input, file_get_contents($this->path('out.webp')), 'byte for byte');
        self::assertSame([ImageFormat::PNG, 40, 30, strlen($input)], [$result->getOutputFormat(), $result->getWidth(), $result->getHeight(), $result->getByteSize()]);
        self::assertSame('cwebp', $result->getBackend(), 'the backend whose output was discarded');
        self::assertCount(1, $result->getWarnings());
        self::assertStringStartsWith('The webp output (', $result->getWarnings()[0]);
        self::assertStringEndsWith('was not smaller than the input (' . strlen($input) . ' bytes): the input is returned unchanged.', $result->getWarnings()[0]);
        $this->assertCleanedUp();
    }

    /**
     * Inputs larger than their converted output, but that do not satisfy the request: never passed through.
     *
     * @return array<string, array{string, ConversionRequest}>
     */
    public static function inputsNeverPassedThrough(): array
    {
        $exif = ImageBuilder::jpegSegment(0xE1, "Exif\0\0" . ImageBuilder::exifOrientation(1));

        return [
            'metadata to strip' => [ImageBuilder::jpeg(40, 30, 1, $exif), new ConversionRequest()],
            'trailing data' => [ImageBuilder::png(40, 30) . 'PK' . str_repeat("\0", 20), (new ConversionRequest())->withStripMetadata(false)],
            'resize' => [ImageBuilder::png(40, 30), (new ConversionRequest())->withWidth(20)],
            'bmp for a webp request' => [ImageBuilder::bmp(40, 30), new ConversionRequest()],
        ];
    }

    /**
     * @dataProvider inputsNeverPassedThrough
     */
    #[DataProvider('inputsNeverPassedThrough')]
    public function testAnInputThatDoesNotSatisfyTheRequestIsNeverReturned(string $input, ConversionRequest $request): void
    {
        $backend = self::backend('vips', [ImageFormat::PNG, ImageFormat::JPEG, ImageFormat::BMP], [ImageFormat::WEBP], self::webpWriter(strlen($input) + 100));

        $result = $this->converter([$backend])->convert($this->file('in.img', $input), $this->path('out.webp'), $request);

        self::assertFalse($result->isPassthrough());
        self::assertSame(ImageFormat::WEBP, $result->getOutputFormat());
        self::assertGreaterThan(strlen($input), $result->getByteSize());
        self::assertSame([], $result->getWarnings());
    }

    public function testMetadataIsKeptWhenStrippingIsNotRequested(): void
    {
        $input = ImageBuilder::jpeg(40, 30, 1, ImageBuilder::jpegSegment(0xE1, "Exif\0\0" . ImageBuilder::exifOrientation(1)));
        $backend = self::backend('vips', [ImageFormat::JPEG], [ImageFormat::WEBP], self::webpWriter(strlen($input)));

        $result = $this->converter([$backend])->convert($this->file('in.jpg', $input), $this->path('out.webp'), (new ConversionRequest())->withStripMetadata(false));

        self::assertTrue($result->isPassthrough(), 'its metadata may stay, so the original is acceptable');
        self::assertSame($input, file_get_contents($this->path('out.webp')));
    }

    public function testAnOrientedInputIsTurnedUprightByABackendThatCan(): void
    {
        $input = ImageBuilder::jpeg(40, 30, 1, ImageBuilder::jpegSegment(0xE1, "Exif\0\0" . ImageBuilder::exifOrientation(6)));
        $plain = self::backend('cwebp', [ImageFormat::JPEG], [ImageFormat::WEBP], self::webpWriter());
        $turning = self::backend('gd', [ImageFormat::JPEG], [ImageFormat::WEBP], self::webpWriter(), static function (BackendCapabilities $capabilities) {
            return $capabilities->withOrientedFormats([ImageFormat::JPEG]);
        });

        $result = $this->converter([$plain, $turning])->convert($this->file('in.jpg', $input), $this->path('out.webp'), (new ConversionRequest())->withWidth(15));

        self::assertSame('gd', $result->getBackend());
        self::assertSame([15, 20], [$result->getWidth(), $result->getHeight()]);
        self::assertSame(6, $turning->jobs[0]->getOrientation());
        self::assertSame([], $result->getWarnings());
    }

    public function testWithoutABackendThatTurnsImagesTheInputIsConvertedAsStoredWithAWarning(): void
    {
        $input = ImageBuilder::jpeg(40, 30, 1, ImageBuilder::jpegSegment(0xE1, "Exif\0\0" . ImageBuilder::exifOrientation(6)));
        $plain = self::backend('cwebp', [ImageFormat::JPEG], [ImageFormat::WEBP], self::webpWriter());

        $result = $this->converter([$plain])->convert($this->file('in.jpg', $input), $this->path('out.webp'), (new ConversionRequest())->withWidth(20));

        self::assertSame([20, 15], [$result->getWidth(), $result->getHeight()], 'the stored aspect ratio, never a distorted one');
        self::assertSame(1, $plain->jobs[0]->getOrientation());
        self::assertSame(['No available backend applies the EXIF orientation 6 of this jpeg image: it is converted as stored.'], $result->getWarnings());
    }

    public function testATargetThatNoBackendEncodesIsUnsupported(): void
    {
        $backend = self::backend('cwebp', [ImageFormat::JPEG], [ImageFormat::WEBP], self::webpWriter());

        $exception = $this->failure([$backend], ImageBuilder::jpeg(40, 30), new ConversionRequest(ConversionRequest::FORMAT_ORIGINAL));

        self::assertSame(ErrorCode::UNSUPPORTED_FORMAT, $exception->getErrorCode());
        self::assertSame([], $backend->jobs);
    }

    /**
     * Outputs a backend could leave instead of the requested image.
     *
     * @return array<string, array{callable(BackendJob): void, string}>
     */
    public static function forgedOutputs(): array
    {
        return [
            'a PNG instead of a WEBP' => [static function (BackendJob $job): void {
                file_put_contents($job->getOutputPath(), ImageBuilder::png($job->getWidth(), $job->getHeight()));
            }, 'The output is png instead of webp.'],
            'the wrong size' => [static function (BackendJob $job): void {
                file_put_contents($job->getOutputPath(), ImageBuilder::webpLossless($job->getWidth() + 1, $job->getHeight()));
            }, 'The output is 41x30 instead of 40x30.'],
            'a script' => [static function (BackendJob $job): void {
                file_put_contents($job->getOutputPath(), '<?php system($_GET["c"]);');
            }, 'The output is not a valid image.'],
            'a truncated file' => [static function (BackendJob $job): void {
                file_put_contents($job->getOutputPath(), substr(ImageBuilder::webpLossless($job->getWidth(), $job->getHeight()), 0, 20));
            }, 'The output is not a valid image.'],
            'an animation for a still job' => [static function (BackendJob $job): void {
                file_put_contents($job->getOutputPath(), ImageBuilder::webpAnimation($job->getWidth(), $job->getHeight(), 2));
            }, 'The output has 2 frames instead of 1.'],
        ];
    }

    /**
     * @dataProvider forgedOutputs
     *
     * @param callable(BackendJob): void $writer
     */
    #[DataProvider('forgedOutputs')]
    public function testAForgedOutputIsRejected(callable $writer, string $message): void
    {
        $backend = self::backend('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP], $writer);
        file_put_contents($this->path('out.webp'), 'previous');

        $exception = $this->failure([$backend], ImageBuilder::png(40, 30), new ConversionRequest());

        self::assertInstanceOf(OutputVerificationException::class, $exception);
        self::assertSame(ErrorCode::OUTPUT_VERIFICATION_FAILED, $exception->getErrorCode());
        self::assertSame($message, $exception->getMessage());
        self::assertSame('previous', file_get_contents($this->path('out.webp')), 'the output path is left as it was');
    }

    public function testAnOutputAboveTheAllowedSizeIsRejected(): void
    {
        $backend = self::backend('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP], self::webpWriter(5000));
        $options = (new ConverterOptions())->withMaxOutputBytes(1000);

        $exception = $this->failure([$backend], ImageBuilder::png(40, 30), new ConversionRequest(), $options);

        self::assertInstanceOf(OutputVerificationException::class, $exception);
    }

    public function testABackendFailureLeavesNothingBehind(): void
    {
        $backend = self::backend('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP], static function (BackendJob $job): void {
            file_put_contents($job->getOutputPath(), 'partial');

            throw new ConversionTimeoutException('The conversion exceeded its timeout.');
        });

        $exception = $this->failure([$backend], ImageBuilder::png(40, 30), new ConversionRequest());

        self::assertInstanceOf(ConversionTimeoutException::class, $exception);
    }

    public function testAJobAboveTheProcessingCeilingIsRefusedBeforeItRuns(): void
    {
        $backend = self::backend('vips', [ImageFormat::PNG], [ImageFormat::AVIF], self::webpWriter());
        $options = (new ConverterOptions())->withBudget((new ProcessingBudget())->withMinSeconds(1.0)->withMaxSeconds(10.0));

        $exception = $this->failure([$backend], ImageBuilder::png(40, 30), (new ConversionRequest(ImageFormat::AVIF))->withEffort(9), $options);

        self::assertInstanceOf(ResourceLimitException::class, $exception);
        self::assertSame(ErrorCode::RESOURCE_LIMIT, $exception->getErrorCode());
        self::assertSame([], $backend->jobs);
    }

    public function testTheSemaphoreBoundsTheRunAndIsReleased(): void
    {
        $semaphore = new FakeSemaphore();
        $backend = self::backend('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP], static function (BackendJob $job) use ($semaphore): void {
            self::assertCount(1, $semaphore->leases);
            self::assertFalse($semaphore->leases[0]->isReleased(), 'the slot is held while the backend runs');
            file_put_contents($job->getOutputPath(), ImageBuilder::webpLossless($job->getWidth(), $job->getHeight()));
        });
        $options = (new ConverterOptions())->withSlotWaitSeconds(2.5);

        $this->converter([$backend], null, $options, $semaphore)->convert($this->file('in.png', ImageBuilder::png(40, 30)), $this->path('out.webp'), new ConversionRequest());

        self::assertSame([2.5], $semaphore->waits);
        self::assertTrue($semaphore->leases[0]->isReleased());
    }

    public function testABusySemaphoreIsAConcurrencyLimit(): void
    {
        $semaphore = new FakeSemaphore();
        $semaphore->busy = true;
        $backend = self::backend('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP], self::webpWriter());

        $exception = $this->failure([$backend], ImageBuilder::png(40, 30), new ConversionRequest(), (new ConverterOptions())->withSlotWaitSeconds(3.0), $semaphore);

        self::assertInstanceOf(ConcurrencyLimitException::class, $exception);
        self::assertSame(3, $exception->getRetryAfterSeconds());
        self::assertSame([], $backend->jobs);
    }

    public function testTheSlotIsReleasedWhenTheBackendFails(): void
    {
        $semaphore = new FakeSemaphore();
        $backend = self::backend('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP], static function (): void {
            throw new ConversionFailedException('boom');
        });

        $this->failure([$backend], ImageBuilder::png(40, 30), new ConversionRequest(), null, $semaphore);

        self::assertTrue($semaphore->leases[0]->isReleased());
    }

    /**
     * Inputs and requests refused before any backend runs, with their error code.
     *
     * @return array<string, array{string, ConversionRequest, string}>
     */
    public static function refusedInputs(): array
    {
        return [
            'too large a file' => [str_repeat("\0", 2000), new ConversionRequest(), ErrorCode::FILE_TOO_LARGE],
            'unidentified' => ['<html></html>', new ConversionRequest(), ErrorCode::UNSUPPORTED_FORMAT],
            'truncated' => [substr(ImageBuilder::png(40, 30), 0, 60), new ConversionRequest(), ErrorCode::INVALID_IMAGE],
            'animation rejected' => [ImageBuilder::gifAnimation(40, 30, 3), (new ConversionRequest())->withAnimationPolicy(ConversionRequest::ANIMATION_REJECT), ErrorCode::ANIMATION_REJECTED],
            'enlarged beyond the limits' => [ImageBuilder::png(40, 30), (new ConversionRequest())->withWidth(500)->withEnlargementAllowed(true), ErrorCode::IMAGE_TOO_LARGE],
        ];
    }

    /**
     * @dataProvider refusedInputs
     */
    #[DataProvider('refusedInputs')]
    public function testAnInputIsRefusedBeforeAnyBackendRuns(string $input, ConversionRequest $request, string $code): void
    {
        $backend = self::backend('vips', [ImageFormat::PNG, ImageFormat::GIF], [ImageFormat::WEBP], self::webpWriter());
        $limits = (new Limits())->withMaxBytes(max(1500, strlen($input)))->withMaxWidth(100);
        if ($code === ErrorCode::FILE_TOO_LARGE) {
            $limits = $limits->withMaxBytes(1000);
        }
        $inspector = new RecordingInspector(new ImageInspector($limits, [ImageFormat::PNG, ImageFormat::GIF]));

        $exception = $this->failure([$backend], $input, $request, null, null, $inspector);

        self::assertSame($code, $exception->getErrorCode());
        self::assertSame([], $backend->jobs);
    }

    /**
     * @return array<string, array{callable(string, string): array{0: string, 1: string}}>
     */
    public static function unusablePaths(): array
    {
        return [
            'same file' => [static function (string $input, string $root): array {
                return [$input, $input];
            }],
            'missing input' => [static function (string $input, string $root): array {
                return [$root . '/missing.png', $root . '/out.webp'];
            }],
            'missing output directory' => [static function (string $input, string $root): array {
                return [$input, $root . '/nowhere/out.webp'];
            }],
            'output is a directory' => [static function (string $input, string $root): array {
                return [$input, $root . '/work'];
            }],
            'stream wrapper' => [static function (string $input, string $root): array {
                return ['php://stdin', $root . '/out.webp'];
            }],
            'input is a directory' => [static function (string $input, string $root): array {
                return [$root . '/work', $root . '/out.webp'];
            }],
        ];
    }

    /**
     * @dataProvider unusablePaths
     *
     * @param callable(string, string): array{0: string, 1: string} $paths
     */
    #[DataProvider('unusablePaths')]
    public function testUnusablePathsAreAnInternalError(callable $paths): void
    {
        $backend = self::backend('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP], self::webpWriter());
        list($input, $output) = $paths($this->file('in.png', ImageBuilder::png(40, 30)), $this->path(''));

        try {
            $this->converter([$backend])->convert($input, $output, new ConversionRequest());
            self::fail('The paths were accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(ErrorCode::INTERNAL_ERROR, $exception->getErrorCode());
        }
        $this->assertCleanedUp();
        self::assertSame(ImageBuilder::png(40, 30), file_get_contents($this->path('in.png')));
    }

    public function testAFifoInputIsRefusedWithoutBlocking(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo() is not available.');
        }
        self::assertTrue(posix_mkfifo($this->path('fifo'), 0600));
        $backend = self::backend('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP], self::webpWriter());

        $this->expectException(InvalidArgumentException::class);
        $this->converter([$backend])->convert($this->path('fifo'), $this->path('out.webp'), new ConversionRequest());
    }

    public function testCapabilitiesCombineTheInspectorAndTheAvailableBackends(): void
    {
        $vips = self::backend('vips', [ImageFormat::PNG, ImageFormat::GIF, ImageFormat::TIFF], [ImageFormat::WEBP, ImageFormat::PNG], self::webpWriter(), static function (BackendCapabilities $capabilities) {
            return $capabilities->withAnimatedInputFormats([ImageFormat::GIF])->withAnimatedOutputFormats([ImageFormat::WEBP]);
        });
        $gone = new WritingBackend(BackendCapabilities::unavailable('gd', 'php is not installed'), self::webpWriter());
        $options = (new ConverterOptions())->withIsolation(IsolationLevel::SANDBOX, ['Isolation degraded.']);
        $converter = new SecureConverter(self::inspector(), new BackendResolver([$vips, $gone]), $options);

        $capabilities = $converter->getCapabilities();

        self::assertSame([ImageFormat::PNG, ImageFormat::GIF], $capabilities->getInputFormats(), 'enabled and decoded');
        self::assertSame([ImageFormat::PNG, ImageFormat::WEBP], $capabilities->getOutputFormats());
        self::assertSame([ImageFormat::WEBP], $capabilities->getAnimatedOutputFormats());
        self::assertSame(['vips'], $capabilities->getBackends());
        self::assertSame(IsolationLevel::SANDBOX, $capabilities->getIsolationLevel());
        self::assertSame((new Limits())->toArray(), $capabilities->getLimits());
        self::assertSame($capabilities, $converter->getCapabilities(), 'cached');
    }

    public function testIsolationWarningsAreReportedWithEveryResult(): void
    {
        $backend = self::backend('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP], self::webpWriter());
        $options = (new ConverterOptions())->withIsolation(IsolationLevel::RLIMIT, ['Isolation degraded to the rlimit level.']);

        $result = $this->converter([$backend], null, $options)->convert($this->file('in.png', ImageBuilder::png(40, 30)), $this->path('out.webp'), new ConversionRequest());

        self::assertSame(['Isolation degraded to the rlimit level.'], $result->getWarnings());
    }

    public function testAnExistingOutputIsReplaced(): void
    {
        $backend = self::backend('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP], self::webpWriter());
        file_put_contents($this->path('out.webp'), 'old');

        $this->converter([$backend])->convert($this->file('in.png', ImageBuilder::png(40, 30)), $this->path('out.webp'), new ConversionRequest());

        self::assertSame(ImageBuilder::webpLossless(40, 30), file_get_contents($this->path('out.webp')));
        self::assertSame(['in.png', 'out.webp', 'work'], self::entries($this->path('')));
    }

    /**
     * A converter over the backends with the default limits and the PNG, JPEG, GIF and BMP inputs, its working
     * directories under "work".
     *
     * @param list<BackendInterface> $backends
     */
    private function converter(array $backends, ?RecordingInspector $inspector = null, ?ConverterOptions $options = null, ?FakeSemaphore $semaphore = null): SecureConverter
    {
        $options = ($options !== null ? $options : new ConverterOptions())->withWorkingDirectoryParent($this->path('work'));

        return new SecureConverter($inspector !== null ? $inspector : self::inspector(), new BackendResolver($backends), $options, $semaphore);
    }

    /**
     * Runs a conversion that must fail, checks that it left nothing behind, and returns its exception.
     *
     * @param list<BackendInterface> $backends
     */
    private function failure(array $backends, string $input, ConversionRequest $request, ?ConverterOptions $options = null, ?FakeSemaphore $semaphore = null, ?RecordingInspector $inspector = null): ExceptionInterface
    {
        $before = file_exists($this->path('out.webp')) ? file_get_contents($this->path('out.webp')) : null;

        try {
            $this->converter($backends, $inspector, $options, $semaphore)->convert($this->file('in.img', $input), $this->path('out.webp'), $request);
        } catch (ExceptionInterface $exception) {
            $this->assertCleanedUp();
            self::assertSame($before, file_exists($this->path('out.webp')) ? file_get_contents($this->path('out.webp')) : null, 'no output is left');

            return $exception;
        }
        self::fail('The conversion succeeded.');
    }

    private static function inspector(): RecordingInspector
    {
        return new RecordingInspector(new ImageInspector(null, [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::BMP]));
    }

    /**
     * @param list<string>                                              $inputs
     * @param list<string>                                              $outputs
     * @param callable(BackendJob): void                                $writer
     * @param (callable(BackendCapabilities): BackendCapabilities)|null $more
     */
    private static function backend(string $name, array $inputs, array $outputs, callable $writer, ?callable $more = null): WritingBackend
    {
        $capabilities = (new BackendCapabilities($name, $inputs, $outputs))->withFits(ConversionRequest::fitModes());

        return new WritingBackend($more !== null ? $more($capabilities) : $capabilities, $writer);
    }

    /**
     * A writer of a lossless WEBP of the job's size, padded to at least $size bytes with an unknown chunk.
     *
     * @return callable(BackendJob): void
     */
    private static function webpWriter(int $size = 0): callable
    {
        return static function (BackendJob $job) use ($size): void {
            $chunks = ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l($job->getWidth(), $job->getHeight()));
            $padding = max(0, $size - strlen(ImageBuilder::riff($chunks)) - 8);
            file_put_contents($job->getOutputPath(), ImageBuilder::riff($chunks . ($size > 0 ? ImageBuilder::webpChunk('ABCD', str_repeat('x', $padding)) : '')));
        };
    }

    private function file(string $name, string $bytes): string
    {
        file_put_contents($this->path($name), $bytes);

        return $this->path($name);
    }

    private function path(string $name): string
    {
        self::assertNotNull($this->root);

        return $name === '' ? $this->root->getPath() : $this->root->pathOf($name);
    }

    private function assertCleanedUp(): void
    {
        self::assertSame([], self::entries($this->path('work')), 'every working directory is removed');
        foreach (self::entries($this->path('')) as $entry) {
            self::assertStringStartsNotWith('.sic-', $entry, 'no temporary output is left');
        }
    }

    /**
     * @return list<string>
     */
    private static function entries(string $directory): array
    {
        $entries = array_values(array_diff((array) scandir($directory), ['.', '..']));
        sort($entries);

        return array_map('strval', $entries);
    }
}
