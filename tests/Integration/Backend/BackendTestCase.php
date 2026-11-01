<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Backend;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Backend\BackendCapabilities;
use Mfonte\SecureImageConverter\Backend\BackendFactory;
use Mfonte\SecureImageConverter\Backend\BackendInterface;
use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Backend\Internal\ProbeImages;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\DetectionResult;
use Mfonte\SecureImageConverter\Isolation\IsolationDetector;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessRunner;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Base class of the tests that convert with the real programs, at the strongest isolation level the host provides.
 *
 * Environment variables:
 *
 * | Variable                     | Meaning                                                                        |
 * | ---------------------------- | ------------------------------------------------------------------------------ |
 * | SIC_BACKENDS                 | the backends to enable, such as "vips" or "cwebp,gif2webp" (default: all)      |
 * | SIC_EXPECTED_BACKENDS        | backends that must be available: a test of one that is not fails instead of   |
 * |                              | skipping, and its capabilities must include the REFERENCE ones                  |
 * | SIC_EXPECTED_ISOLATION_LEVEL | the level the host must provide (as for the isolation tests)                   |
 *
 * Capabilities are probed once per backend and test run, without the file cache. The images converted are those of
 * tests/Fixtures/backend: still.* is a 40 x 30 still of every raster format (the JPEG carries EXIF and an ICC
 * profile, the TIFF a 64 x 64 second page that must be ignored), anim.gif and anim.webp 4 frames of 40 x 30,
 * anim.png an APNG of 4 frames of 12 x 6, and variant-<name>.* a 40 x 30 still of each variant of
 * ImageInfo::VARIANTS (a CMYK JPEG and BMPs that not every decoder reads).
 */
abstract class BackendTestCase extends TestCase
{
    public const BACKENDS_VARIABLE = 'SIC_BACKENDS';

    public const EXPECTED_BACKENDS_VARIABLE = 'SIC_EXPECTED_BACKENDS';

    public const EXPECTED_LEVEL_VARIABLE = 'SIC_EXPECTED_ISOLATION_LEVEL';

    /**
     * What an expected backend must at least do with the packages of Ubuntu 24.04 (tests/docker/Dockerfile and the
     * CI job "backends"): the conversions it is shipped for.
     */
    public const REFERENCE = [
        'vips' => [
            'input_formats' => [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::WEBP, ImageFormat::TIFF, ImageFormat::AVIF],
            'output_formats' => [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::WEBP, ImageFormat::AVIF],
            'input_variants' => [ImageInfo::VARIANT_JPEG_CMYK],
            'animated_input_formats' => [ImageFormat::GIF, ImageFormat::WEBP],
            'animated_output_formats' => [ImageFormat::WEBP],
        ],
        'cwebp' => [
            'input_formats' => [ImageFormat::JPEG, ImageFormat::PNG],
            'output_formats' => [ImageFormat::WEBP],
        ],
        'gif2webp' => [
            'animated_input_formats' => [ImageFormat::GIF],
            'animated_output_formats' => [ImageFormat::WEBP],
        ],
        'avifenc' => [
            'input_formats' => [ImageFormat::JPEG, ImageFormat::PNG],
            'output_formats' => [ImageFormat::AVIF],
        ],
        'imagick' => [
            'input_formats' => [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::BMP, ImageFormat::WEBP, ImageFormat::TIFF],
            'output_formats' => [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::WEBP],
            'input_variants' => [
                ImageInfo::VARIANT_JPEG_CMYK,
                ImageInfo::VARIANT_BMP_BIT_FIELDS,
                ImageInfo::VARIANT_BMP_64_BIT,
                ImageInfo::VARIANT_BMP_V2_HEADER,
                ImageInfo::VARIANT_BMP_V3_HEADER,
            ],
        ],
        'gd' => [
            'input_formats' => [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF],
            'output_formats' => [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::WEBP],
            'input_variants' => [ImageInfo::VARIANT_JPEG_CMYK],
        ],
    ];

    /**
     * @var DetectionResult|null
     */
    private static $detection;

    /**
     * Backends created so far, by name: each probes once per run.
     *
     * @var array<string, AbstractBackend>
     */
    private static $backends = [];

    /**
     * @var ProbeImages|null
     */
    private static $fixtures;

    /**
     * @var ImageInspector|null
     */
    private static $inspector;

    /**
     * @var WorkingDirectory|null
     */
    private $directory;

    /**
     * Number of jobs created by this test, which names their outputs.
     *
     * @var int
     */
    private $jobCount = 0;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = WorkingDirectory::create();
    }

    protected function tear_down(): void
    {
        if ($this->directory !== null) {
            $this->directory->remove();
            $this->directory = null;
        }
        parent::tear_down();
    }

    /**
     * The enabled backends, for data providers.
     *
     * @return array<string, array{string}>
     */
    public static function enabledBackends(): array
    {
        $cases = [];
        foreach (self::enabledNames() as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * The backends SIC_BACKENDS enables, in order of preference.
     *
     * @return list<string>
     */
    protected static function enabledNames(): array
    {
        $list = getenv(self::BACKENDS_VARIABLE);

        return is_string($list) && trim($list) !== '' ? BackendFactory::parseNames($list) : BackendFactory::NAMES;
    }

    /**
     * Whether SIC_EXPECTED_BACKENDS names the backend.
     */
    protected static function isExpected(string $name): bool
    {
        $list = getenv(self::EXPECTED_BACKENDS_VARIABLE);

        return is_string($list) && trim($list) !== '' && in_array($name, BackendFactory::parseNames($list), true);
    }

    /**
     * What the detector finds on this host (probed once, without the file cache).
     */
    protected static function hostDetection(): DetectionResult
    {
        if (self::$detection === null) {
            self::$detection = (new IsolationDetector(new ProcessOptions(), null, 0))->detect();
        }

        return self::$detection;
    }

    /**
     * A runner at the detected level; fails when SIC_EXPECTED_ISOLATION_LEVEL asks for a stronger one.
     */
    protected static function hostRunner(?ProcessOptions $options = null): ProcessRunner
    {
        $level = self::hostDetection()->getLevel();
        $expected = getenv(self::EXPECTED_LEVEL_VARIABLE);
        if (is_string($expected) && $expected !== '') {
            self::assertTrue(IsolationLevel::isValid($expected), self::EXPECTED_LEVEL_VARIABLE . ' must name an isolation level.');
            self::assertTrue(IsolationLevel::isAtLeast($level, $expected), sprintf(
                'The host provides the %s isolation level, %s requires %s: %s',
                $level,
                self::EXPECTED_LEVEL_VARIABLE,
                $expected,
                json_encode(self::hostDetection()->getReasons())
            ));
        }

        return new ProcessRunner($level, $options);
    }

    /**
     * Skips the test when the host does not provide $level, or fails it when the environment expects the level.
     */
    protected static function requireLevel(string $level): void
    {
        $detected = self::hostDetection()->getLevel();
        if (IsolationLevel::isAtLeast($detected, $level)) {
            return;
        }
        $message = sprintf('The %s isolation level is not available on this host (detected: %s).', $level, $detected);
        $expected = getenv(self::EXPECTED_LEVEL_VARIABLE);
        if (is_string($expected) && IsolationLevel::isValid($expected) && IsolationLevel::isAtLeast($expected, $level)) {
            self::fail($message . ' ' . self::EXPECTED_LEVEL_VARIABLE . ' requires it.');
        }
        self::markTestSkipped($message);
    }

    /**
     * Options of the backends under test: no capability cache, so that every run probes.
     */
    protected static function backendOptions(): BackendOptions
    {
        return (new BackendOptions())->withCapabilityCache(false);
    }

    /**
     * The backend, created and probed once per run with the host runner; skips the test when the backend is not
     * enabled or not available, or fails it when SIC_EXPECTED_BACKENDS names it.
     */
    protected static function backend(string $name): AbstractBackend
    {
        if (!in_array($name, self::enabledNames(), true)) {
            self::markTestSkipped(sprintf('The %s backend is not enabled by %s.', $name, self::BACKENDS_VARIABLE));
        }
        if (!isset(self::$backends[$name])) {
            $backend = BackendFactory::create($name, self::hostRunner(), self::backendOptions());
            self::assertInstanceOf(AbstractBackend::class, $backend);
            self::$backends[$name] = $backend;
        }
        $backend = self::$backends[$name];
        self::requireAvailable($backend->getCapabilities(), $backend);

        return $backend;
    }

    /**
     * Skips the test, or fails it for an expected backend, when the capabilities say unavailable; the failure of an
     * expected backend shows how its discovery failed (see diagnosis()).
     */
    protected static function requireAvailable(BackendCapabilities $capabilities, ?AbstractBackend $backend = null): void
    {
        if ($capabilities->isAvailable()) {
            return;
        }
        $message = sprintf(
            'The %s backend is unavailable on this host: %s.',
            $capabilities->getBackend(),
            (string) $capabilities->getUnavailableReason()
        );
        if (self::isExpected($capabilities->getBackend())) {
            self::fail($message . ' ' . self::EXPECTED_BACKENDS_VARIABLE . ' requires it.' . ($backend !== null ? self::diagnosis($backend) : ''));
        }
        self::markTestSkipped($message);
    }

    /**
     * How the discovery of a backend fails, run again: the exit code or signal of its last command, and the end of its
     * standard error and output (the capabilities keep only a reason without host details).
     */
    protected static function diagnosis(AbstractBackend $backend): string
    {
        try {
            $facts = $backend->discoverFacts();
        } catch (\Exception $exception) {
            return sprintf(' Discovery threw %s: %s', get_class($exception), $exception->getMessage());
        }
        $result = $backend->getLastDiscoveryResult();
        if ($result === null) {
            return ' Discovery ran no command.';
        }

        return sprintf(
            "\nDiscovery again: %s\nLast command: exit code %s, signal %s, %s level%s\nstderr:\n%s\nstdout:\n%s",
            (string) ($facts[AbstractBackend::FACT_UNAVAILABLE] ?? 'available'),
            var_export($result->getExitCode(), true),
            var_export($result->getSignal(), true),
            $result->getIsolationLevel(),
            $result->isTimedOut() ? ', timed out' : '',
            substr($result->getStderr(), -4000),
            substr($result->getStdout(), -2000)
        );
    }

    /**
     * The available enabled backends, in order of preference; skips the test when there is none.
     *
     * @return list<AbstractBackend>
     */
    protected static function availableBackends(): array
    {
        $backends = [];
        foreach (self::enabledNames() as $name) {
            if (!isset(self::$backends[$name])) {
                $backend = BackendFactory::create($name, self::hostRunner(), self::backendOptions());
                self::assertInstanceOf(AbstractBackend::class, $backend);
                self::$backends[$name] = $backend;
            }
            $capabilities = self::$backends[$name]->getCapabilities();
            if ($capabilities->isAvailable()) {
                $backends[] = self::$backends[$name];
            } elseif (self::isExpected($name)) {
                self::fail(sprintf('The %s backend is unavailable: %s.', $name, (string) $capabilities->getUnavailableReason()) . self::diagnosis(self::$backends[$name]));
            }
        }
        if ($backends === []) {
            self::markTestSkipped('No enabled backend is available on this host.');
        }

        return $backends;
    }

    /**
     * The fixture images.
     */
    protected static function fixtures(): ProbeImages
    {
        if (self::$fixtures === null) {
            self::$fixtures = new ProbeImages(\dirname(__DIR__, 2) . '/Fixtures/backend');
        }

        return self::$fixtures;
    }

    /**
     * Path and header of the still fixture of a format.
     *
     * @return array{0: string, 1: ImageInfo}
     */
    protected static function still(string $format): array
    {
        $still = self::fixtures()->still($format);
        self::assertNotNull($still, 'No still fixture for ' . $format);

        return $still;
    }

    /**
     * Path and header of the animated fixture of a format, or null when there is none.
     *
     * @return array{0: string, 1: ImageInfo}|null
     */
    protected static function animation(string $format): ?array
    {
        return self::fixtures()->animation($format);
    }

    protected function workingDirectory(): WorkingDirectory
    {
        self::assertNotNull($this->directory);

        return $this->directory;
    }

    /**
     * A job converting a fixture to the given format and size (the input size by default), written to a new file
     * of the working directory.
     *
     * @param array{0: string, 1: ImageInfo} $fixture
     */
    protected function job(array $fixture, string $outputFormat, ?int $width = null, ?int $height = null): BackendJob
    {
        ++$this->jobCount;

        return new BackendJob(
            $fixture[0],
            $fixture[1],
            $this->workingDirectory()->pathOf(sprintf('out-%d.%s', $this->jobCount, ImageFormat::extension($outputFormat))),
            $outputFormat,
            $width !== null ? $width : $fixture[1]->getWidth(),
            $height !== null ? $height : $fixture[1]->getHeight()
        );
    }

    /**
     * What an output inspector (ImageInspector::forOutput(), as OutputCheck uses) reads from an output, or null when it
     * is not a valid image. No limit applies: a large uncompressed output is as valid as a small one.
     */
    protected static function inspect(string $path): ?ImageInfo
    {
        if (self::$inspector === null) {
            self::$inspector = ImageInspector::forOutput(ImageFormat::all(), Capabilities::LIMIT_VALUE_MAX);
        }
        try {
            return self::$inspector->inspect($path);
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * Converts a job and checks the output: its format, size and frame count, and that no EXIF survived when the
     * job strips metadata. Returns a description of what went wrong, or null.
     */
    protected static function check(BackendInterface $backend, BackendJob $job): ?string
    {
        try {
            $backend->convert($job);
        } catch (\Exception $exception) {
            return sprintf('%s: %s', get_class($exception), $exception->getMessage());
        }
        $info = self::inspect($job->getOutputPath());
        if ($info === null) {
            return 'the output is not a valid image';
        }
        $problems = [];
        if ($info->getFormat() !== $job->getOutputFormat()) {
            $problems[] = 'the output is ' . $info->getFormat();
        }
        if ($info->getWidth() !== $job->getWidth() || $info->getHeight() !== $job->getHeight()) {
            $problems[] = sprintf('the output is %dx%d, not %dx%d', $info->getWidth(), $info->getHeight(), $job->getWidth(), $job->getHeight());
        }
        if ($info->getFrameCount() !== $job->getFrameCount()) {
            $problems[] = sprintf('the output has %d frames, not %d', $info->getFrameCount(), $job->getFrameCount());
        }
        if ($job->shouldStripMetadata() && $info->hasExif()) {
            $problems[] = 'the output kept the EXIF data';
        }

        return $problems === [] ? null : implode(', ', $problems);
    }

    /**
     * A label for a job in failure lists, such as "gif (animated) -> webp 20x15 cover".
     */
    protected static function label(BackendJob $job): string
    {
        $input = $job->getInputFormat();
        if ($job->getInput()->isAnimated()) {
            $input .= $job->isAnimated() ? ' (animated)' : ' (first frame)';
        }
        $label = sprintf('%s -> %s', $input, $job->getOutputFormat());
        if ($job->isResized()) {
            $label .= sprintf(' %dx%d %s', $job->getWidth(), $job->getHeight(), $job->getFit());
        }

        return $label . ($job->needsLosslessMode() ? ' lossless' : '');
    }
}
