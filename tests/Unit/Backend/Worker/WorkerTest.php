<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Worker;

use Mfonte\SecureImageConverter\Backend\Worker\AbstractWorker;
use Mfonte\SecureImageConverter\Backend\Worker\GdWorker;
use Mfonte\SecureImageConverter\Backend\Worker\ImagickWorker;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageBuilder;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The worker protocol, run in this process (the backends run it in a PHP CLI of its own).
 *
 * @covers \Mfonte\SecureImageConverter\Backend\Worker\AbstractWorker
 * @covers \Mfonte\SecureImageConverter\Backend\Worker\GdWorker
 * @covers \Mfonte\SecureImageConverter\Backend\Worker\ImagickWorker
 */
#[CoversClass(AbstractWorker::class)]
#[CoversClass(GdWorker::class)]
#[CoversClass(ImagickWorker::class)]
final class WorkerTest extends TestCase
{
    /**
     * @var WorkingDirectory|null
     */
    private $directory;

    /**
     * @var string
     */
    private $cwd = '';

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = WorkingDirectory::create();
        $this->cwd = (string) getcwd();
        chdir($this->directory->getPath());
    }

    protected function tear_down(): void
    {
        chdir($this->cwd);
        if ($this->directory !== null) {
            $this->directory->remove();
        }
        parent::tear_down();
    }

    /**
     * @return array<string, mixed>
     */
    private static function job(): array
    {
        return [
            'input' => 'input.png',
            'input_format' => 'png',
            'input_width' => 16,
            'input_height' => 12,
            'output' => 'output.webp',
            'output_format' => 'webp',
            'width' => 6,
            'height' => 6,
            'fit' => 'cover',
            'quality' => 80,
            'effort' => 4,
            'lossless' => false,
            'strip' => true,
            'keep_icc' => true,
            'orientation' => 1,
            'memory_bytes' => 268435456,
        ];
    }

    public function testAValidJobIsRead(): void
    {
        file_put_contents(AbstractWorker::JOB_FILE, json_encode(self::job()));

        self::assertSame(self::job(), AbstractWorker::readJob(AbstractWorker::JOB_FILE));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidJobs(): array
    {
        return [
            'path in the input name' => [['input' => '../etc/passwd'] + self::job()],
            'other output name' => [['output' => 'out.webp'] + self::job()],
            'svg' => [['input_format' => 'svg'] + self::job()],
            'unknown fit' => [['fit' => 'stretch'] + self::job()],
            'quality as a string' => [['quality' => '80'] + self::job()],
            'effort out of range' => [['effort' => 10] + self::job()],
            'orientation out of range' => [['orientation' => 9] + self::job()],
            'missing flag' => [array_diff_key(self::job(), ['strip' => true])],
        ];
    }

    /**
     * @dataProvider invalidJobs
     *
     * @param array<string, mixed> $job
     */
    #[DataProvider('invalidJobs')]
    public function testAnInvalidJobIsRefused(array $job): void
    {
        file_put_contents(AbstractWorker::JOB_FILE, json_encode($job));

        ob_start();
        $code = GdWorker::main(['gd.php', 'convert']);
        $answer = json_decode((string) ob_get_clean(), true);

        self::assertSame(AbstractWorker::EXIT_INVALID, $code);
        self::assertSame(['ok' => false, 'code' => 1, 'error' => 'The job file is not valid.'], $answer);
    }

    public function testTheJobDecoderReadsWhatTheBackendWrites(): void
    {
        $job = self::job();

        self::assertSame($job, AbstractWorker::decodeJob((string) json_encode($job)));
        self::assertSame($job, AbstractWorker::decodeJob((string) json_encode($job, JSON_PRETTY_PRINT)));
        self::assertSame(['memory_bytes' => PHP_INT_MAX, 'offset' => -1], AbstractWorker::decodeJob('{"memory_bytes":' . PHP_INT_MAX . ',"offset":-1}'));
        self::assertSame([], AbstractWorker::decodeJob(' { } '));
    }

    /**
     * Texts the job decoder refuses: more than json_encode() writes for a job, or not JSON.
     *
     * @return array<string, array{0: string}>
     */
    public static function undecodableJobs(): array
    {
        return [
            'empty' => [''],
            'list' => ['["input.png"]'],
            'trailing comma' => ['{"quality":80,}'],
            'duplicate key' => ['{"quality":80,"quality":90}'],
            'float' => ['{"quality":80.5}'],
            'exponent' => ['{"quality":8e1}'],
            'leading zero' => ['{"quality":080}'],
            'beyond the integer range' => ['{"memory_bytes":9223372036854775808}'],
            'null' => ['{"input":null}'],
            'nested' => ['{"input":{"a":1}}'],
            'escape' => ['{"input":"input\\u002epng"}'],
            'slash' => ['{"input":"a/b"}'],
            'upper-case key' => ['{"Input":"input.png"}'],
            'text after' => ['{"quality":80}{}'],
            'single quotes' => ["{'quality':80}"],
        ];
    }

    /**
     * @dataProvider undecodableJobs
     */
    #[DataProvider('undecodableJobs')]
    public function testAnUndecodableJobIsRefused(string $text): void
    {
        self::assertNull(AbstractWorker::decodeJob($text));
        file_put_contents(AbstractWorker::JOB_FILE, $text);

        ob_start();
        $code = GdWorker::main(['gd.php', 'convert']);
        $answer = json_decode((string) ob_get_clean(), true);

        self::assertSame(AbstractWorker::EXIT_INVALID, $code);
        self::assertSame(['ok' => false, 'code' => 1, 'error' => 'The job file is not valid.'], $answer);
    }

    /**
     * @return array<string, array{0: array<int|string, mixed>}>
     */
    public static function answers(): array
    {
        return [
            'probe' => [['inputs' => ['jpeg', 'png'], 'outputs' => [], 'lossless' => ['webp']]],
            'success' => [['ok' => true]],
            'failure' => [['ok' => false, 'code' => 2, 'error' => "Quotes \" back\\slash /slash\n\ttab \x01 \x7f."]],
            'null and negative' => [['value' => null, 'number' => -5, 'list' => [[1, 2], []]]],
            'numbered keys' => [[1 => 'a', 3 => 'b']],
        ];
    }

    /**
     * @dataProvider answers
     *
     * @param array<int|string, mixed> $answer
     */
    #[DataProvider('answers')]
    public function testAnAnswerIsTheJsonOfItsValue(array $answer): void
    {
        self::assertSame($answer, json_decode(AbstractWorker::encodeAnswer($answer), true));
    }

    public function testAnAnswerWritesBytesOutsideAsciiAsQuestionMarks(): void
    {
        self::assertSame('"caf?? ?"', AbstractWorker::encodeAnswer("caf\xC3\xA9 \xFF"));
    }

    public function testAnUnknownModeIsRefused(): void
    {
        ob_start();
        $code = ImagickWorker::main(['imagick.php', 'shell']);
        ob_end_clean();

        self::assertSame(AbstractWorker::EXIT_INVALID, $code);
    }

    public function testGdConvertsAndCrops(): void
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            self::markTestSkipped('ext-gd with WEBP support is not loaded.');
        }
        copy(__DIR__ . '/../../../../resources/probe/still.png', 'input.png');
        file_put_contents(AbstractWorker::JOB_FILE, json_encode(self::job()));

        ob_start();
        $code = GdWorker::main(['gd.php', 'convert']);
        $answer = json_decode((string) ob_get_clean(), true);

        self::assertSame(['ok' => true], $answer);
        self::assertSame(AbstractWorker::EXIT_OK, $code);
        $info = (new ImageInspector(null, ImageFormat::all()))->inspect((string) realpath('output.webp'));
        self::assertSame(['webp', 6, 6], [$info->getFormat(), $info->getWidth(), $info->getHeight()]);
    }

    public function testAGdJpegCarriesNoMetadata(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is not loaded.');
        }
        copy(__DIR__ . '/../../../../resources/probe/still.png', 'input.png');
        file_put_contents(AbstractWorker::JOB_FILE, json_encode(['output' => 'output.jpg', 'output_format' => 'jpeg', 'strip' => false] + self::job()));

        ob_start();
        $code = GdWorker::main(['gd.php', 'convert']);
        ob_end_clean();

        self::assertSame(AbstractWorker::EXIT_OK, $code);
        $info = (new ImageInspector(null, ImageFormat::all()))->inspect((string) realpath('output.jpg'));
        self::assertSame([ImageFormat::JPEG, false, false], [$info->getFormat(), $info->hasMetadata(), $info->hasTrailingData()]);
    }

    /**
     * Encoder outputs with metadata of their own, and whether an ICC profile remains once it is dropped with and
     * without keeping the profile.
     *
     * @return array<string, array{string, string}>
     */
    public static function encoderMetadata(): array
    {
        $jpeg = ImageBuilder::jpeg(8, 6, 1, ImageBuilder::jpegSegment(0xFE, 'CREATOR: gd-jpeg v1.0') . ImageBuilder::jpegExif()
            . ImageBuilder::jpegSegment(0xED, 'Photoshop 3.0') . ImageBuilder::jpegIcc() . ImageBuilder::jpegSegment(0xEE, "Adobe\0\x64\0\0\0\0\1")
            . ImageBuilder::jpegSegment(0xE0, "JFXX\0\x10"));
        $application = static function (string $identifier, string $data): string {
            return "\x21\xFF\x0B" . $identifier . ImageBuilder::gifSubBlocks($data);
        };
        $gif = ImageBuilder::gif(8, 6, 1, $application('ImageMagick', 'gamma=0.45455') . ImageBuilder::gifComment('made here')
            . $application('NETSCAPE2.0', "\x01\0\0") . $application('ICCRGBG1012', ImageBuilder::iccProfile()) . "\x21\x01" . ImageBuilder::gifSubBlocks(str_repeat("\0", 12) . 'text'));

        return [
            'jpeg' => ['jpeg', $jpeg],
            'gif' => ['gif', $gif],
        ];
    }

    /**
     * @dataProvider encoderMetadata
     */
    #[DataProvider('encoderMetadata')]
    public function testEncoderMetadataIsDropped(string $format, string $bytes): void
    {
        $inspector = new ImageInspector(null, ImageFormat::all());
        file_put_contents('output', $bytes);
        $before = $inspector->inspect((string) realpath('output'));
        self::assertSame([true, true], [$before->hasMetadata(), $before->hasIccProfile()], 'the fixture carries both');

        foreach ([true, false] as $keepIcc) {
            file_put_contents('output', $bytes);
            StrippingWorker::drop('output', $format, $keepIcc);
            clearstatcache();
            $after = $inspector->inspect((string) realpath('output'));

            self::assertSame([$format, 8, 6], [$after->getFormat(), $after->getWidth(), $after->getHeight()]);
            self::assertSame([false, $keepIcc, false], [$after->hasMetadata(), $after->hasIccProfile(), $after->hasTrailingData()]);
        }
    }

    public function testAnOutputThatDoesNotParseIsLeftAsItIs(): void
    {
        foreach (['jpeg' => substr(ImageBuilder::jpeg(8, 6, 1, ImageBuilder::jpegExif()), 0, 30), 'gif' => 'GIF89a' . "\0\0", 'png' => ImageBuilder::png(8, 6, 8, 2, ImageBuilder::pngChunk('tEXt', "k\0v"))] as $format => $bytes) {
            file_put_contents('output', $bytes);
            StrippingWorker::drop('output', $format, false);
            clearstatcache();

            self::assertSame($bytes, file_get_contents('output'), $format);
        }
    }

    /**
     * Where the top-left pixel of a 4 x 2 image lands once each EXIF orientation is applied.
     *
     * @return array<string, array{int, int, int, int, int}>
     */
    public static function orientations(): array
    {
        return [
            '1 as stored' => [1, 4, 2, 0, 0],
            '2 mirrored' => [2, 4, 2, 3, 0],
            '3 half turn' => [3, 4, 2, 3, 1],
            '4 flipped' => [4, 4, 2, 0, 1],
            '5 transposed' => [5, 2, 4, 0, 0],
            '6 quarter turn clockwise' => [6, 2, 4, 1, 0],
            '7 transversed' => [7, 2, 4, 1, 3],
            '8 quarter turn anticlockwise' => [8, 2, 4, 0, 3],
        ];
    }

    /**
     * @dataProvider orientations
     */
    #[DataProvider('orientations')]
    public function testGdTurnsTheImageUpright(int $orientation, int $width, int $height, int $x, int $y): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is not loaded.');
        }
        $image = imagecreatetruecolor(4, 2);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 0, 0, 255));
        imagesetpixel($image, 0, 0, (int) imagecolorallocate($image, 255, 0, 0));
        self::assertTrue(imagepng($image, 'input.png'));
        $job = ['input_width' => 4, 'input_height' => 2, 'output' => 'output.png', 'output_format' => 'png', 'width' => $width, 'height' => $height, 'fit' => 'fill', 'orientation' => $orientation] + self::job();
        file_put_contents(AbstractWorker::JOB_FILE, json_encode($job));

        ob_start();
        $code = GdWorker::main(['gd.php', 'convert']);
        $answer = json_decode((string) ob_get_clean(), true);

        self::assertSame(['ok' => true], $answer);
        self::assertSame(AbstractWorker::EXIT_OK, $code);
        $output = imagecreatefrompng('output.png');
        self::assertNotFalse($output);
        self::assertSame([$width, $height], [imagesx($output), imagesy($output)]);
        for ($row = 0; $row < $height; ++$row) {
            for ($column = 0; $column < $width; ++$column) {
                $red = (imagecolorat($output, $column, $row) >> 16) & 0xFF;
                self::assertSame($column === $x && $row === $y ? 255 : 0, $red, sprintf('pixel %d,%d', $column, $row));
            }
        }
    }

    public function testGdProbeListsWhatItsBuildSupports(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is not loaded.');
        }

        ob_start();
        GdWorker::main(['gd.php', 'probe']);
        $answer = json_decode((string) ob_get_clean(), true);

        self::assertIsArray($answer);
        self::assertIsArray($answer['inputs']);
        self::assertIsArray($answer['outputs']);
        self::assertContains('png', $answer['inputs']);
        self::assertNotContains('tiff', $answer['inputs']);
        self::assertNotContains('avif', $answer['outputs']);
    }
}
