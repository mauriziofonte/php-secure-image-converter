<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Security;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Backend\BackendFactory;
use Mfonte\SecureImageConverter\Backend\BackendResolver;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\ProcessingBudget;
use Mfonte\SecureImageConverter\Pipeline\SecureConverter;
use Mfonte\SecureImageConverter\Tests\Integration\Backend\BackendTestCase;
use Mfonte\SecureImageConverter\Validation\ImageInspector;

/**
 * Base class of the security tests: hostile files converted end to end by SecureConverter with the real programs, at
 * the strongest isolation level of the host, every raster input format enabled and the default limits
 * (SIC_BACKENDS, SIC_EXPECTED_BACKENDS and SIC_EXPECTED_ISOLATION_LEVEL work as for the backend tests).
 *
 * Every conversion must end in one of two ways, within CONVERSION_SECONDS and without leaving a file behind:
 *
 * - converted: the output is at the output path and is the image the result describes;
 * - refused with a code of REFUSALS, the 4xx codes of an input-caused problem. conversion_failed,
 *   output_verification_failed and internal_error (500) are failures: a hostile input must never pass for a fault of
 *   the library or the host.
 *
 * A valid corpus file (named valid-*) must be converted, when every backend is enabled and available (the reference
 * image): a refusal of a valid image is a defect, except the refusals of KNOWN_REFUSALS, each with its reason.
 *
 * MAX_SECONDS, the processing ceiling of the converter, is what the library guarantees: a job estimated above it is
 * refused with conversion_timeout before it runs, and a job that runs is stopped at its own budget. The corpus is
 * held to CONVERSION_SECONDS, far tighter (the slowest conversion takes about 5 seconds in the reference image), so
 * that a regression shows. No file may remain in the converter's working directory parent after a conversion, nor at
 * the output path after a refusal.
 */
abstract class SecurityTestCase extends BackendTestCase
{
    /**
     * The codes a hostile input may be refused with: unsupported, malformed, over a limit or over a budget.
     */
    public const REFUSALS = [
        ErrorCode::UNSUPPORTED_FORMAT,
        ErrorCode::INVALID_IMAGE,
        ErrorCode::ANIMATION_REJECTED,
        ErrorCode::FILE_TOO_LARGE,
        ErrorCode::IMAGE_TOO_LARGE,
        ErrorCode::CONVERSION_TIMEOUT,
        ErrorCode::RESOURCE_LIMIT,
    ];

    /**
     * Outcome of a conversion that succeeded.
     */
    public const CONVERTED = 'converted';

    /**
     * Valid corpus files that the reference image refuses, with the code and why; every other valid file converts.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const KNOWN_REFUSALS = [
        'bmp/valid-v5-icc-before-pixels.bmp' => [
            ErrorCode::UNSUPPORTED_FORMAT,
            'bit fields with the colour profile before the pixels: ImageMagick 6 reads bit fields but refuses that layout, GD reads that layout but not bit fields',
        ],
        'avif/valid-sequence.avif' => [
            ErrorCode::UNSUPPORTED_FORMAT,
            'an AVIF sequence: no backend decodes every frame of one, and a request that keeps the animation is not flattened',
        ],
        'avif/valid-video-track.avif' => [
            ErrorCode::UNSUPPORTED_FORMAT,
            'an AVIF sequence: no backend decodes every frame of one, and a request that keeps the animation is not flattened',
        ],
    ];

    /**
     * Processing ceiling of the converter under test: the default estimates admit a still image of 40 megapixels to
     * WEBP (about 295 seconds), and refuse AVIF at that size before it runs.
     */
    public const MAX_SECONDS = 300.0;

    /**
     * Longest time a corpus conversion may take, refusals and the check of a failure against the probe image included.
     */
    public const CONVERSION_SECONDS = 30.0;

    /**
     * @var SecureConverter|null
     */
    private static $converter;

    /**
     * Whether every backend of BackendFactory::NAMES is enabled and available.
     *
     * @var bool
     */
    private static $complete = false;

    /**
     * The working directory parent of the converter and its backends.
     *
     * @var WorkingDirectory|null
     */
    private static $parent;

    /**
     * Where the inputs and outputs of the current test go.
     *
     * @var WorkingDirectory|null
     */
    private $files;

    protected function set_up(): void
    {
        parent::set_up();
        $this->files = WorkingDirectory::create();
    }

    protected function tear_down(): void
    {
        if ($this->files !== null) {
            $this->files->remove();
            $this->files = null;
        }
        parent::tear_down();
    }

    public static function tear_down_after_class(): void
    {
        if (self::$parent !== null) {
            self::$parent->remove();
            self::$parent = null;
        }
        self::$converter = null;
        parent::tear_down_after_class();
    }

    /**
     * The converter under test, built and probed once per test class: every enabled backend that is available, every
     * input format but SVG, the default limits, a processing ceiling of MAX_SECONDS, and a private working directory
     * parent. Skips the test when no backend is available; fails it when an expected one is not.
     */
    protected static function converter(): SecureConverter
    {
        if (self::$converter !== null) {
            return self::$converter;
        }
        self::$parent = WorkingDirectory::create();
        $parent = self::$parent->getPath();
        $runner = self::hostRunner();
        $backends = [];
        foreach (BackendFactory::createAll($runner, self::backendOptions()->withWorkingDirectoryParent($parent), self::enabledNames()) as $backend) {
            self::assertInstanceOf(AbstractBackend::class, $backend);
            if ($backend->getCapabilities()->isAvailable()) {
                $backends[] = $backend;
            } else {
                self::requireAvailable($backend->getCapabilities(), $backend);
            }
        }
        if ($backends === []) {
            self::markTestSkipped('No enabled backend is available on this host.');
        }
        self::$complete = count($backends) === count(BackendFactory::NAMES);
        $options = (new ConverterOptions())
            ->withIsolation($runner->getLevel())
            ->withWorkingDirectoryParent($parent)
            ->withBudget((new ProcessingBudget())->withMaxSeconds(self::MAX_SECONDS));
        self::$converter = new SecureConverter(
            new ImageInspector(null, array_values(array_diff(ImageFormat::all(), [ImageFormat::SVG]))),
            new BackendResolver($backends),
            $options
        );

        return self::$converter;
    }

    /**
     * Converts the bytes of a hostile file and checks the outcome against the rules of this class.
     *
     * @return array{0: string, 1: float, 2: list<string>} the outcome (CONVERTED or the error code), the seconds the
     *                                                     conversion took, and what broke the rules (empty when none)
     */
    protected function convertHostile(SecureConverter $converter, string $bytes, ConversionRequest $request): array
    {
        self::assertNotNull($this->files);
        $input = $this->files->pathOf('input');
        $output = $this->files->pathOf('output');
        self::assertNotFalse(file_put_contents($input, $bytes));
        @unlink($output);
        $problems = [];
        $result = null;
        $start = microtime(true);

        try {
            $result = $converter->convert($input, $output, $request);
            $outcome = self::CONVERTED;
        } catch (ExceptionInterface $exception) {
            $outcome = $exception->getErrorCode();
            if (!in_array($outcome, self::REFUSALS, true)) {
                $problems[] = sprintf('refused with %s: %s', $outcome, $exception->getMessage());
            }
        } catch (\Throwable $throwable) {
            $outcome = get_class($throwable);
            $problems[] = sprintf('threw %s: %s at %s:%d', $outcome, $throwable->getMessage(), $throwable->getFile(), $throwable->getLine());
        }
        $seconds = microtime(true) - $start;

        if ($seconds > self::CONVERSION_SECONDS) {
            $problems[] = sprintf('took %.1f seconds', $seconds);
        }
        if ($result instanceof ConversionResult) {
            $problem = self::checkOutput($result, $output);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        } elseif (file_exists($output)) {
            $problems[] = 'a file was left at the output path after a refusal';
        }
        $left = self::$parent !== null ? array_values(array_diff((array) scandir(self::$parent->getPath()), ['.', '..'])) : [];
        if ($left !== []) {
            $problems[] = sprintf('%d file(s) left in the working directory parent', count($left));
            // Cleared, so that the next conversion is checked on its own.
            self::assertNotNull(self::$parent);
            self::$parent->remove();
            self::assertTrue(mkdir(self::$parent->getPath(), 0700));
        }
        @unlink($output);
        @unlink($input);

        return [$outcome, $seconds, $problems];
    }

    /**
     * Why the outcome of a corpus file is wrong, or null: a valid file (valid-*) must be converted, or refused as
     * KNOWN_REFUSALS says, when every backend is there; other files may be refused.
     */
    protected static function validProblem(string $path, string $outcome): ?string
    {
        if (!self::$complete || strpos(basename($path), 'valid-') !== 0) {
            return null;
        }
        $known = self::KNOWN_REFUSALS[$path] ?? null;
        $expected = $known !== null ? $known[0] : self::CONVERTED;

        return $outcome === $expected ? null : sprintf('a valid file ended as %s instead of %s', $outcome, $expected);
    }

    /**
     * What is wrong with a converted output, or null.
     */
    private static function checkOutput(ConversionResult $result, string $output): ?string
    {
        $info = self::inspect($output);
        if ($info === null) {
            return 'the output is not a valid image';
        }
        if ($info->getFormat() !== $result->getOutputFormat() || $info->getWidth() !== $result->getWidth() || $info->getHeight() !== $result->getHeight() || $info->getFrameCount() !== $result->getFrameCount()) {
            return sprintf(
                'the output is a %s of %d x %d (%d frames), the result says %s of %d x %d (%d frames)',
                $info->getFormat(),
                $info->getWidth(),
                $info->getHeight(),
                $info->getFrameCount(),
                $result->getOutputFormat(),
                $result->getWidth(),
                $result->getHeight(),
                $result->getFrameCount()
            );
        }

        return null;
    }
}
