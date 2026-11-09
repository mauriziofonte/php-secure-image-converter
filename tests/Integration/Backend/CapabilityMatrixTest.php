<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Backend;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Backend\AbstractWorkerBackend;
use Mfonte\SecureImageConverter\Backend\AvifencBackend;
use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\BackendResolver;
use Mfonte\SecureImageConverter\Backend\CwebpBackend;
use Mfonte\SecureImageConverter\Backend\GdBackend;
use Mfonte\SecureImageConverter\Backend\Gif2webpBackend;
use Mfonte\SecureImageConverter\Backend\ImagickBackend;
use Mfonte\SecureImageConverter\Backend\VipsBackend;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Tests\Fixtures\ContentImages;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The capability matrix: every conversion a backend declares after probing works on the fixtures (format, size,
 * frame count, metadata stripped, TIFF page 0), every job outside its capabilities is refused, and the resolver
 * chooses a backend and fallback format that work, with all backends or with those SIC_BACKENDS enables.
 *
 * With SIC_EXPECTED_BACKENDS, the named backends must be available with at least their REFERENCE capabilities.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\AbstractBackend
 * @covers \Mfonte\SecureImageConverter\Backend\AbstractWorkerBackend
 * @covers \Mfonte\SecureImageConverter\Backend\AvifencBackend
 * @covers \Mfonte\SecureImageConverter\Backend\BackendResolver
 * @covers \Mfonte\SecureImageConverter\Backend\CwebpBackend
 * @covers \Mfonte\SecureImageConverter\Backend\GdBackend
 * @covers \Mfonte\SecureImageConverter\Backend\Gif2webpBackend
 * @covers \Mfonte\SecureImageConverter\Backend\ImagickBackend
 * @covers \Mfonte\SecureImageConverter\Backend\VipsBackend
 *
 * @group backends
 */
#[CoversClass(AbstractBackend::class)]
#[CoversClass(AbstractWorkerBackend::class)]
#[CoversClass(AvifencBackend::class)]
#[CoversClass(BackendResolver::class)]
#[CoversClass(CwebpBackend::class)]
#[CoversClass(GdBackend::class)]
#[CoversClass(Gif2webpBackend::class)]
#[CoversClass(ImagickBackend::class)]
#[CoversClass(VipsBackend::class)]
#[Group('backends')]
final class CapabilityMatrixTest extends BackendTestCase
{
    /**
     * Output sizes of the 40 x 30 stills per fit: contain keeps the aspect ratio, cover and fill do not.
     */
    private const STILL_SIZES = [
        ConversionRequest::FIT_CONTAIN => [20, 15],
        ConversionRequest::FIT_COVER => [20, 20],
        ConversionRequest::FIT_FILL => [30, 12],
    ];

    /**
     * @dataProvider enabledBackends
     */
    #[DataProvider('enabledBackends')]
    public function testTheBackendIsAvailableWithItsReferenceCapabilities(string $name): void
    {
        $capabilities = self::backend($name)->getCapabilities();
        $declared = $capabilities->toArray();

        self::assertNotSame([], $capabilities->getOutputFormats(), 'An available backend encodes something.');
        if (!self::isExpected($name)) {
            return;
        }
        foreach (self::REFERENCE[$name] as $key => $formats) {
            self::assertSame([], array_values(array_diff($formats, $declared[$key])), sprintf(
                'The %s backend lacks some of its reference %s; it declares %s (notes: %s).',
                $name,
                $key,
                json_encode($declared),
                implode('; ', $capabilities->getNotes())
            ));
        }
    }

    /**
     * Every input format to every output format, at the input size. The TIFF fixture has a 64 x 64 second page:
     * a 40 x 30 output proves that page 0 was converted.
     *
     * @dataProvider enabledBackends
     */
    #[DataProvider('enabledBackends')]
    public function testEveryDeclaredStillConversionWorks(string $name): void
    {
        $backend = self::backend($name);
        $capabilities = $backend->getCapabilities();
        $jobs = [];
        foreach ($capabilities->getInputFormats() as $input) {
            foreach ($capabilities->getOutputFormats() as $output) {
                $jobs[] = $this->job(self::still($input), $output);
            }
        }

        self::assertConversions($backend, $jobs);
    }

    /**
     * A noisy indexed PNG of 800 x 600 pixels (256 colours) to every output format: an encoder that codes it with
     * run lengths (ImageMagick's RLE8 BMP) would write more runs than BmpParser::MAX_RUNS, and the output would be
     * refused.
     *
     * @dataProvider enabledBackends
     */
    #[DataProvider('enabledBackends')]
    public function testANoisyIndexedImageConvertsToEveryOutput(string $name): void
    {
        $backend = self::backend($name);
        $capabilities = $backend->getCapabilities();
        if (!in_array(ImageFormat::PNG, $capabilities->getInputFormats(), true)) {
            self::markTestSkipped(sprintf('The %s backend does not decode PNG.', $name));
        }
        $path = $this->workingDirectory()->pathOf('indexed-noise.png');
        file_put_contents($path, ContentImages::indexedPng(ContentImages::PALETTE_NOISE, 800, 600));
        $info = self::inspect($path);
        self::assertNotNull($info);
        $jobs = [];
        foreach ($capabilities->getOutputFormats() as $output) {
            $jobs[] = $this->job([$path, $info], $output);
        }

        self::assertConversions($backend, $jobs);
    }

    /**
     * Each input variant the backend declares (ImageInfo::VARIANTS) converts to every output format; a variant of
     * an input format it reads but does not declare is refused before anything runs.
     *
     * @dataProvider enabledBackends
     */
    #[DataProvider('enabledBackends')]
    public function testEveryDeclaredInputVariantConverts(string $name): void
    {
        $backend = self::backend($name);
        $capabilities = $backend->getCapabilities();
        $jobs = [];
        $refused = [];
        foreach (ImageInfo::VARIANTS as $variant => $format) {
            if (!in_array($format, $capabilities->getInputFormats(), true)) {
                continue;
            }
            $fixture = self::fixtures()->variant($variant);
            self::assertNotNull($fixture, 'No fixture for the variant ' . $variant);
            foreach ($capabilities->getOutputFormats() as $output) {
                $job = $this->job($fixture, $output);
                if (in_array($variant, $capabilities->getInputVariants(), true)) {
                    $jobs[] = $job;
                } elseif ($capabilities->explainUnsupported($job) === null) {
                    $refused[] = $variant . ' -> ' . $output;
                }
            }
        }

        self::assertSame([], $refused, 'Undeclared variants must be refused.');
        self::assertConversions($backend, $jobs, false);
    }

    /**
     * Every fit to every output format, from the first input format.
     *
     * @dataProvider enabledBackends
     */
    #[DataProvider('enabledBackends')]
    public function testEveryDeclaredResizeWorks(string $name): void
    {
        $backend = self::backend($name);
        $capabilities = $backend->getCapabilities();
        if ($capabilities->getFits() === []) {
            self::assertSame([], $capabilities->getAnimatedFits());

            return;
        }
        $input = self::still($capabilities->getInputFormats()[0]);
        $jobs = [];
        foreach ($capabilities->getOutputFormats() as $output) {
            foreach ($capabilities->getFits() as $fit) {
                list($width, $height) = self::STILL_SIZES[$fit];
                $jobs[] = $this->job($input, $output, $width, $height)->withFit($fit);
            }
        }

        self::assertConversions($backend, $jobs);
    }

    /**
     * Each animated fixture to each output format, with every frame (ANIMATION_PRESERVE) and with the first frame
     * only (ANIMATION_FIRST_FRAME), at the input size and resized with every fit: a job the capabilities cover keeps
     * exactly the frames it asks for, any other job is refused without an output.
     *
     * @dataProvider enabledBackends
     */
    #[DataProvider('enabledBackends')]
    public function testAnimationPoliciesAreHonoured(string $name): void
    {
        $backend = self::backend($name);
        $capabilities = $backend->getCapabilities();
        $jobs = [];
        $refused = [];
        foreach ([ImageFormat::GIF, ImageFormat::WEBP, ImageFormat::PNG] as $format) {
            $animation = self::animation($format);
            self::assertNotNull($animation);
            $info = $animation[1];
            $sizes = [
                ConversionRequest::FIT_CONTAIN => [intdiv($info->getWidth(), 2), intdiv($info->getHeight(), 2)],
                ConversionRequest::FIT_COVER => [intdiv($info->getWidth(), 2), intdiv($info->getWidth(), 2)],
                ConversionRequest::FIT_FILL => [$info->getWidth() - 4, intdiv($info->getHeight(), 2) + 1],
            ];
            foreach ($capabilities->getOutputFormats() as $output) {
                foreach ([true, false] as $keep) {
                    $candidates = [$this->job($animation, $output)->withAnimation($keep)];
                    foreach ($sizes as $fit => $size) {
                        $candidates[] = $this->job($animation, $output, $size[0], $size[1])->withFit($fit)->withAnimation($keep);
                    }
                    foreach ($candidates as $job) {
                        if ($capabilities->explainUnsupported($job) === null) {
                            $jobs[] = $job;
                        } else {
                            $refused[] = $job;
                        }
                    }
                }
            }
        }

        self::assertConversions($backend, $jobs, false);
        $failures = [];
        foreach ($refused as $job) {
            try {
                $backend->convert($job);
                $failures[] = self::label($job) . ': converted, although its capabilities do not cover it';
            } catch (ConversionFailedException $exception) {
                if (file_exists($job->getOutputPath())) {
                    $failures[] = self::label($job) . ': refused, but left an output';
                }
            }
        }
        self::assertSame([], $failures, sprintf('The %s backend did not refuse every job outside its capabilities.', $name));
    }

    /**
     * A lossless job from the first input format to every format the backend encodes losslessly; a lossless WEBP
     * has a VP8L chunk and no lossy VP8 one. GD before PHP 8.1 has no IMG_WEBP_LOSSLESS and declares no lossless
     * output, so the test skips there; every other backend must declare one.
     *
     * @dataProvider enabledBackends
     */
    #[DataProvider('enabledBackends')]
    public function testLosslessOutputsAreLossless(string $name): void
    {
        $backend = self::backend($name);
        $capabilities = $backend->getCapabilities();
        if ($name === 'gd' && PHP_VERSION_ID < 80100) {
            self::assertSame([], $capabilities->getLosslessFormats());
            self::markTestSkipped('GD encodes WEBP losslessly from PHP 8.1 on.');
        }
        $input = self::still($capabilities->getInputFormats()[0]);
        $jobs = [];
        foreach ($capabilities->getLosslessFormats() as $output) {
            $jobs[] = $this->job($input, $output)->withLossless(true);
        }

        self::assertConversions($backend, $jobs);
        foreach ($jobs as $job) {
            if ($job->getOutputFormat() === ImageFormat::WEBP) {
                $chunks = self::webpChunks((string) file_get_contents($job->getOutputPath()));
                self::assertContains('VP8L', $chunks, self::label($job));
                self::assertNotContains('VP8 ', $chunks, self::label($job));
            }
        }
    }

    /**
     * With metadata stripped and the ICC profile kept (the defaults), the profile of the JPEG fixture reaches every
     * WEBP and AVIF output of a backend that declares it keeps ICC profiles, and its EXIF data does not.
     *
     * @dataProvider enabledBackends
     */
    #[DataProvider('enabledBackends')]
    public function testTheIccProfileIsKeptWhereDeclared(string $name): void
    {
        $backend = self::backend($name);
        $capabilities = $backend->getCapabilities();
        $outputs = array_values(array_intersect([ImageFormat::WEBP, ImageFormat::AVIF], $capabilities->getOutputFormats()));
        if (!$capabilities->keepsIccProfile() || !in_array(ImageFormat::JPEG, $capabilities->getInputFormats(), true) || $outputs === []) {
            $this->addToAssertionCount(1);

            return;
        }
        $failures = [];
        foreach ($outputs as $output) {
            $job = $this->job(self::still(ImageFormat::JPEG), $output);
            $problem = self::check($backend, $job);
            $info = self::inspect($job->getOutputPath());
            if ($problem === null && $info !== null && !$info->hasIccProfile()) {
                $problem = 'the ICC profile was dropped';
            }
            if ($problem !== null) {
                $failures[] = self::label($job) . ': ' . $problem;
            }
        }

        self::assertSame([], $failures);
    }

    /**
     * The resolver with every available enabled backend: for each still and animated fixture, asked for WEBP, AVIF
     * and its own format, it chooses a backend and a fallback format whose conversion works, or refuses the job only
     * when no backend covers any fallback format: with UnsupportedFormatException when none of them decodes the
     * input (a BMP with vips alone) or encodes any fallback format (a PNG to PNG with cwebp alone), else with
     * ConversionFailedException.
     */
    public function testTheResolverChoosesConversionsThatWork(): void
    {
        $backends = self::availableBackends();
        $resolver = new BackendResolver($backends);
        $failures = [];
        $fixtures = [];
        foreach (ImageFormat::all() as $format) {
            if ($format !== ImageFormat::SVG) {
                $fixtures[] = self::still($format);
            }
        }
        foreach ([ImageFormat::GIF, ImageFormat::WEBP, ImageFormat::PNG] as $format) {
            $animation = self::animation($format);
            self::assertNotNull($animation);
            $fixtures[] = $animation;
        }
        $resolved = 0;
        foreach ($fixtures as $fixture) {
            foreach (array_unique([ImageFormat::WEBP, ImageFormat::AVIF, $fixture[1]->getFormat()]) as $requested) {
                $job = $this->job($fixture, $requested);
                try {
                    $selection = $resolver->resolve($job);
                } catch (ConversionFailedException | UnsupportedFormatException $exception) {
                    $decodable = false;
                    $encodable = false;
                    foreach ($backends as $backend) {
                        $outputs = $backend->getCapabilities()->getOutputFormats();
                        $encodable = $encodable || array_intersect(BackendResolver::fallbackFormats($requested, $job->getInputFormat()), $outputs) !== [];
                    }
                    foreach (self::variants($job) as $variant) {
                        foreach ($backends as $backend) {
                            $decodable = $decodable || $backend->getCapabilities()->explainUndecodable($variant) === null;
                            if ($backend->getCapabilities()->explainUnsupported($variant) === null) {
                                $failures[] = sprintf('%s: refused, but %s can convert %s', self::label($job), $backend->getName(), self::label($variant));
                            }
                        }
                    }
                    $unsupported = !$decodable || !$encodable;
                    if (($exception instanceof UnsupportedFormatException) !== $unsupported) {
                        $failures[] = sprintf(
                            '%s: refused with %s, but %s',
                            self::label($job),
                            get_class($exception),
                            !$decodable ? 'no backend decodes it' : (!$encodable ? 'no backend encodes a fallback format' : 'a backend decodes it and one encodes a fallback format')
                        );
                    }
                    continue;
                }
                ++$resolved;
                $chosen = $selection->getJob();
                if (!in_array($chosen->getOutputFormat(), BackendResolver::fallbackFormats($requested, $job->getInputFormat()), true)) {
                    $failures[] = sprintf('%s: %s is not a fallback format', self::label($job), $chosen->getOutputFormat());
                }
                $problem = self::check($selection->getBackend(), $chosen);
                if ($problem !== null) {
                    $failures[] = sprintf('%s: %s chose %s: %s', self::label($job), $selection->getBackend()->getName(), self::label($chosen), $problem);
                }
            }
        }

        self::assertSame([], $failures);
        self::assertGreaterThan(0, $resolved, 'The available backends convert nothing.');
    }

    /**
     * Each input variant, asked for WEBP, AVIF and its own format: the resolver with every available enabled backend
     * chooses one that declares the variant and converts it, or, when none does, refuses the job with
     * UnsupportedFormatException (unsupported_format, 415), as does a resolver of each backend alone that reads the
     * format but not the variant.
     */
    public function testTheResolverSendsAnInputVariantOnlyToABackendThatDecodesIt(): void
    {
        $backends = self::availableBackends();
        $failures = [];
        $checked = 0;
        foreach (ImageInfo::VARIANTS as $variant => $format) {
            $fixture = self::fixtures()->variant($variant);
            self::assertNotNull($fixture, 'No fixture for the variant ' . $variant);
            $decoders = [];
            foreach ($backends as $backend) {
                if (in_array($variant, $backend->getCapabilities()->getInputVariants(), true)) {
                    $decoders[] = $backend->getName();
                }
            }
            foreach (array_unique([ImageFormat::WEBP, ImageFormat::AVIF, $format]) as $requested) {
                $job = $this->job($fixture, $requested);
                foreach (array_merge([$backends], array_map(static function (AbstractBackend $backend): array {
                    return [$backend];
                }, $backends)) as $set) {
                    $names = array_map(static function (AbstractBackend $backend): string {
                        return $backend->getName();
                    }, $set);
                    $label = sprintf('%s -> %s with %s', $variant, $requested, implode(',', $names));
                    $reads = array_filter($set, static function (AbstractBackend $backend) use ($format): bool {
                        return in_array($format, $backend->getCapabilities()->getInputFormats(), true);
                    });
                    if ($reads === []) {
                        continue;
                    }
                    ++$checked;
                    try {
                        $selection = (new BackendResolver($set))->resolve($job);
                    } catch (UnsupportedFormatException $exception) {
                        if (array_intersect($names, $decoders) !== []) {
                            $failures[] = $label . ': refused, but a backend decodes it';
                        }
                        continue;
                    } catch (ConversionFailedException $exception) {
                        $failures[] = $label . ': ' . $exception->getMessage();
                        continue;
                    }
                    $chosen = $selection->getBackend()->getName();
                    if (!in_array($chosen, $decoders, true)) {
                        $failures[] = sprintf('%s: %s was chosen, but it does not decode the variant', $label, $chosen);
                        continue;
                    }
                    $problem = self::check($selection->getBackend(), $selection->getJob());
                    if ($problem !== null) {
                        $failures[] = sprintf('%s: %s: %s', $label, $chosen, $problem);
                    }
                }
            }
        }

        if ($checked === 0) {
            self::markTestSkipped('No enabled backend reads JPEG or BMP.');
        }
        self::assertSame([], $failures);
    }

    /**
     * vips thumbnail picks its loader by content: a resize is declared only where VIPS_BLOCK_UNTRUSTED works, and then
     * an input whose content only an untrusted loader reads (a PPM passed as a validated PNG) is refused, resized or
     * not, while a PNG converts. The refusal is invalid_image: vips converts the probe PNG, so the input is the cause.
     */
    public function testVipsReadsNoInputWithAnUntrustedLoader(): void
    {
        $vips = self::backend(VipsBackend::NAME);
        $blocked = $vips->getFacts()['untrusted_blocked'] ?? null;
        self::assertContains($blocked, ['0', '1']);
        self::assertSame($blocked === '1' ? ConversionRequest::fitModes() : [], $vips->getCapabilities()->getFits());
        if ($blocked !== '1') {
            self::markTestSkipped('VIPS_BLOCK_UNTRUSTED has no effect on this libvips: vips declares no resize.');
        }
        $png = self::still(ImageFormat::PNG);
        $ppm = $this->workingDirectory()->pathOf('disguised.png');
        file_put_contents($ppm, "P6\n40 30\n255\n" . str_repeat("\x80\x40\x20", 40 * 30));
        $disguised = [$ppm, $png[1]];

        foreach ([[40, 30], [20, 15]] as $size) {
            try {
                $vips->convert($this->job($disguised, ImageFormat::WEBP, $size[0], $size[1]));
                self::fail(sprintf('vips read a PPM at %dx%d.', $size[0], $size[1]));
            } catch (InvalidImageException $exception) {
                self::assertStringContainsString('vips', $exception->getMessage());
            }
            self::assertNull(self::check($vips, $this->job($png, ImageFormat::WEBP, $size[0], $size[1])));
        }
    }

    /**
     * Converts each job and fails with the list of jobs that did not produce what they asked for.
     *
     * @param list<BackendJob> $jobs
     */
    private static function assertConversions(AbstractBackend $backend, array $jobs, bool $nonEmpty = true): void
    {
        if ($nonEmpty) {
            self::assertNotSame([], $jobs, 'The backend declares nothing to check.');
        }
        $failures = [];
        foreach ($jobs as $job) {
            $problem = self::check($backend, $job);
            if ($problem !== null) {
                $failures[] = self::label($job) . ': ' . $problem;
            }
        }

        self::assertSame([], $failures, sprintf(
            'The %s backend failed %d of its %d declared conversions.',
            $backend->getName(),
            count($failures),
            count($jobs)
        ));
    }

    /**
     * The jobs the resolver tries for a job: each fallback format, animated and then first frame only.
     *
     * @return list<BackendJob>
     */
    private static function variants(BackendJob $job): array
    {
        $variants = [];
        foreach (BackendResolver::fallbackFormats($job->getOutputFormat(), $job->getInputFormat()) as $format) {
            $variants[] = $job->withOutputFormat($format);
            if ($job->isAnimated()) {
                $variants[] = $job->withOutputFormat($format)->withAnimation(false);
            }
        }

        return $variants;
    }

    /**
     * The four-character codes of the top-level chunks of a WEBP file.
     *
     * @return list<string>
     */
    private static function webpChunks(string $data): array
    {
        $chunks = [];
        $offset = 12;
        while ($offset + 8 <= strlen($data)) {
            $chunks[] = substr($data, $offset, 4);
            $size = unpack('V', substr($data, $offset + 4, 4));
            self::assertIsArray($size);
            $length = $size[1];
            self::assertIsInt($length);
            $offset += 8 + $length + ($length & 1);
        }

        return $chunks;
    }
}
