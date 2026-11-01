<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Validation;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageBuilder;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use Mfonte\SecureImageConverter\Validation\Limits;
use Mfonte\SecureImageConverter\Validation\Parser\GifParser;
use Mfonte\SecureImageConverter\Validation\Parser\PngParser;
use Mfonte\SecureImageConverter\Validation\Parser\WebpParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The inspector itself: allowlist, limits order, paths and streams. What each format parser accepts and refuses is
 * covered by ImageCorpusTest.
 *
 * @covers \Mfonte\SecureImageConverter\Validation\ImageInspector
 */
#[CoversClass(ImageInspector::class)]
final class ImageInspectorTest extends TestCase
{
    /**
     * @var list<string>
     */
    private $temporaryPaths = [];

    protected function tear_down(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_dir($path) && !is_link($path)) {
                rmdir($path);
            } elseif (is_link($path) || file_exists($path)) {
                unlink($path);
            }
        }
        $this->temporaryPaths = [];
        parent::tear_down();
    }

    public function testDefaultsEnableJpegPngGifAndBmpWithTheDefaultLimits(): void
    {
        $inspector = new ImageInspector();

        self::assertSame([ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::BMP], $inspector->getInputFormats());
        self::assertSame(ImageInspector::DEFAULT_INPUT_FORMATS, $inspector->getInputFormats());
        self::assertEquals(new Limits(), $inspector->getLimits());
    }

    public function testInputFormatsAreListedInCanonicalOrderWithoutSvg(): void
    {
        $limits = (new Limits())->withMaxWidth(100);
        $inspector = new ImageInspector($limits, [ImageFormat::SVG, ImageFormat::TIFF, ImageFormat::WEBP, ImageFormat::JPEG]);

        self::assertSame([ImageFormat::JPEG, ImageFormat::WEBP, ImageFormat::TIFF], $inspector->getInputFormats());
        self::assertSame($limits, $inspector->getLimits());
        self::assertSame([], (new ImageInspector(null, [ImageFormat::SVG]))->getInputFormats());
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function invalidFormatLists(): array
    {
        return [
            'unknown format' => [[ImageFormat::PNG, 'jpg']],
            'mime type' => [['image/png']],
            'upper case' => [['PNG']],
            'integer' => [[1]],
            'null' => [[null]],
            'duplicate' => [[ImageFormat::PNG, ImageFormat::GIF, ImageFormat::PNG]],
        ];
    }

    /**
     * @param array<mixed> $formats
     *
     * @dataProvider invalidFormatLists
     */
    #[DataProvider('invalidFormatLists')]
    public function testConstructorRejectsAnInvalidFormatList(array $formats): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore argument.type (the list is invalid on purpose)
        new ImageInspector(null, $formats);
    }

    public function testAFormatThatIsNotEnabledIsRefusedFromItsContent(): void
    {
        $exception = self::refusal(new ImageInspector(), ImageBuilder::webpLossless(8, 8));

        self::assertInstanceOf(UnsupportedFormatException::class, $exception);
        self::assertSame(ErrorCode::UNSUPPORTED_FORMAT, $exception->getErrorCode());
        self::assertSame('The input format webp is not enabled.', $exception->getMessage());
    }

    public function testSvgIsRefusedEvenWhenEnabled(): void
    {
        $exception = self::refusal(new ImageInspector(null, ImageFormat::all()), ImageBuilder::svg());

        self::assertInstanceOf(UnsupportedFormatException::class, $exception);
        self::assertStringContainsString('never rasterises SVG', $exception->getMessage());
    }

    public function testAnEmptyFormatListRefusesEveryInput(): void
    {
        $exception = self::refusal(new ImageInspector(null, []), ImageBuilder::png(1, 1));

        self::assertSame(ErrorCode::UNSUPPORTED_FORMAT, $exception->getErrorCode());
    }

    public function testUnidentifiedContentIsRefusedWithoutEchoingIt(): void
    {
        $exception = self::refusal(new ImageInspector(), "SECRET-CONTENT\x00\xFF");

        self::assertInstanceOf(UnsupportedFormatException::class, $exception);
        self::assertSame('The input format could not be identified from its content.', $exception->getMessage());
    }

    public function testTheFileSizeIsCheckedBeforeAnyByteIsRead(): void
    {
        $inspector = new ImageInspector();
        $stream = CountingStreamWrapper::open(ImageBuilder::png(1, 1), Limits::DEFAULT_MAX_BYTES + 1);

        try {
            $inspector->inspectStream($stream);
            self::fail('A file above max_bytes must be refused.');
        } catch (LimitExceededException $exception) {
            self::assertSame(ErrorCode::FILE_TOO_LARGE, $exception->getErrorCode());
            self::assertSame('max_bytes', $exception->getLimit());
            self::assertSame(Limits::DEFAULT_MAX_BYTES + 1, $exception->getActual());
        }
        self::assertSame(0, CountingStreamWrapper::$bytesRead);
    }

    public function testTheFileSizeLimitIsInclusive(): void
    {
        $png = ImageBuilder::png(2, 2);

        $info = (new ImageInspector((new Limits())->withMaxBytes(strlen($png))))->inspectStream(self::stream($png));
        self::assertSame(strlen($png), $info->getByteSize());

        $exception = self::refusal(new ImageInspector((new Limits())->withMaxBytes(strlen($png) - 1)), $png);
        self::assertSame(ErrorCode::FILE_TOO_LARGE, $exception->getErrorCode());
    }

    public function testDimensionLimitsComeFromTheGivenLimits(): void
    {
        $png = ImageBuilder::png(16, 8);

        self::assertSame(16, (new ImageInspector((new Limits())->withMaxWidth(16)))->inspectStream(self::stream($png))->getWidth());

        $exception = self::refusal(new ImageInspector((new Limits())->withMaxWidth(15)), $png);
        self::assertInstanceOf(LimitExceededException::class, $exception);
        self::assertSame(ErrorCode::IMAGE_TOO_LARGE, $exception->getErrorCode());
        self::assertSame('max_width', $exception->getLimit());
    }

    public function testFrameLimitsComeFromTheGivenLimits(): void
    {
        $gif = ImageBuilder::gif(4, 4, 3);

        self::assertSame(3, (new ImageInspector((new Limits())->withMaxFrames(3)))->inspectStream(self::stream($gif))->getFrameCount());

        $exception = self::refusal(new ImageInspector((new Limits())->withMaxFrames(2)), $gif);
        self::assertInstanceOf(LimitExceededException::class, $exception);
        self::assertSame('max_frames', $exception->getLimit());
    }

    public function testInspectReadsALocalFile(): void
    {
        $path = $this->temporaryFile(ImageBuilder::jpeg(10, 4));

        $info = (new ImageInspector())->inspect($path);

        self::assertSame(ImageFormat::JPEG, $info->getFormat());
        self::assertSame([10, 4], [$info->getWidth(), $info->getHeight()]);
        self::assertSame(filesize($path), $info->getByteSize());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedPaths(): array
    {
        return [
            'empty' => [''],
            'NUL byte' => ["image.png\0.jpg"],
            'php wrapper' => ['php://memory'],
            'phar wrapper' => ['phar://archive.phar/image.png'],
            'data URL' => ['data:image/png;base64,iVBORw0KGgo='],
            'file URL' => ['file:///etc/hosts'],
            'https URL' => ['https://example.com/image.png'],
            'compression wrapper' => ['compress.zlib://image.png.gz'],
            'glob wrapper' => ['glob://*.png'],
        ];
    }

    /**
     * @dataProvider refusedPaths
     */
    #[DataProvider('refusedPaths')]
    public function testInspectRefusesUrlsAndStreamWrappers(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The input path must be a local file path, not a URL or stream wrapper.');

        (new ImageInspector())->inspect($path);
    }

    public function testInspectRefusesAMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The input path is not a regular file.');

        (new ImageInspector())->inspect(sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(8)) . '.png');
    }

    public function testInspectRefusesADirectory(): void
    {
        $directory = sys_get_temp_dir() . '/inspector-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $this->temporaryPaths[] = $directory;

        $this->expectException(InvalidArgumentException::class);

        (new ImageInspector())->inspect($directory);
    }

    public function testInspectRefusesASymbolicLinkToAnImage(): void
    {
        $target = $this->temporaryFile(ImageBuilder::jpeg(10, 4));
        $link = sys_get_temp_dir() . '/inspector-link-' . bin2hex(random_bytes(8)) . '.jpg';
        if (!function_exists('symlink') || !@symlink($target, $link)) {
            self::markTestSkipped('Symbolic links cannot be created here.');
        }
        $this->temporaryPaths[] = $link;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The input path is not a regular file.');

        (new ImageInspector())->inspect($link);
    }

    public function testInspectRefusesAFifoWithoutBlocking(): void
    {
        $fifo = sys_get_temp_dir() . '/inspector-fifo-' . bin2hex(random_bytes(8));
        if (!function_exists('posix_mkfifo') || !posix_mkfifo($fifo, 0600)) {
            self::markTestSkipped('FIFOs cannot be created here.');
        }
        $this->temporaryPaths[] = $fifo;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The input path is not a regular file.');

        (new ImageInspector())->inspect($fifo);
    }

    public function testInspectRefusesADevice(): void
    {
        if (!file_exists('/dev/zero')) {
            self::markTestSkipped('/dev/zero does not exist here.');
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The input path is not a regular file.');

        (new ImageInspector())->inspect('/dev/zero');
    }

    public function testRefusalsQuoteNeitherThePathNorTheContent(): void
    {
        $files = [
            ErrorCode::INVALID_IMAGE => ImageBuilder::png(4, 4, 8, 2, ImageBuilder::pngChunk('SECR', 'ET-CONTENT')),
            ErrorCode::UNSUPPORTED_FORMAT => 'SECRET-CONTENT',
            ErrorCode::IMAGE_TOO_LARGE => ImageBuilder::gifScreen(10000, 1) . ImageBuilder::gifComment('SECRET-CONTENT') . "\x3B",
        ];

        foreach ($files as $errorCode => $bytes) {
            $path = $this->temporaryFile($bytes, 'SECRET-NAME');
            try {
                (new ImageInspector())->inspect($path);
                self::fail('The file must be refused.');
            } catch (ExceptionInterface $exception) {
                self::assertSame($errorCode, $exception->getErrorCode());
                self::assertStringNotContainsString('SECRET', $exception->getMessage());
                self::assertStringNotContainsString(basename($path), $exception->getMessage());
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function variants(): array
    {
        return [
            'grey JPEG' => [ImageBuilder::jpeg(8, 8, 1), []],
            'colour JPEG' => [ImageBuilder::jpeg(8, 8, 3), []],
            'CMYK JPEG' => [ImageBuilder::jpeg(8, 8, 4), [ImageInfo::VARIANT_JPEG_CMYK]],
            '24-bit BMP' => [ImageBuilder::bmp(4, 4), []],
            'run-length encoded BMP' => [ImageBuilder::bmp(4, 4, 8, 1), []],
            'core header BMP' => [ImageBuilder::bmpCore(4, 4), []],
            'V5 header 24-bit BMP' => [ImageBuilder::bmp(4, 4, 24, 0, 124), []],
            '16-bit bit-field BMP' => [ImageBuilder::bmp(4, 4, 16, 3), [ImageInfo::VARIANT_BMP_BIT_FIELDS]],
            '32-bit bit-field V5 BMP' => [ImageBuilder::bmp(4, 4, 32, 3, 124), [ImageInfo::VARIANT_BMP_BIT_FIELDS]],
            'alpha bit-field BMP' => [ImageBuilder::bmp(4, 4, 32, 6), [ImageInfo::VARIANT_BMP_ALPHA_BIT_FIELDS]],
            '2-bit BMP' => [ImageBuilder::bmp(4, 4, 2), [ImageInfo::VARIANT_BMP_2_BIT]],
            '64-bit BMP' => [ImageBuilder::bmp(4, 4, 64), [ImageInfo::VARIANT_BMP_64_BIT]],
            'V2 header BMP' => [ImageBuilder::bmp(4, 4, 24, 0, 52), [ImageInfo::VARIANT_BMP_V2_HEADER]],
            'V3 header bit-field BMP' => [ImageBuilder::bmp(4, 4, 32, 3, 56), [ImageInfo::VARIANT_BMP_BIT_FIELDS, ImageInfo::VARIANT_BMP_V3_HEADER]],
        ];
    }

    /**
     * The variants of a format that some decoders do not read are reported, so that backends can be chosen by them.
     *
     * @param list<string> $expected
     *
     * @dataProvider variants
     */
    #[DataProvider('variants')]
    public function testVariantsThatSomeDecodersDoNotReadAreReported(string $data, array $expected): void
    {
        self::assertSame($expected, (new ImageInspector())->inspectStream(self::stream($data))->getVariants());
    }

    public function testInspectStreamReadsFromTheStartWhateverThePosition(): void
    {
        $stream = self::stream(ImageBuilder::gif(3, 2));
        fseek($stream, 7);

        self::assertSame(3, (new ImageInspector())->inspectStream($stream)->getWidth());
        self::assertTrue(is_resource($stream), 'the stream is left open');
    }

    public function testInspectStreamRefusesAClosedStream(): void
    {
        $stream = self::stream(ImageBuilder::gif(3, 2));
        fclose($stream);

        $this->expectException(InvalidArgumentException::class);

        (new ImageInspector())->inspectStream($stream);
    }

    public function testInspectStreamRefusesAResourceThatIsNotAStream(): void
    {
        $context = stream_context_create();

        $this->expectException(InvalidArgumentException::class);

        (new ImageInspector())->inspectStream($context);
    }

    public function testInspectStreamRefusesAStreamThatCannotSeek(): void
    {
        $stream = fopen('php://output', 'wb');
        self::assertIsResource($stream);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('not seekable');

            (new ImageInspector())->inspectStream($stream);
        } finally {
            fclose($stream);
        }
    }

    public function testAMalformedHeaderIsAnInvalidImage(): void
    {
        $exception = self::refusal(new ImageInspector(), ImageBuilder::PNG_SIGNATURE . ImageBuilder::pngHeader(0, 1));

        self::assertInstanceOf(InvalidImageException::class, $exception);
        self::assertSame(ErrorCode::INVALID_IMAGE, $exception->getErrorCode());
    }

    /**
     * The exception the inspector throws for $bytes given as a stream.
     */
    private static function refusal(ImageInspector $inspector, string $bytes): ExceptionInterface
    {
        try {
            $inspector->inspectStream(self::stream($bytes));
        } catch (ExceptionInterface $exception) {
            return $exception;
        }
        self::fail('The input must be refused.');
    }

    /**
     * Encoders write more structures than an untrusted input may hold (vips splits PNG data into 8 KiB IDAT chunks,
     * so a PNG output above 134 MB has more than PngParser::MAX_CHUNKS chunks): an output inspector scales its walks
     * with the allowed size, while an input inspector keeps the constants.
     *
     * @return array<string, array{string, string}>
     */
    public static function structureHeavyFiles(): array
    {
        $gif = ImageBuilder::gifScreen(8, 8) . "\x21\xFE" . ImageBuilder::repeat("\x01A", GifParser::MAX_BLOCKS) . "\x00"
            . ImageBuilder::gifImage(0, 0, 8, 8) . "\x3B";

        return [
            'png' => [ImageFormat::PNG, ImageBuilder::png(8, 8, 8, 2, ImageBuilder::repeat(ImageBuilder::pngChunk('prVt', ''), PngParser::MAX_CHUNKS))],
            'gif' => [ImageFormat::GIF, $gif],
            'webp' => [ImageFormat::WEBP, ImageBuilder::riff(ImageBuilder::vp8x(0, 8, 8) . ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l(8, 8))
                . ImageBuilder::repeat(ImageBuilder::webpChunk('ABCD', ''), WebpParser::MAX_CHUNKS))],
        ];
    }

    /**
     * @dataProvider structureHeavyFiles
     */
    #[DataProvider('structureHeavyFiles')]
    public function testAnOutputInspectorWalksAsManyStructuresAsItsSizeAllows(string $format, string $bytes): void
    {
        $limits = (new Limits())->withMaxBytes(Capabilities::LIMIT_VALUE_MAX);
        $output = ImageInspector::forOutput([$format], strlen($bytes));

        $info = $output->inspect($this->temporaryFile($bytes));
        self::assertSame($format, $info->getFormat());
        self::assertSame(strlen($bytes), $output->getLimits()->getMaxBytes());
        self::assertSame(Capabilities::LIMIT_VALUE_MAX, $output->getLimits()->getMaxWidth());
        self::assertSame([$format], $output->getInputFormats());

        try {
            (new ImageInspector($limits, [$format]))->inspect($this->temporaryFile($bytes));
            self::fail('An input inspector walked more structures than its constant allows.');
        } catch (InvalidImageException $exception) {
            self::assertSame(ErrorCode::INVALID_IMAGE, $exception->getErrorCode());
        }
    }

    public function testAnOutputInspectorRefusesAFileAboveItsSize(): void
    {
        $bytes = ImageBuilder::png(8, 8);

        $this->expectException(LimitExceededException::class);
        ImageInspector::forOutput([ImageFormat::PNG], strlen($bytes) - 1)->inspect($this->temporaryFile($bytes));
    }

    /**
     * @return resource
     */
    private static function stream(string $bytes)
    {
        return CountingStreamWrapper::open($bytes);
    }

    private function temporaryFile(string $bytes, string $prefix = 'inspector'): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($path);
        $this->temporaryPaths[] = $path;
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));

        return $path;
    }
}
