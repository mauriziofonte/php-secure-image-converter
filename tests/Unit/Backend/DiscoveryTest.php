<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Backend\AvifencBackend;
use Mfonte\SecureImageConverter\Backend\VipsBackend;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use Mfonte\SecureImageConverter\Tests\Unit\Backend\Fake\FakeProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * What the discovery runs of each backend learn from the programs' output, with a runner that answers from canned
 * output.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\AbstractBackend
 * @covers \Mfonte\SecureImageConverter\Backend\AvifencBackend
 * @covers \Mfonte\SecureImageConverter\Backend\VipsBackend
 */
#[CoversClass(AbstractBackend::class)]
#[CoversClass(VipsBackend::class)]
#[CoversClass(AvifencBackend::class)]
final class DiscoveryTest extends TestCase
{
    use FakePrograms;

    private const VIPS_LIST = ''
        . "  VipsForeignLoadJpegFile (jpegload), load jpeg from file (.jpg, .jpeg, .jpe, .jfif), priority=50\n"
        . "  VipsForeignLoadPngFile (pngload), load png from file (.png), priority=200\n"
        . "  VipsForeignLoadNsgifFile (gifload), load GIF with libnsgif (.gif), priority=50\n"
        . "  VipsForeignLoadMagickFile (magickload), load file with ImageMagick, priority=-100, untrusted\n"
        . "  VipsForeignSaveWebpFile (webpsave), save image to webp file (.webp), priority=0\n"
        . "  VipsForeignSaveHeifFile (heifsave), save image in HEIF format (.heic, .heif, .avif), priority=0\n";

    protected function tear_down(): void
    {
        $this->removeFakePrograms();
        parent::tear_down();
    }

    /**
     * @param array<string, ProcessResult> $answers by the last argument of the command
     */
    private function runner(array $answers): FakeProcessRunner
    {
        return new FakeProcessRunner(
            static function (array $command) use ($answers): ProcessResult {
                $key = (string) end($command);

                return $answers[$key] ?? FakeProcessRunner::result(1, '', 'unexpected command');
            },
            $this->programOptions()
        );
    }

    public function testVipsListsItsOperationsAndOptions(): void
    {
        $runner = $this->runner([
            '-l' => FakeProcessRunner::result(0, self::VIPS_LIST),
            'webpsave' => FakeProcessRunner::result(0, "optional arguments:\n   Q            - Q factor\n   keep         - Which metadata to retain\n"),
            'jpegload' => FakeProcessRunner::result(0, "   fail-on      - Error level to fail on\n"),
            '--no-rotate' => FakeProcessRunner::result(1, '', 'VipsForeignLoad: "untrusted.ppm" is not a known file format'),
        ]);

        $facts = (new VipsBackend($runner))->discoverFacts();

        self::assertSame(['fail_on' => '1', 'keep' => '1', 'loaders' => 'jpeg,png,gif', 'savers' => 'webp,avif', 'untrusted_blocked' => '1'], $facts);
        $runs = $runner->getRuns();
        self::assertCount(4, $runs);
        self::assertSame('1', $runs[0]['options']->getEnvironment()['VIPS_CONCURRENCY'] ?? null);
        self::assertSame('1', $runs[3]['options']->getEnvironment()['VIPS_BLOCK_UNTRUSTED'] ?? null);
        self::assertSame(['thumbnail', 'untrusted.ppm', 'untrusted.png', '1', '--no-rotate'], array_slice($runs[3]['command'], 1));
    }

    /**
     * Where VIPS_BLOCK_UNTRUSTED has no effect (libvips before 8.13), vips thumbnail would read a validated TIFF with
     * OpenSlide: the backend then declares no fit, so that every resize goes to another backend.
     *
     * @return array<string, array{0: ProcessResult, 1: string}>
     */
    public static function untrustedLoaderOutcomes(): array
    {
        return [
            'refused by content' => [FakeProcessRunner::result(1, '', 'VipsForeignLoad: "untrusted.ppm" is not a known file format'), '1'],
            'converted' => [FakeProcessRunner::result(0), '0'],
            'failed otherwise' => [FakeProcessRunner::result(1, '', 'out of memory'), '0'],
        ];
    }

    /**
     * @dataProvider untrustedLoaderOutcomes
     */
    #[DataProvider('untrustedLoaderOutcomes')]
    public function testResizeNeedsUntrustedLoadersBlocked(ProcessResult $thumbnail, string $blocked): void
    {
        $backend = new VipsBackend($this->runner([
            '-l' => FakeProcessRunner::result(0, self::VIPS_LIST),
            'webpsave' => FakeProcessRunner::result(0, "   keep         - Which metadata to retain\n"),
            'jpegload' => FakeProcessRunner::result(0, "   fail-on      - Error level to fail on\n"),
            '--no-rotate' => $thumbnail,
        ]));

        $facts = $backend->discoverFacts();
        $candidates = \Closure::bind(function () use ($facts): array {
            return $this->candidates($facts);
        }, $backend, VipsBackend::class);
        $declared = $candidates();

        self::assertSame($blocked, $facts['untrusted_blocked']);
        self::assertSame($blocked === '1' ? ConversionRequest::fitModes() : [], $declared['fits']);
        self::assertSame($blocked === '1' ? ConversionRequest::fitModes() : [], $declared['animated_fits']);
    }

    public function testAnOlderVipsHasNeitherKeepNorFailOn(): void
    {
        $facts = (new VipsBackend($this->runner([
            '-l' => FakeProcessRunner::result(0, self::VIPS_LIST),
            'webpsave' => FakeProcessRunner::result(0, "   strip        - Strip all metadata from image\n"),
            'jpegload' => FakeProcessRunner::result(0, "   fail         - Fail on first warning\n"),
        ])))->discoverFacts();

        self::assertSame('0', $facts['keep']);
        self::assertSame('0', $facts['fail_on']);
    }

    public function testAFailingVipsIsUnavailable(): void
    {
        $facts = (new VipsBackend($this->runner([])))->discoverFacts();

        self::assertSame([AbstractBackend::FACT_UNAVAILABLE => 'vips -l failed'], $facts);
    }

    public function testAvifencQualityOption(): void
    {
        self::assertSame(['qcolor' => '1'], (new AvifencBackend($this->runner([
            '--help' => FakeProcessRunner::result(0, "Syntax: avifenc [options] input.[jpg|jpeg|png|y4m] output.avif\n    -q,--qcolor Q\n"),
        ])))->discoverFacts());
        self::assertSame(['qcolor' => '0'], (new AvifencBackend($this->runner([
            '--help' => FakeProcessRunner::result(1, "Syntax: avifenc [options] input.[jpg|jpeg|png|y4m] output.avif\n    --min Q\n"),
        ])))->discoverFacts());
    }
}
