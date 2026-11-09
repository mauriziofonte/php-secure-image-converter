<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Pipeline;

use Mfonte\SecureImageConverter\Backend\BackendResolver;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\SecureConverter;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageCorpus;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The walk caps of the parsers stay those of untrusted input in the converter. LIB-6 bounds the walks of OUTPUT files
 * by their allowed size instead (ImageInspector::forOutput(): vips writes a PNG in 8 KiB IDAT chunks), so the corpus
 * files that hold one chunk, block or extension too many must be refused by SecureConverter as invalid_image before
 * any backend, while the output inspector of the same converter accepts them.
 *
 * @covers \Mfonte\SecureImageConverter\Pipeline\SecureConverter
 * @covers \Mfonte\SecureImageConverter\Validation\ImageInspector
 */
#[CoversClass(SecureConverter::class)]
#[CoversClass(ImageInspector::class)]
final class StrictInputWalkTest extends TestCase
{
    /**
     * The corpus cases one step beyond a walk cap of PNG, GIF and WEBP, the parsers whose output walks grow.
     */
    private const OVER_THE_CAP = [
        'png/spoof-too-many-chunks.png' => ImageFormat::PNG,
        'gif/spoof-too-many-blocks.gif' => ImageFormat::GIF,
        'gif/spoof-too-many-extensions.gif' => ImageFormat::GIF,
        'webp/spoof-too-many-chunks.webp' => ImageFormat::WEBP,
    ];

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

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function overTheCap(): array
    {
        $cases = ImageCorpus::cases();
        $data = [];
        foreach (self::OVER_THE_CAP as $path => $format) {
            $data[$path] = [$path, $format, $cases[$path]['bytes']];
        }

        return $data;
    }

    /**
     * @dataProvider overTheCap
     */
    #[DataProvider('overTheCap')]
    public function testTheConverterRefusesWhatOnlyAnOutputMayHold(string $path, string $format, string $bytes): void
    {
        self::assertNotNull($this->directory);
        $input = $this->directory->pathOf('input');
        $output = $this->directory->pathOf('output.webp');
        file_put_contents($input, $bytes);
        $options = (new ConverterOptions())->withWorkingDirectoryParent($this->directory->getPath());
        // No backend: the input must be refused before one is chosen.
        $converter = new SecureConverter(new ImageInspector(null, ImageFormat::all()), new BackendResolver([]), $options);

        try {
            $converter->convert($input, $output, new ConversionRequest());
            self::fail($path . ' must be refused.');
        } catch (ExceptionInterface $exception) {
            self::assertSame('invalid_image', $exception->getErrorCode(), $path . ': ' . $exception->getMessage());
        }
        self::assertFileDoesNotExist($output);

        $info = ImageInspector::forOutput([$format], $options->getMaxOutputBytes())->inspect($input);
        self::assertSame($format, $info->getFormat(), $path . ' is a valid output');
    }
}
