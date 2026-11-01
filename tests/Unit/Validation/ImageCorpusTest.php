<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Validation;

use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Tests\Fixtures\CpuClock;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageBuilder;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageCorpus;
use Mfonte\SecureImageConverter\Validation\FormatSniffer;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use Mfonte\SecureImageConverter\Validation\Limits;
use Mfonte\SecureImageConverter\Validation\Parser\AvifParser;
use Mfonte\SecureImageConverter\Validation\Parser\BmpParser;
use Mfonte\SecureImageConverter\Validation\Parser\ByteReader;
use Mfonte\SecureImageConverter\Validation\Parser\GifParser;
use Mfonte\SecureImageConverter\Validation\Parser\JpegParser;
use Mfonte\SecureImageConverter\Validation\Parser\PngParser;
use Mfonte\SecureImageConverter\Validation\Parser\TiffParser;
use Mfonte\SecureImageConverter\Validation\Parser\WebpParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Runs the input validation corpus (see ImageCorpus): every valid sample is accepted with its dimensions, frame count
 * and metadata flags, and every bomb, spoofed, truncated or unsupported file is refused with its error code, in under
 * 50 ms of CPU time (and 500 ms of wall-clock time; the best of three runs, see CpuClock). The files are read through CountingStreamWrapper, which proves how few bytes the inspector reads: a bomb is
 * refused from its header, whatever the size its pixels would take once decoded.
 *
 * @covers \Mfonte\SecureImageConverter\Validation\ImageInspector
 * @covers \Mfonte\SecureImageConverter\Validation\Parser\AvifParser
 * @covers \Mfonte\SecureImageConverter\Validation\Parser\BmpParser
 * @covers \Mfonte\SecureImageConverter\Validation\Parser\ByteReader
 * @covers \Mfonte\SecureImageConverter\Validation\Parser\GifParser
 * @covers \Mfonte\SecureImageConverter\Validation\Parser\JpegParser
 * @covers \Mfonte\SecureImageConverter\Validation\Parser\PngParser
 * @covers \Mfonte\SecureImageConverter\Validation\Parser\TiffParser
 * @covers \Mfonte\SecureImageConverter\Validation\Parser\WebpParser
 */
#[CoversClass(ImageInspector::class)]
#[CoversClass(AvifParser::class)]
#[CoversClass(BmpParser::class)]
#[CoversClass(ByteReader::class)]
#[CoversClass(GifParser::class)]
#[CoversClass(JpegParser::class)]
#[CoversClass(PngParser::class)]
#[CoversClass(TiffParser::class)]
#[CoversClass(WebpParser::class)]
final class ImageCorpusTest extends TestCase
{
    /**
     * Largest CPU time one inspection may take: the time the inspector itself spends, which other processes on a
     * busy machine do not add to.
     */
    public const MAX_CPU_SECONDS = 0.05;

    /**
     * Largest wall-clock time one inspection may take: a loose bound, which a wait (a read that blocks) fails.
     */
    public const MAX_WALL_SECONDS = 0.5;

    /**
     * Largest size of a committed corpus file.
     */
    private const MAX_FILE_SIZE = 100 * 1024;

    /**
     * @return array<string, array{string, string, array<string, bool|int|string>}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (ImageCorpus::cases() as $path => $case) {
            $cases[$path] = [$path, $case['bytes'], $case['expect']];
        }

        return $cases;
    }

    public function testCommittedCorpusIsTheGeneratorOutput(): void
    {
        $expected = [];
        foreach (ImageCorpus::cases() as $path => $case) {
            if ($case['committed']) {
                $expected[] = $path;
                $file = ImageCorpus::directory() . '/' . $path;
                self::assertFileExists($file, 'run: php tests/Fixtures/generate-corpus.php');
                self::assertSame($case['bytes'], file_get_contents($file), $path . ' differs from the generator output');
                self::assertLessThan(self::MAX_FILE_SIZE, strlen($case['bytes']), $path . ' is too large to commit');
            }
        }

        $committed = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ImageCorpus::directory(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $committed[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(ImageCorpus::directory()) + 1));
            }
        }
        sort($expected);
        sort($committed);
        self::assertSame($expected, $committed, 'the corpus directory holds exactly the committed cases');
    }

    public function testCorpusCoversEveryRasterFormatAndEveryKindOfCase(): void
    {
        $kinds = [];
        foreach (ImageCorpus::cases() as $path => $case) {
            $kinds[dirname($path)][explode('-', pathinfo($path, PATHINFO_FILENAME))[0]] = true;
        }

        foreach ([ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::BMP, ImageFormat::WEBP, ImageFormat::AVIF, ImageFormat::TIFF] as $format) {
            self::assertArrayHasKey($format, $kinds);
            foreach (['valid', 'bomb', 'truncated'] as $kind) {
                self::assertArrayHasKey($kind, $kinds[$format], $format . ' has no ' . $kind . ' case');
            }
        }
        self::assertArrayHasKey('svg', $kinds);
    }

    /**
     * Every case with every format enabled.
     *
     * @param array<string, bool|int|string> $expect
     *
     * @dataProvider cases
     */
    #[DataProvider('cases')]
    public function testCaseWithEveryFormatEnabled(string $path, string $bytes, array $expect): void
    {
        $this->assertOutcome(new ImageInspector(null, ImageFormat::all()), $path, $bytes, $expect);
    }

    /**
     * Every case with the default formats: WEBP, AVIF, TIFF and SVG are refused from their content, whatever the
     * file is named; the other cases have the same outcome.
     *
     * @param array<string, bool|int|string> $expect
     *
     * @dataProvider cases
     */
    #[DataProvider('cases')]
    public function testCaseWithTheDefaultFormats(string $path, string $bytes, array $expect): void
    {
        $format = (new FormatSniffer())->detect((string) substr($bytes, 0, FormatSniffer::HEAD_LENGTH));
        if ($format !== null && !in_array($format, ImageInspector::DEFAULT_INPUT_FORMATS, true)) {
            $expect = ['error' => ErrorCode::UNSUPPORTED_FORMAT, 'max_bytes_read' => ByteReader::BUFFER_SIZE];
        }

        $this->assertOutcome(new ImageInspector(), $path, $bytes, $expect);
    }

    /**
     * The committed files give the same outcome through a path as through a stream.
     */
    public function testCommittedFilesInspectedByPath(): void
    {
        $inspector = new ImageInspector(null, ImageFormat::all());
        foreach (ImageCorpus::cases() as $path => $case) {
            if (!$case['committed']) {
                continue;
            }
            $file = ImageCorpus::directory() . '/' . $path;
            try {
                $outcome = $inspector->inspect($file)->toArray();
            } catch (ExceptionInterface $exception) {
                $outcome = $exception->getErrorCode();
                self::assertStringNotContainsString(basename($path), $exception->getMessage());
            }
            try {
                $expected = $inspector->inspectStream(CountingStreamWrapper::open($case['bytes']))->toArray();
            } catch (ExceptionInterface $exception) {
                $expected = $exception->getErrorCode();
            }
            self::assertSame($expected, $outcome, $path);
        }
    }

    /**
     * The decompression bomb is a valid 20000 x 20000 PNG: with limits that allow it, the inspector accepts it after
     * reading a few kilobytes, without inflating its 50 MB of pixel data.
     */
    public function testDecompressionBombIsAcceptedUnreadWhenItsSizeIsAllowed(): void
    {
        $bomb = ImageBuilder::pngDecompressionBomb();
        $limits = (new Limits())->withMaxWidth(20000)->withMaxHeight(20000)->withMaxMegapixels(400);

        $info = (new ImageInspector($limits))->inspectStream(CountingStreamWrapper::open($bomb));

        self::assertSame([20000, 20000], [$info->getWidth(), $info->getHeight()]);
        self::assertLessThan(strlen($bomb) / 2, CountingStreamWrapper::$bytesRead);
    }

    /**
     * @param array<string, bool|int|string> $expect
     */
    private function assertOutcome(ImageInspector $inspector, string $path, string $bytes, array $expect): void
    {
        $outcome = null;
        list($cpu, $wall) = CpuClock::measure(static function () use ($inspector, $bytes, &$outcome): void {
            $stream = CountingStreamWrapper::open($bytes);
            try {
                $outcome = $inspector->inspectStream($stream);
            } catch (ExceptionInterface $exception) {
                $outcome = $exception;
            } finally {
                fclose($stream);
            }
        }, self::MAX_CPU_SECONDS, self::MAX_WALL_SECONDS);

        self::assertLessThan(self::MAX_CPU_SECONDS, $cpu, sprintf('%s took %.1f ms of CPU time', $path, $cpu * 1000));
        self::assertLessThan(self::MAX_WALL_SECONDS, $wall, sprintf('%s took %.1f ms', $path, $wall * 1000));
        if (isset($expect['max_bytes_read'])) {
            self::assertLessThanOrEqual($expect['max_bytes_read'], CountingStreamWrapper::$bytesRead, $path . ' read too much');
            unset($expect['max_bytes_read']);
        }

        if (isset($expect['error'])) {
            self::assertInstanceOf(ExceptionInterface::class, $outcome, $path . ' must be refused');
            self::assertSame($expect['error'], $outcome->getErrorCode(), $path . ': ' . $outcome->getMessage());
            self::assertMatchesRegularExpression('/\A[\x20-\x7E]+\z/', $outcome->getMessage(), 'messages are printable ASCII');

            return;
        }

        if ($outcome instanceof ExceptionInterface) {
            self::fail($path . ' must be accepted, refused with ' . $outcome->getErrorCode() . ': ' . $outcome->getMessage());
        }
        self::assertInstanceOf(ImageInfo::class, $outcome);
        self::assertSame(strlen($bytes), $outcome->getByteSize());
        $actual = array_intersect_key($outcome->toArray(), $expect);
        ksort($expect);
        ksort($actual);
        self::assertSame($expect, $actual, $path);
    }
}
