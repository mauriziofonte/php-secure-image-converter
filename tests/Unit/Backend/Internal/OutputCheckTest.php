<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Internal;

use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\Internal\OutputCheck;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageBuilder;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * An output is delivered only when it is a complete image of the job's format, size and frame count.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\OutputCheck
 */
#[CoversClass(OutputCheck::class)]
final class OutputCheckTest extends TestCase
{
    /**
     * What vips 8.15 writes for an AVIF of 3000x2000 when the AV1 encoder fails to allocate (address space limit at
     * 85 % of the estimate of the first delivery), with exit status 0: a meta box without its image item.
     */
    private const VIPS_ALLOCATION_FAILURE_AVIF = 'AAAAHGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZgAAALptZXRhAAAAAAAAACFoZGxyAAAAAAAAAABwaWN0AAAAAAAA'
        . 'AAAAAAAAAAAAAA5waXRtAAAAAAAAAAAAEGlsb2MAAAAAREAAAAAAADhpaW5mAAAAAAACAAAAFWluZmUCAAAAAAEAAGF2MDEAAAAAFWluZmUCAAAAAAIA'
        . 'AGF2MDEAAAAAN2lwcnAAAAAbaXBjbwAAABNjb2xybmNseAABAA0ABoAAAAAUaXBtYQAAAAAAAAABAAEBAQAAAAhtZGF0';

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
     * The probe images of every format, still and animated; the TIFF one holds two pages, as no output does.
     *
     * @return array<string, array{string}>
     */
    public static function images(): array
    {
        $cases = [];
        foreach ((array) glob(\dirname(__DIR__, 4) . '/resources/probe/*') as $path) {
            if (is_string($path) && substr($path, -5) !== '.tiff') {
                $cases[basename($path)] = [$path];
            }
        }
        $cases['single page tiff'] = [''];

        return $cases;
    }

    /**
     * @dataProvider images
     */
    #[DataProvider('images')]
    public function testACompleteOutputPasses(string $path): void
    {
        $path = $this->image($path);

        self::assertNull(OutputCheck::problem(self::jobFor($path), $path, 1048576));
    }

    /**
     * Every prefix of a valid output, from one byte to all but the last one, is refused.
     *
     * @dataProvider images
     */
    #[DataProvider('images')]
    public function testAnOutputCutAnywhereIsRefused(string $path): void
    {
        $path = $this->image($path);
        $job = self::jobFor($path);
        $bytes = (string) file_get_contents($path);
        $cut = $this->path('cut');

        for ($length = 1; $length < strlen($bytes); ++$length) {
            file_put_contents($cut, substr($bytes, 0, $length));
            self::assertSame(
                sprintf('it is not a valid %s image', $job->getOutputFormat()),
                OutputCheck::problem($job, $cut, 1048576),
                sprintf('%s cut to %d bytes', basename($path), $length)
            );
        }
    }

    public function testTheAvifVipsWritesWhenItRunsOutOfMemoryIsRefused(): void
    {
        $avif = $this->path('output.avif');
        file_put_contents($avif, (string) base64_decode(self::VIPS_ALLOCATION_FAILURE_AVIF, true));
        self::assertSame(222, filesize($avif));
        $job = self::jobFor(\dirname(__DIR__, 4) . '/resources/probe/still.avif');

        self::assertSame('it is not a valid avif image', OutputCheck::problem($job, $avif, 1048576));
    }

    public function testAnOutputOfAnotherFormatSizeOrFrameCountIsRefused(): void
    {
        $probe = \dirname(__DIR__, 4) . '/resources/probe';
        $still = self::jobFor($probe . '/still.webp');

        self::assertSame('it is not a valid webp image', OutputCheck::problem($still, $probe . '/still.png', 1048576));
        self::assertSame('it is 16x12 instead of 20x15', OutputCheck::problem(self::jobFor($probe . '/still.webp', 20, 15), $probe . '/still.webp', 1048576));
        self::assertSame('it has 3 frames instead of 1', OutputCheck::problem($still, $probe . '/anim.webp', 1048576));
        self::assertSame('it is not a valid webp image', OutputCheck::problem($still, $probe . '/still.webp', 95), 'larger than the file size limit');
        $animated = self::jobFor($probe . '/anim.webp');
        self::assertTrue($animated->isAnimated());
        self::assertNull(OutputCheck::problem($animated, $probe . '/still.webp', 1048576), 'an encoder may merge identical frames');
    }

    /**
     * @dataProvider images
     */
    #[DataProvider('images')]
    public function testAnOutputFollowedByDataThatDecodersSkipIsRefused(string $path): void
    {
        $source = $this->image($path);
        $padded = $this->path('padded-' . basename($source));
        file_put_contents($padded, file_get_contents($source) . '<html><script>alert(1)</script></html>');

        // AVIF boxes must tile the file: bytes that are no box make it invalid instead.
        $expected = substr($source, -5) === '.avif' ? 'it is not a valid avif image' : 'it holds data that decoders skip';

        self::assertSame($expected, OutputCheck::problem(self::jobFor($source), $padded, 1048576));
    }

    public function testMetadataIsRefusedWhenTheJobStripsIt(): void
    {
        $jpeg = \dirname(__DIR__, 4) . '/resources/probe/still.jpg';
        $job = self::jobFor($jpeg);

        self::assertNull(OutputCheck::problem($job, $jpeg, 1048576));
        self::assertSame('it carries metadata that was to be stripped', OutputCheck::problem($job->withStripMetadata(true), $jpeg, 1048576));
        $icc = $this->path('icc.jpg');
        file_put_contents($icc, ImageBuilder::jpeg(8, 8, 1, ImageBuilder::jpegIcc()));
        $job = self::jobFor($icc)->withStripMetadata(true);
        self::assertNull(OutputCheck::problem($job, $icc, 1048576), 'the ICC profile is kept by default');
        self::assertSame('it carries an ICC profile that was to be stripped', OutputCheck::problem($job->withKeepIccProfile(false), $icc, 1048576));
    }

    public function testATiffOutputHoldsOnePage(): void
    {
        $tiff = \dirname(__DIR__, 4) . '/resources/probe/still.tiff';

        self::assertSame('it has more than one page', OutputCheck::problem(self::jobFor($tiff), $tiff, 1048576));
    }

    /**
     * The path of an image of images(): '' for the single page TIFF, made of the first page of the probe TIFF (its
     * header, strip, directory and the values it points to, in the first 852 bytes) with no next directory.
     */
    private function image(string $path): string
    {
        if ($path !== '') {
            return $path;
        }
        $bytes = substr((string) file_get_contents(\dirname(__DIR__, 4) . '/resources/probe/still.tiff'), 0, 852);
        $path = $this->path('page.tiff');
        // The next directory offset follows the 16 entries of the directory at 584.
        file_put_contents($path, substr_replace($bytes, "\0\0\0\0", 584 + 2 + 16 * 12, 4));

        return $path;
    }

    private function path(string $name): string
    {
        self::assertNotNull($this->directory);

        return $this->directory->pathOf($name);
    }

    /**
     * A job converting an image to its own format and size, keeping its frames and metadata.
     */
    private static function jobFor(string $path, ?int $width = null, ?int $height = null): BackendJob
    {
        $info = (new ImageInspector(null, array_values(array_diff(ImageFormat::all(), [ImageFormat::SVG]))))->inspect($path);

        return (new BackendJob(
            $path,
            $info,
            '/nonexistent/output.' . ImageFormat::extension($info->getFormat()),
            $info->getFormat(),
            $width !== null ? $width : $info->getWidth(),
            $height !== null ? $height : $info->getHeight()
        ))->withStripMetadata(false);
    }
}
