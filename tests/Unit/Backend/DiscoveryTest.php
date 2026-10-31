<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Backend\AvifencBackend;
use Mfonte\SecureImageConverter\Backend\VipsBackend;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use Mfonte\SecureImageConverter\Tests\Unit\Backend\Fake\FakeProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
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
        ]);

        $facts = (new VipsBackend($runner))->discoverFacts();

        self::assertSame(['fail_on' => '1', 'keep' => '1', 'loaders' => 'jpeg,png,gif', 'savers' => 'webp,avif'], $facts);
        $runs = $runner->getRuns();
        self::assertCount(3, $runs);
        self::assertSame('1', $runs[0]['options']->getEnvironment()['VIPS_CONCURRENCY'] ?? null);
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
