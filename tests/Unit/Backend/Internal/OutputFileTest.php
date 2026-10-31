<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Internal;

use Mfonte\SecureImageConverter\Backend\Internal\OutputFile;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A process may replace its output with anything; only a regular file of its own is taken.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\OutputFile
 */
#[CoversClass(OutputFile::class)]
final class OutputFileTest extends TestCase
{
    /**
     * @var WorkingDirectory|null
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = WorkingDirectory::create();
    }

    protected function tear_down(): void
    {
        if ($this->directory !== null) {
            $this->directory->remove();
        }
        parent::tear_down();
    }

    private function path(string $name): string
    {
        self::assertNotNull($this->directory);

        return $this->directory->pathOf($name);
    }

    public function testARegularFileIsCopiedOverTheDestination(): void
    {
        file_put_contents($this->path('output.webp'), 'RIFF....WEBP');
        file_put_contents($this->path('destination.webp'), 'old');

        OutputFile::copy($this->path('output.webp'), $this->path('destination.webp'), 1024);

        self::assertSame('RIFF....WEBP', file_get_contents($this->path('destination.webp')));
        self::assertNotNull($this->directory);
        self::assertSame(['destination.webp', 'output.webp'], self::files($this->directory->getPath()));
    }

    public function testASymbolicLinkIsRefused(): void
    {
        file_put_contents($this->path('secret'), 'secret');
        symlink($this->path('secret'), $this->path('output.webp'));

        $this->expectRefusal('The conversion output is not a regular file.', 'output.webp');
    }

    public function testADirectoryIsRefused(): void
    {
        mkdir($this->path('output.webp'));

        $this->expectRefusal('The conversion output is not a regular file.', 'output.webp');
    }

    public function testAFifoIsRefusedWithoutBlocking(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('The posix extension is required to create a FIFO.');
        }
        self::assertTrue(posix_mkfifo($this->path('output.webp'), 0600));

        $this->expectRefusal('The conversion output is not a regular file.', 'output.webp');
    }

    public function testAHardLinkIsRefused(): void
    {
        file_put_contents($this->path('other'), 'data');
        link($this->path('other'), $this->path('output.webp'));

        $this->expectRefusal('The conversion output has other links.', 'output.webp');
    }

    public function testAMissingEmptyOrOversizedOutputIsRefused(): void
    {
        $this->expectRefusal('The conversion produced no output file.', 'missing.webp');
        file_put_contents($this->path('empty.webp'), '');
        $this->expectRefusal('The conversion output is empty.', 'empty.webp');
        file_put_contents($this->path('large.webp'), str_repeat('x', 2048));
        $this->expectRefusal('The conversion output is larger than allowed.', 'large.webp');
    }

    private function expectRefusal(string $message, string $name): void
    {
        try {
            OutputFile::copy($this->path($name), $this->path('destination.webp'), 1024);
            self::fail('The output must be refused.');
        } catch (ConversionFailedException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->path('destination.webp'));
    }

    /**
     * @return list<string>
     */
    private static function files(string $directory): array
    {
        $files = array_values(array_diff((array) scandir($directory), ['.', '..']));
        sort($files);

        return array_values(array_filter($files, 'is_string'));
    }
}
