<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Worker;

use Mfonte\SecureImageConverter\Backend\Worker\AbstractWorker;
use Mfonte\SecureImageConverter\Backend\Worker\GdWorker;
use Mfonte\SecureImageConverter\Backend\Worker\ImagickWorker;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
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
