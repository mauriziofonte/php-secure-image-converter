<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Backend;

use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\GdBackend;
use Mfonte\SecureImageConverter\Backend\ImagickBackend;
use Mfonte\SecureImageConverter\Backend\VipsBackend;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageBuilder;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every EXIF orientation, turned upright by each backend that declares it orients JPEG: the stored pixels are the
 * upright image transformed by the inverse of the orientation, so the output must be the upright image, its four
 * quadrants (red, green, blue, yellow, an image that no turn or mirror maps onto itself) in place.
 *
 * The test images are made here with Imagick, which also decodes the outputs; without Imagick the test is skipped.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\GdBackend
 * @covers \Mfonte\SecureImageConverter\Backend\ImagickBackend
 * @covers \Mfonte\SecureImageConverter\Backend\VipsBackend
 *
 * @group backends
 */
#[CoversClass(GdBackend::class)]
#[CoversClass(ImagickBackend::class)]
#[CoversClass(VipsBackend::class)]
#[Group('backends')]
final class OrientationTest extends BackendTestCase
{
    /**
     * Size of the upright image: wider than high, so that a missing turn changes the size too.
     */
    private const WIDTH = 32;

    private const HEIGHT = 16;

    /**
     * Colour of each quadrant of the upright image: top left, top right, bottom left, bottom right.
     */
    private const QUADRANTS = [[255, 0, 0], [0, 200, 0], [0, 0, 255], [255, 255, 0]];

    /**
     * @return array<string, array{string, int}>
     */
    public static function orientations(): array
    {
        $cases = [];
        foreach (['vips', 'imagick', 'gd'] as $name) {
            foreach (range(1, 8) as $orientation) {
                $cases[sprintf('%s, orientation %d', $name, $orientation)] = [$name, $orientation];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider orientations
     */
    #[DataProvider('orientations')]
    public function testTheBackendTurnsEveryOrientationUpright(string $name, int $orientation): void
    {
        if (!extension_loaded('imagick')) {
            self::markTestSkipped('ext-imagick makes and decodes the test images.');
        }
        $backend = self::backend($name);
        if (!in_array(ImageFormat::JPEG, $backend->getCapabilities()->getOrientedFormats(), true)) {
            if (self::isExpected($name)) {
                self::fail(sprintf('The %s backend does not declare that it orients JPEG.', $name));
            }
            self::markTestSkipped(sprintf('The %s backend does not orient JPEG on this host.', $name));
        }
        $output = in_array(ImageFormat::PNG, $backend->getCapabilities()->getOutputFormats(), true) ? ImageFormat::PNG : ImageFormat::WEBP;
        $input = $this->workingDirectory()->pathOf('oriented.jpg');
        file_put_contents($input, self::storedJpeg($orientation));
        $info = (new ImageInspector())->inspect($input);
        self::assertSame($orientation, $info->getOrientation());
        $job = BackendJob::fromRequest(
            $input,
            $info,
            $this->workingDirectory()->pathOf('upright.' . ImageFormat::extension($output)),
            (new ConversionRequest(ConversionRequest::FORMAT_ORIGINAL))->withLossless(true)
        )->withOutputFormat($output);
        self::assertTrue($job->needsOrientation() || $orientation === 1);

        $backend->convert($job);

        $image = new \Imagick($job->getOutputPath());
        self::assertSame([self::WIDTH, self::HEIGHT], [$image->getImageWidth(), $image->getImageHeight()]);
        $centres = [[8, 4], [24, 4], [8, 12], [24, 12]];
        foreach ($centres as $index => $centre) {
            $colour = $image->getImagePixelColor($centre[0], $centre[1])->getColor();
            $found = [(int) $colour['r'], (int) $colour['g'], (int) $colour['b']];
            foreach (self::QUADRANTS[$index] as $channel => $expected) {
                self::assertEqualsWithDelta($expected, $found[$channel], 48, sprintf(
                    'quadrant %d is %s instead of %s',
                    $index,
                    implode(',', $found),
                    implode(',', self::QUADRANTS[$index])
                ));
            }
        }
    }

    /**
     * The JPEG that a viewer applying $orientation displays as the upright image.
     */
    private static function storedJpeg(int $orientation): string
    {
        $image = new \Imagick();
        $image->newImage(self::WIDTH, self::HEIGHT, new \ImagickPixel('white'));
        $draw = new \ImagickDraw();
        $half = [self::WIDTH / 2, self::HEIGHT / 2];
        foreach (self::QUADRANTS as $index => $rgb) {
            $draw->setFillColor(new \ImagickPixel(vsprintf('rgb(%d,%d,%d)', $rgb)));
            $left = ($index % 2) * $half[0];
            $top = intdiv($index, 2) * $half[1];
            $draw->rectangle($left, $top, $left + $half[0] - 1, $top + $half[1] - 1);
        }
        $image->drawImage($draw);
        // The inverse of the transform a viewer applies: 2, 3, 4, 5 and 7 are their own inverse, 6 and 8 each other's.
        switch ($orientation) {
            case 2:
                $image->flopImage();
                break;
            case 3:
                $image->rotateImage(new \ImagickPixel('white'), 180);
                break;
            case 4:
                $image->flipImage();
                break;
            case 5:
                $image->transposeImage();
                break;
            case 6:
                $image->rotateImage(new \ImagickPixel('white'), 270);
                break;
            case 7:
                $image->transverseImage();
                break;
            case 8:
                $image->rotateImage(new \ImagickPixel('white'), 90);
                break;
        }
        $image->setImagePage(0, 0, 0, 0);
        $image->setImageFormat('JPEG');
        $image->setImageCompressionQuality(95);
        $image->stripImage();
        $bytes = $image->getImageBlob();

        return "\xFF\xD8" . ImageBuilder::jpegSegment(0xE1, "Exif\0\0" . ImageBuilder::exifOrientation($orientation)) . substr($bytes, 2);
    }
}
