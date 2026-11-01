<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Pipeline;

use Mfonte\SecureImageConverter\Backend\Internal\OutputFile;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Pipeline\SecureConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A write that fails part way (a full filesystem) while the input is copied or the output is published, under an
 * error handler that throws (as Laravel's and this suite's do): the converter throws ConversionFailedException and
 * leaves nothing behind, neither in the output directory nor in its working directory.
 *
 * The failure is the file size limit of a child process (RLIMIT_FSIZE, SIGXFSZ ignored): its writes past the limit
 * fail with EFBIG, as they fail with ENOSPC on a full filesystem.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\OutputFile
 * @covers \Mfonte\SecureImageConverter\Pipeline\SecureConverter
 */
#[CoversClass(OutputFile::class)]
#[CoversClass(SecureConverter::class)]
final class FullFilesystemTest extends TestCase
{
    /**
     * @var WorkingDirectory|null
     */
    private $root;

    protected function set_up(): void
    {
        parent::set_up();
        if (\DIRECTORY_SEPARATOR !== '/' || !function_exists('posix_setrlimit') || !is_executable('/bin/sh')) {
            self::markTestSkipped('The file size limit of a child process needs a POSIX host with ext-posix.');
        }
        $this->root = WorkingDirectory::create();
    }

    protected function tear_down(): void
    {
        if ($this->root !== null) {
            $this->root->remove();
        }
        parent::tear_down();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function failures(): array
    {
        return [
            'input copy, error handler honouring @' => ['input', 'respectful', 'The input could not be copied.'],
            'input copy, error handler ignoring @' => ['input', 'strict', 'The input could not be copied.'],
            'output copy, error handler honouring @' => ['output', 'respectful', 'The output file could not be written.'],
            'output copy, error handler ignoring @' => ['output', 'strict', 'The output file could not be written.'],
        ];
    }

    /**
     * @dataProvider failures
     */
    #[DataProvider('failures')]
    public function testAWriteThatFailsPartWayLeavesNothingBehind(string $mode, string $handler, string $message): void
    {
        self::assertNotNull($this->root);
        $command = sprintf(
            "/bin/sh -c 'trap \"\" XFSZ; exec \"$@\"' sh %s %s %s %s %s 2>&1",
            escapeshellarg(PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2) . '/Fixtures/Pipeline/full-filesystem.php'),
            escapeshellarg($this->root->getPath()),
            escapeshellarg($mode),
            escapeshellarg($handler)
        );
        $output = [];
        exec($command, $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame([ConversionFailedException::class . ': ' . $message, '', ''], $output, 'the error, then empty output and working directories');
    }

}
