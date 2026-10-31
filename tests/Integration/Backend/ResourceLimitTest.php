<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Backend;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Backend\BackendFactory;
use Mfonte\SecureImageConverter\Backend\Internal\ProcessFailure;
use Mfonte\SecureImageConverter\Backend\VipsBackend;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Tests\Fixtures\BitWriter;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageBuilder;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The per-job limits with real programs: the derived address space is enough for a large image, and a process
 * stopped by its address space or CPU time limit is reported as resource_limit.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\AbstractBackend
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\ProcessFailure
 *
 * @group backends
 */
#[CoversClass(AbstractBackend::class)]
#[CoversClass(ProcessFailure::class)]
#[Group('backends')]
final class ResourceLimitTest extends BackendTestCase
{
    /**
     * Every output format a backend encodes, from a noisy 6 megapixel RGBA PNG (or, for gif2webp, an animated GIF of
     * 6 frames of 0.6 megapixels), with the address space the backend derives for the job.
     *
     * @dataProvider enabledBackends
     */
    #[DataProvider('enabledBackends')]
    public function testTheDerivedAddressSpaceIsEnoughForALargeImage(string $name): void
    {
        self::requireLevel(IsolationLevel::RLIMIT);
        $backend = self::backend($name);
        $capabilities = $backend->getCapabilities();
        if (in_array(ImageFormat::PNG, $capabilities->getInputFormats(), true)) {
            $input = $this->png(3000, 2000, true);
        } elseif (in_array(ImageFormat::GIF, $capabilities->getAnimatedInputFormats(), true)) {
            $input = $this->gif(1000, 600, 6);
        } else {
            self::markTestSkipped(sprintf('The %s backend decodes neither PNG nor animated GIF.', $name));
        }
        $failures = [];
        foreach ($capabilities->getOutputFormats() as $output) {
            $job = $this->job($input, $output)->withEffort(0);
            if ($capabilities->explainUnsupported($job) !== null) {
                continue;
            }
            $problem = self::check($backend, $job);
            if ($problem !== null) {
                $failures[] = sprintf('%s with %d MiB: %s', self::label($job), intdiv($backend->estimateMemoryBytes($job), 1048576), $problem);
            }
        }

        self::assertSame([], $failures);
    }

    /**
     * vips below the address space it needs: whether the loader, the thread pool or an allocation fails, the run is
     * a resource limit.
     */
    public function testAnAddressSpaceKillIsAResourceLimit(): void
    {
        self::requireLevel(IsolationLevel::RLIMIT);
        $backend = self::backend(VipsBackend::NAME);
        $job = $this->job($this->png(4000, 3000, false), ImageFormat::WEBP);
        $directory = $this->workingDirectory();
        self::assertTrue(symlink($job->getInputPath(), $directory->pathOf('input.png')));
        $environment = ['VIPS_CONCURRENCY' => '1', 'VIPS_BLOCK_UNTRUSTED' => '1', 'MALLOC_ARENA_MAX' => '2'];

        foreach ([96, 128, 160] as $mebibytes) {
            $result = $backend->createJobRunner($mebibytes * 1048576, $environment)
                ->execute($backend->commandLine($job), $directory, null, [$job->getInputPath()]);

            self::assertFalse($result->isSuccessful(), sprintf('vips converted 12 megapixels within %d MiB.', $mebibytes));
            self::assertInstanceOf(
                ResourceLimitException::class,
                ProcessFailure::exception($backend->getName(), $result),
                sprintf('%d MiB: exit code %s, signal %s: %s', $mebibytes, var_export($result->getExitCode(), true), var_export($result->getSignal(), true), $result->getStderr())
            );
        }
    }

    /**
     * A slow job (a noisy 6 megapixel image at the highest effort, to AVIF or lossless WEBP) under a CPU time limit
     * of one second fails with ResourceLimitException.
     *
     * @dataProvider enabledBackends
     */
    #[DataProvider('enabledBackends')]
    public function testACpuTimeKillIsAResourceLimit(string $name): void
    {
        self::requireLevel(IsolationLevel::RLIMIT);
        self::backend($name);
        $options = new ProcessOptions();
        $options = $options->withResourceLimits($options->getResourceLimits()->withCpuSeconds(1));
        $backend = BackendFactory::create($name, self::hostRunner($options), self::backendOptions());
        self::assertInstanceOf(AbstractBackend::class, $backend);
        $capabilities = $backend->getCapabilities();
        self::requireAvailable($capabilities);
        if (!in_array(ImageFormat::PNG, $capabilities->getInputFormats(), true)) {
            self::markTestSkipped(sprintf('The %s backend does not decode PNG.', $name));
        }
        $output = in_array(ImageFormat::AVIF, $capabilities->getOutputFormats(), true) ? ImageFormat::AVIF : ImageFormat::WEBP;
        $job = $this->job($this->png(3000, 2000, true), $output)->withEffort(9)->withQuality(100);
        if ($output === ImageFormat::WEBP && in_array(ImageFormat::WEBP, $capabilities->getLosslessFormats(), true)) {
            $job = $job->withLossless(true);
        }
        if ($capabilities->explainUnsupported($job) !== null) {
            self::markTestSkipped(sprintf('The %s backend encodes neither AVIF nor WEBP from PNG.', $name));
        }

        $this->expectException(ResourceLimitException::class);
        $backend->convert($job);
    }

    /**
     * An animated GIF in the working directory whose frames alternate between two patterns of black and white
     * pixels, so that no encoder can merge them.
     *
     * @param positive-int $width
     * @param positive-int $height
     *
     * @return array{0: string, 1: ImageInfo}
     */
    private function gif(int $width, int $height, int $frames): array
    {
        $data = ImageBuilder::gifScreen($width, $height) . "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        for ($frame = 0; $frame < $frames; ++$frame) {
            // A clear code before every pixel keeps the LZW codes three bits wide.
            $writer = new BitWriter();
            for ($pixel = $width * $height; $pixel > 0; --$pixel) {
                $writer->write(4, 3);
                $writer->write(($pixel + $frame) & 1, 3);
            }
            $writer->write(5, 3);
            $data .= "\x21\xF9\x04\x04\x0A\x00\x00\x00\x2C" . pack('vvvvC', 0, 0, $width, $height, 0) . "\x02"
                . ImageBuilder::gifSubBlocks($writer->getBytes());
        }
        $path = $this->workingDirectory()->pathOf(sprintf('large-%dx%d.gif', $width, $height));
        file_put_contents($path, $data . "\x3B");

        return [$path, new ImageInfo(ImageFormat::GIF, $width, $height, $frames, (int) filesize($path))];
    }

    /**
     * An RGBA PNG in the working directory: uniform, or of random pixels, which take encoders the longest and the
     * most memory.
     *
     * @param positive-int $width
     * @param positive-int $height
     *
     * @return array{0: string, 1: ImageInfo}
     */
    private function png(int $width, int $height, bool $noise): array
    {
        $rows = '';
        $row = "\0" . str_repeat("\x80\x40\x20\xFF", $width);
        for ($y = 0; $y < $height; ++$y) {
            $rows .= $noise ? "\0" . random_bytes($width * 4) : $row;
        }
        $data = $noise ? ImageBuilder::zlibStored($rows) : (string) gzcompress($rows, 1);
        $path = $this->workingDirectory()->pathOf(sprintf('large-%dx%d.png', $width, $height));
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . ImageBuilder::pngHeader($width, $height, 8, 6) . ImageBuilder::pngChunk('IDAT', $data) . ImageBuilder::pngChunk('IEND', ''));

        return [$path, new ImageInfo(ImageFormat::PNG, $width, $height, 1, (int) filesize($path))];
    }
}
