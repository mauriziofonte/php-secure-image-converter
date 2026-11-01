<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Pipeline;

use Mfonte\SecureImageConverter\Backend\BackendCapabilities;
use Mfonte\SecureImageConverter\Backend\BackendInterface;
use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\BackendResolver;
use Mfonte\SecureImageConverter\Backend\BackendResult;
use Mfonte\SecureImageConverter\Backend\Internal\ProbeImages;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\OutputVerificationException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\OutputVerifier;
use Mfonte\SecureImageConverter\Pipeline\SecureConverter;
use Mfonte\SecureImageConverter\Tests\Integration\Backend\BackendTestCase;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Golden-file conversions through SecureConverter with the real programs, at the strongest isolation level of the
 * host: each enabled backend alone, then all of them together (SIC_BACKENDS selects them, as for the other backend
 * tests).
 *
 * The checks are tolerant, never byte equality, since encoders differ between versions: the output's format, size
 * and frame count, and its PSNR against the fixture it was converted from (decoded by Imagick in this process, or GD
 * when Imagick is missing; the PSNR check is skipped for a format neither decodes). The PSNR covers the colour of the
 * opaque pixels of the fixture and, for an output with an alpha channel, the alpha of every pixel.
 *
 * @covers \Mfonte\SecureImageConverter\Pipeline\OutputVerifier
 * @covers \Mfonte\SecureImageConverter\Pipeline\PassthroughPolicy
 * @covers \Mfonte\SecureImageConverter\Pipeline\SecureConverter
 *
 * @group backends
 */
#[CoversClass(OutputVerifier::class)]
#[CoversClass(\Mfonte\SecureImageConverter\Pipeline\PassthroughPolicy::class)]
#[CoversClass(SecureConverter::class)]
#[Group('backends')]
final class GoldenConversionTest extends BackendTestCase
{
    /**
     * Least PSNR, in dB, of an output converted at the input size: lossy, lossless.
     */
    private const MIN_PSNR = 26.0;

    private const MIN_PSNR_LOSSLESS = 40.0;

    /**
     * Least PSNR of a resized output against the fixture resized by the test.
     */
    private const MIN_PSNR_RESIZED = 20.0;

    /**
     * Least PSNR of a GIF output (a palette of 256 colours at most).
     */
    private const MIN_PSNR_GIF = 22.0;

    /**
     * The request formats every still is converted to.
     */
    private const TARGETS = [ImageFormat::WEBP, ImageFormat::AVIF, ConversionRequest::FORMAT_ORIGINAL];

    /**
     * The resolver of the converter last created by converter().
     *
     * @var BackendResolver|null
     */
    private $resolver;

    /**
     * Every still the backend decodes, converted by it alone to WEBP, AVIF and its own format: the output is the
     * target, or the fallback the result reports, and close to the fixture; a target without a fallback that the
     * backend does not encode is unsupported.
     *
     * @dataProvider enabledBackends
     */
    #[DataProvider('enabledBackends')]
    public function testEachBackendAloneConvertsTheStillsCloseToTheFixture(string $name): void
    {
        $backend = self::backend($name);
        $converter = $this->converter([$backend]);
        $capabilities = $backend->getCapabilities();
        $failures = [];
        $count = 0;
        foreach ($capabilities->getInputFormats() as $format) {
            if (self::fixtures()->still($format) === null) {
                continue;
            }
            foreach (self::TARGETS as $target) {
                $wanted = $target === ConversionRequest::FORMAT_ORIGINAL ? $format : $target;
                $encodes = in_array($wanted, $capabilities->getOutputFormats(), true);
                ++$count;
                if (!$encodes && ($target === ConversionRequest::FORMAT_ORIGINAL || !in_array(ImageFormat::WEBP, $capabilities->getOutputFormats(), true))) {
                    // No fallback applies: the request is unsupported (415), and nothing is published.
                    $problem = $this->expectUnsupported($converter, $format, new ConversionRequest($target));
                } else {
                    $problem = $this->convertStill($converter, $format, new ConversionRequest($target), $capabilities);
                }
                if ($problem !== null) {
                    $failures[] = sprintf('%s -> %s: %s', $format, $target, $problem);
                }
            }
        }

        self::assertSame([], $failures, $name . ' converted these stills wrongly');
        if ($count === 0) {
            self::markTestSkipped(sprintf('The %s backend encodes none of the targets of a still.', $name));
        }
    }

    /**
     * Every still fixture, with every enabled backend available: resized, lossless, and to each target.
     */
    public function testAllBackendsConvertEveryStillCloseToTheFixture(): void
    {
        $backends = self::availableBackends();
        $converter = $this->converter($backends);
        $supported = $converter->getCapabilities();
        $failures = [];
        foreach (ImageFormat::all() as $format) {
            if (self::fixtures()->still($format) === null || !in_array($format, $supported->getInputFormats(), true)) {
                continue;
            }
            $requests = [
                'webp contain 20x20' => (new ConversionRequest())->withWidth(20)->withHeight(20),
                'webp lossless' => (new ConversionRequest())->withLossless(true),
                'avif effort 2' => (new ConversionRequest(ImageFormat::AVIF))->withEffort(2),
                'original' => new ConversionRequest(ConversionRequest::FORMAT_ORIGINAL),
            ];
            foreach ($requests as $label => $request) {
                $problem = $this->convertStill($converter, $format, $request, null);
                if ($problem !== null) {
                    $failures[] = sprintf('%s, %s: %s', $format, $label, $problem);
                }
            }
        }

        self::assertSame([], $failures, 'the converter converted these stills wrongly');
    }

    /**
     * Every animated fixture to WEBP: animated when a backend writes animated WEBP from it, otherwise its first frame
     * with a warning.
     */
    public function testAnimationsKeepTheirFramesOrReportTheFirstFrame(): void
    {
        $backends = self::availableBackends();
        $converter = $this->converter($backends);
        $animated = [];
        foreach ($backends as $backend) {
            $capabilities = $backend->getCapabilities();
            if (in_array(ImageFormat::WEBP, $capabilities->getAnimatedOutputFormats(), true)) {
                $animated = array_merge($animated, $capabilities->getAnimatedInputFormats());
            }
        }
        $count = 0;
        foreach ([ImageFormat::GIF, ImageFormat::WEBP, ImageFormat::PNG] as $format) {
            $fixture = self::animation($format);
            if ($fixture === null || !in_array($format, $converter->getCapabilities()->getInputFormats(), true)) {
                continue;
            }
            $output = $this->workingDirectory()->pathOf('anim-' . $format . '.webp');
            $result = $this->convertUnlessRefused($converter, $fixture, $output, new ConversionRequest());
            if ($result === null) {
                continue;
            }
            ++$count;
            $info = self::inspect($output);
            self::assertNotNull($info, $format . ': the output is an image');
            self::assertSame([$fixture[1]->getWidth(), $fixture[1]->getHeight()], [$info->getWidth(), $info->getHeight()], $format);
            if (in_array($format, $animated, true)) {
                self::assertSame(ImageFormat::WEBP, $result->getOutputFormat(), $format);
                self::assertGreaterThan(1, $info->getFrameCount(), $format . ': the animation is kept');
                self::assertLessThanOrEqual($fixture[1]->getFrameCount(), $info->getFrameCount(), $format);
            } else {
                self::assertSame(1, $info->getFrameCount(), $format);
                self::assertNotSame([], $result->getWarnings(), $format . ': the first frame is reported');
            }
            self::assertSame($info->getFrameCount(), $result->getFrameCount(), $format);
        }
        if ($count === 0) {
            self::markTestSkipped('No enabled backend converts an animated fixture to WEBP.');
        }
    }

    /**
     * The JPEG with EXIF orientation 6 comes out upright (12 x 16) when a backend applies the orientation, or as
     * stored with a warning; either way each quadrant has the colour of the fixture turned the same way, and a
     * lossless output is close to it.
     */
    public function testAnOrientedJpegIsTurnedUprightOrReported(): void
    {
        $backends = self::availableBackends();
        $converter = $this->converter($backends);
        $fixture = (new ProbeImages())->oriented();
        self::assertNotNull($fixture);
        if (!in_array(ImageFormat::JPEG, $converter->getCapabilities()->getInputFormats(), true)) {
            self::markTestSkipped('No enabled backend decodes JPEG.');
        }
        $orienting = false;
        foreach ($backends as $backend) {
            $orienting = $orienting || in_array(ImageFormat::JPEG, $backend->getCapabilities()->getOrientedFormats(), true);
        }
        $outputs = $converter->getCapabilities()->getOutputFormats();
        $target = in_array(ImageFormat::WEBP, $outputs, true) ? ImageFormat::WEBP : ImageFormat::AVIF;
        if (!in_array($target, $outputs, true)) {
            self::markTestSkipped('No enabled backend encodes WEBP or AVIF.');
        }
        $output = $this->workingDirectory()->pathOf('upright.out');
        // Lossless where a backend encodes the target so, else at quality 100 (GD before PHP 8.1): a lossless request
        // that none can serve falls back to the input format, with a warning of its own.
        $lossless = self::encodesLosslessly($backends, $target);
        $request = new ConversionRequest($target);
        $request = $lossless ? $request->withLossless(true) : $request->withQuality(100);
        $result = $converter->convert($fixture[0], $output, $request);

        $info = self::inspect($output);
        self::assertNotNull($info);
        if ($orienting) {
            self::assertSame([12, 16, 1], [$info->getWidth(), $info->getHeight(), $info->getOrientation()]);
            self::assertSame([], $result->getWarnings());
        } else {
            self::assertSame([16, 12], [$info->getWidth(), $info->getHeight()]);
            self::assertNotSame([], $result->getWarnings());
        }
        $reference = self::decode($fixture[0]);
        if ($reference !== null && $orienting) {
            $reference = self::turnClockwise($reference);
        }
        $decoded = self::decode($output);
        if ($reference !== null && $decoded !== null) {
            // The colour at the centre of each quadrant tells a turn from a mirror, even through a lossy encoder; the
            // PSNR, whose edges a lossy 4:2:0 encoder blurs in an image this small, is checked for a lossless output.
            foreach ([[1, 1], [3, 1], [1, 3], [3, 3]] as $quarter) {
                $offset = 4 * (intdiv($quarter[1] * $reference['height'], 4) * $reference['width'] + intdiv($quarter[0] * $reference['width'], 4));
                for ($channel = 0; $channel < 3; ++$channel) {
                    self::assertEqualsWithDelta($reference['pixels'][$offset + $channel], $decoded['pixels'][$offset + $channel], 48, sprintf('quadrant %d,%d', $quarter[0], $quarter[1]));
                }
            }
            if ($lossless) {
                self::assertGreaterThanOrEqual(self::MIN_PSNR, self::psnr($reference, $decoded, false));
            }
        }
    }

    /**
     * A still whose output is not smaller than the input is returned unchanged when the input satisfies the request,
     * and a smaller output is published otherwise.
     */
    public function testTheSmallerFileIsKept(): void
    {
        $backends = self::availableBackends();
        $converter = $this->converter($backends);
        $lossless = self::encodesLosslessly($backends, ImageFormat::WEBP);
        if (!in_array(ImageFormat::WEBP, $converter->getCapabilities()->getOutputFormats(), true)) {
            self::markTestSkipped('No enabled backend encodes WEBP.');
        }
        $passedThrough = 0;
        $converted = 0;
        foreach ([ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::WEBP] as $format) {
            $fixture = self::fixtures()->still($format);
            if ($fixture === null || !in_array($format, $converter->getCapabilities()->getInputFormats(), true)) {
                continue;
            }
            $output = $this->workingDirectory()->pathOf('smaller-' . $format);
            // Lossless at the lowest effort (quality 100 where no backend encodes WEBP losslessly), metadata kept: an
            // output that is rarely smaller than these small stills.
            $request = (new ConversionRequest())->withEffort(0)->withStripMetadata(false);
            $request = $lossless ? $request->withLossless(true) : $request->withQuality(100);
            $result = $this->convertUnlessRefused($converter, $fixture, $output, $request);
            if ($result === null) {
                continue;
            }
            ++$converted;
            if ($result->isPassthrough()) {
                ++$passedThrough;
                self::assertSame(file_get_contents($fixture[0]), file_get_contents($output), $format . ': the input is returned unchanged');
                self::assertSame($format, $result->getOutputFormat());
                self::assertStringContainsString('the input is returned unchanged', implode(' ', $result->getWarnings()));
            } else {
                self::assertLessThan(filesize($fixture[0]), $result->getByteSize(), $format . ': a published output is smaller');
                self::assertSame(ImageFormat::WEBP, $result->getOutputFormat());
            }
        }
        if ($converted === 0) {
            self::markTestSkipped('No enabled backend converts a still fixture to WEBP.');
        }
        self::assertGreaterThan(0, $passedThrough, 'at least one output is larger than its fixture');
    }

    /**
     * A real backend whose output is replaced by another valid image, of the wrong size or (for a backend that does
     * not resize) of the wrong format: the converter rejects it and leaves nothing at the destination.
     */
    public function testAForgedOutputOfARealBackendIsRejected(): void
    {
        $backends = self::availableBackends();
        $forging = new class ($backends[0]) implements BackendInterface {
            /**
             * @var BackendInterface
             */
            private $backend;

            public function __construct(BackendInterface $backend)
            {
                $this->backend = $backend;
            }

            public function getName(): string
            {
                return $this->backend->getName();
            }

            public function getCapabilities(): BackendCapabilities
            {
                return $this->backend->getCapabilities();
            }

            public function estimateMemoryBytes(BackendJob $job): int
            {
                return $this->backend->estimateMemoryBytes($job);
            }

            public function convert(BackendJob $job): BackendResult
            {
                $result = $this->backend->convert($job);
                if ($this->backend->getCapabilities()->getFits() === []) {
                    // A backend that does not resize: its output is replaced by the input, another format.
                    copy($job->getInputPath(), $job->getOutputPath());

                    return $result;
                }
                $other = (new BackendJob($job->getInputPath(), $job->getInput(), $job->getOutputPath() . '.forged', $job->getOutputFormat(), $job->getWidth() + 1, $job->getHeight()))
                    ->withAnimation(false);
                $this->backend->convert($other);
                rename($other->getOutputPath(), $job->getOutputPath());

                return $result;
            }
        };
        $converter = $this->converter([$forging]);
        $capabilities = $forging->getCapabilities();
        $format = null;
        foreach ($capabilities->getInputFormats() as $candidate) {
            if (self::fixtures()->still($candidate) !== null) {
                $format = $candidate;

                break;
            }
        }
        $outputs = $capabilities->getOutputFormats();
        if ($format === null || $outputs === []) {
            self::markTestSkipped('The first backend converts no still fixture.');
        }
        $resizes = $capabilities->getFits() !== [];
        $target = $resizes && in_array($format, $outputs, true) ? ConversionRequest::FORMAT_ORIGINAL : (in_array(ImageFormat::WEBP, $outputs, true) ? ImageFormat::WEBP : ImageFormat::AVIF);
        if (!$resizes && $target === $format) {
            self::markTestSkipped('The first backend neither resizes nor changes the format of a still fixture.');
        }
        $fixture = self::fixtures()->still($format);
        self::assertNotNull($fixture);
        $output = $this->workingDirectory()->pathOf('forged.out');

        try {
            $converter->convert($fixture[0], $output, new ConversionRequest($target));
            self::fail('The forged output was accepted.');
        } catch (OutputVerificationException $exception) {
            self::assertStringContainsString('instead of', $exception->getMessage());
        }
        self::assertFileDoesNotExist($output);
    }

    /**
     * Whether one of the backends encodes $format losslessly. Only GD before PHP 8.1 (no IMG_WEBP_LOSSLESS) declares
     * none among the backends that encode WEBP; any other backend that encodes WEBP must declare it.
     *
     * @param list<BackendInterface> $backends
     */
    private static function encodesLosslessly(array $backends, string $format): bool
    {
        $lossless = false;
        foreach ($backends as $backend) {
            $capabilities = $backend->getCapabilities();
            $declared = in_array($format, $capabilities->getLosslessFormats(), true);
            if ($format === ImageFormat::WEBP && in_array(ImageFormat::WEBP, $capabilities->getOutputFormats(), true) && !($backend->getName() === 'gd' && PHP_VERSION_ID < 80100)) {
                self::assertTrue($declared, $backend->getName() . ' encodes WEBP losslessly.');
            }
            $lossless = $lossless || $declared;
        }

        return $lossless;
    }

    /**
     * A converter with these backends, at the host's isolation level, reading every format the fixtures have.
     *
     * @param list<BackendInterface> $backends
     */
    private function converter(array $backends): SecureConverter
    {
        $inspector = new ImageInspector(null, array_values(array_diff(ImageFormat::all(), [ImageFormat::SVG])));
        $options = (new ConverterOptions())->withIsolation(self::hostDetection()->getLevel());

        $this->resolver = new BackendResolver($backends);

        return new SecureConverter($inspector, $this->resolver, $options);
    }

    /**
     * Converts a fixture, unless the resolver refuses the request with the enabled backends (a set without a backend
     * that resizes or encodes the target): then the converter must refuse it with the same exception and publish
     * nothing, and null is returned.
     *
     * @param array{0: string, 1: ImageInfo} $fixture
     */
    private function convertUnlessRefused(SecureConverter $converter, array $fixture, string $output, ConversionRequest $request): ?ConversionResult
    {
        self::assertNotNull($this->resolver);
        $refusal = null;

        try {
            $this->resolver->resolve(BackendJob::fromRequest($fixture[0], $fixture[1], $output, $request));
        } catch (ExceptionInterface $exception) {
            $refusal = $exception;
        }
        if ($refusal === null) {
            return $converter->convert($fixture[0], $output, $request);
        }

        try {
            $converter->convert($fixture[0], $output, $request);
            self::fail('The converter converted what the resolver refuses: ' . $refusal->getMessage());
        } catch (ExceptionInterface $exception) {
            self::assertSame([get_class($refusal), $refusal->getMessage()], [get_class($exception), $exception->getMessage()]);
        }
        self::assertFileDoesNotExist($output);

        return null;
    }

    /**
     * Converts the still fixture of $format with a request no backend serves. Returns what went wrong, or null.
     */
    private function expectUnsupported(SecureConverter $converter, string $format, ConversionRequest $request): ?string
    {
        $fixture = self::fixtures()->still($format);
        self::assertNotNull($fixture);
        $output = $this->workingDirectory()->pathOf(sprintf('unsupported-%s-%s.out', $format, $request->getFormat()));

        try {
            $converter->convert($fixture[0], $output, $request);
        } catch (UnsupportedFormatException $exception) {
            return file_exists($output) ? 'an output was published' : null;
        } catch (\Exception $exception) {
            return sprintf('%s instead of UnsupportedFormatException: %s', get_class($exception), $exception->getMessage());
        }

        return 'converted although no backend encodes the target';
    }

    /**
     * Converts the still fixture of $format and checks the result. Returns what went wrong, or null.
     *
     * @param BackendCapabilities|null $alone the capabilities of the only backend, or null for a set of backends,
     *                                        which may refuse what none of them serves
     */
    private function convertStill(SecureConverter $converter, string $format, ConversionRequest $request, ?BackendCapabilities $alone): ?string
    {
        $fixture = self::fixtures()->still($format);
        self::assertNotNull($fixture);
        $output = $this->workingDirectory()->pathOf(sprintf('golden-%s-%s.out', $format, md5(serialize($request))));

        try {
            $result = $alone !== null ? $converter->convert($fixture[0], $output, $request) : $this->convertUnlessRefused($converter, $fixture, $output, $request);
        } catch (\Exception $exception) {
            return sprintf('%s: %s', get_class($exception), $exception->getMessage());
        }
        if ($result === null) {
            return null;
        }
        $info = self::inspect($output);
        if ($info === null) {
            return 'the output is not a valid image';
        }
        $wanted = $request->getFormat() === ConversionRequest::FORMAT_ORIGINAL ? $format : $request->getFormat();
        $problems = [];
        if ($info->getFormat() !== $result->getOutputFormat()) {
            $problems[] = sprintf('the output is %s, the result says %s', $info->getFormat(), $result->getOutputFormat());
        }
        if ($result->getOutputFormat() !== $wanted && !$result->isPassthrough() && $result->getWarnings() === []) {
            $problems[] = sprintf('the output is %s instead of %s without a warning', $result->getOutputFormat(), $wanted);
        }
        if ($alone !== null && in_array($wanted, $alone->getOutputFormats(), true) && $result->getOutputFormat() !== $wanted && !$result->isPassthrough()) {
            $problems[] = sprintf('the backend encodes %s but wrote %s', $wanted, $result->getOutputFormat());
        }
        list($width, $height) = $request->resolveOutputSize($fixture[1]->getWidth(), $fixture[1]->getHeight());
        if ([$info->getWidth(), $info->getHeight(), $info->getFrameCount()] !== [$width, $height, 1]) {
            $problems[] = sprintf('the output is %dx%d with %d frames, not %dx%d with 1', $info->getWidth(), $info->getHeight(), $info->getFrameCount(), $width, $height);
        }
        if ($info->getWidth() !== $result->getWidth() || $info->getHeight() !== $result->getHeight() || $info->getByteSize() !== $result->getByteSize()) {
            $problems[] = 'the result does not describe the output';
        }
        if ($request->shouldStripMetadata() && !$result->isPassthrough() && $info->hasExif()) {
            $problems[] = 'the output kept the EXIF data';
        }
        if ($problems === []) {
            $resized = [$width, $height] !== [$fixture[1]->getWidth(), $fixture[1]->getHeight()];
            $problems = self::comparePixels($fixture[0], $format, $output, $resized ? [$width, $height] : null, $request, $result);
        }

        return $problems === [] ? null : implode(', ', $problems);
    }

    /**
     * @param array{int, int}|null $size the output size of a resized output, null for an output of the fixture's size
     *
     * @return list<string>
     */
    private static function comparePixels(string $fixture, string $inputFormat, string $output, ?array $size, ConversionRequest $request, ConversionResult $result): array
    {
        $reference = $size !== null ? self::decode($fixture, $size[0], $size[1]) : self::decode($fixture);
        $decoded = self::decode($output);
        if ($reference === null || $decoded === null) {
            return [];
        }
        $minimum = self::MIN_PSNR;
        if ($size !== null) {
            $minimum = self::MIN_PSNR_RESIZED;
        } elseif ($result->getOutputFormat() === ImageFormat::GIF) {
            $minimum = self::MIN_PSNR_GIF;
        } elseif ($result->isPassthrough() || ($request->isLossless() && $result->getOutputFormat() === $request->getFormat() && $inputFormat !== ImageFormat::AVIF)) {
            // Decoders upsample the chroma of an AVIF input differently: a lossless copy of it is held to the lossy
            // threshold. A lossless request that fell back to another format (no backend encodes it losslessly) is
            // held to the lossy one too.
            $minimum = self::MIN_PSNR_LOSSLESS;
        }
        $alpha = in_array($result->getOutputFormat(), [ImageFormat::PNG, ImageFormat::WEBP, ImageFormat::AVIF, ImageFormat::TIFF], true);
        $psnr = self::psnr($reference, $decoded, $alpha);

        return $psnr >= $minimum ? [] : [sprintf('the PSNR is %.1f dB, below %.1f', $psnr, $minimum)];
    }

    /**
     * The first frame of an image as 8-bit RGBA, resized to $width x $height when given; null when neither Imagick
     * nor GD decodes it.
     *
     * @return array{width: int, height: int, pixels: list<int>}|null
     */
    private static function decode(string $path, ?int $width = null, ?int $height = null): ?array
    {
        if (extension_loaded('imagick')) {
            try {
                $image = new \Imagick();
                $image->readImage($path . '[0]');
                $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);
                if ($width !== null && $height !== null && ($width !== $image->getImageWidth() || $height !== $image->getImageHeight())) {
                    $image->resizeImage($width, $height, \Imagick::FILTER_TRIANGLE, 1.0);
                }
                $w = $image->getImageWidth();
                $h = $image->getImageHeight();
                // ImageMagick 6 exports an alpha of 0 for some images without an alpha channel (AVIF): such an image
                // is exported as RGB and given an opaque alpha here.
                $opaque = !$image->getImageAlphaChannel();
                $exported = array_map('intval', $image->exportImagePixels(0, 0, $w, $h, $opaque ? 'RGB' : 'RGBA', \Imagick::PIXEL_CHAR));
                $image->clear();
                $pixels = $exported;
                if ($opaque) {
                    $pixels = [];
                    foreach (array_chunk($exported, 3) as $rgb) {
                        array_push($pixels, $rgb[0], $rgb[1], $rgb[2], 255);
                    }
                }

                return ['width' => $w, 'height' => $h, 'pixels' => $pixels];
            } catch (\Exception $exception) {
                return null;
            }
        }
        if (!extension_loaded('gd')) {
            return null;
        }
        $bytes = file_get_contents($path);
        $image = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        if ($image === false) {
            return null;
        }
        if ($width !== null && $height !== null && ($width !== imagesx($image) || $height !== imagesy($image))) {
            $scaled = imagescale($image, $width, $height, IMG_BILINEAR_FIXED);
            if ($scaled === false) {
                return null;
            }
            $image = $scaled;
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $pixels = [];
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $index = imagecolorat($image, $x, $y);
                if ($index === false) {
                    return null;
                }
                $colour = imagecolorsforindex($image, $index);
                $pixels[] = $colour['red'];
                $pixels[] = $colour['green'];
                $pixels[] = $colour['blue'];
                $pixels[] = (int) round(255 - $colour['alpha'] * 255 / 127);
            }
        }

        return ['width' => $w, 'height' => $h, 'pixels' => $pixels];
    }

    /**
     * The image turned a quarter clockwise.
     *
     * @param array{width: int, height: int, pixels: list<int>} $image
     *
     * @return array{width: int, height: int, pixels: list<int>}
     */
    private static function turnClockwise(array $image): array
    {
        $width = $image['width'];
        $height = $image['height'];
        $pixels = [];
        // The turned image is $height wide: its pixel (x, y) is the source pixel (y, height - 1 - x).
        for ($y = 0; $y < $width; ++$y) {
            for ($x = 0; $x < $height; ++$x) {
                $source = (($height - 1 - $x) * $width + $y) * 4;
                for ($channel = 0; $channel < 4; ++$channel) {
                    $pixels[] = $image['pixels'][$source + $channel];
                }
            }
        }

        return ['width' => $height, 'height' => $width, 'pixels' => $pixels];
    }

    /**
     * PSNR, in dB, of the colour of the opaque pixels of $reference and, when $alpha, of the alpha of every pixel.
     *
     * @param array{width: int, height: int, pixels: list<int>} $reference
     * @param array{width: int, height: int, pixels: list<int>} $image
     */
    private static function psnr(array $reference, array $image, bool $alpha): float
    {
        self::assertSame([$reference['width'], $reference['height']], [$image['width'], $image['height']]);
        $sum = 0.0;
        $samples = 0;
        $count = $reference['width'] * $reference['height'];
        for ($pixel = 0; $pixel < $count; ++$pixel) {
            $offset = $pixel * 4;
            if ($reference['pixels'][$offset + 3] === 255) {
                for ($channel = 0; $channel < 3; ++$channel) {
                    $difference = $reference['pixels'][$offset + $channel] - $image['pixels'][$offset + $channel];
                    $sum += $difference * $difference;
                    ++$samples;
                }
            }
            if ($alpha) {
                $difference = $reference['pixels'][$offset + 3] - $image['pixels'][$offset + 3];
                $sum += $difference * $difference;
                ++$samples;
            }
        }
        if ($samples === 0 || $sum === 0.0) {
            return INF;
        }

        return 10.0 * log10(255.0 * 255.0 / ($sum / $samples));
    }
}
