<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Worker;

/**
 * The GD worker (see AbstractWorker): decodes with imagecreatefrom*(), scales with imagecopyresampled() and encodes
 * with image*(). GD keeps no metadata: every output is stripped, ICC profile included. A job's orientation is applied
 * with imagerotate() and imageflip() before the resize. It reads the first frame of a
 * GIF and the default image of an APNG; it does not read animated WEBP. AVIF is left to vips and avifenc: few GD
 * builds have it (PHP 8.1 and later with a libgd built with libavif), and its decoder has no limits of its own.
 *
 * @internal
 */
final class GdWorker extends AbstractWorker
{
    /**
     * Decoder and encoder of each format.
     */
    private const FUNCTIONS = [
        'jpeg' => ['imagecreatefromjpeg', 'imagejpeg'],
        'png' => ['imagecreatefrompng', 'imagepng'],
        'gif' => ['imagecreatefromgif', 'imagegif'],
        'bmp' => ['imagecreatefrombmp', 'imagebmp'],
        'webp' => ['imagecreatefromwebp', 'imagewebp'],
    ];

    /**
     * Entry point of resources/workers/gd.php.
     *
     * @param list<string> $arguments
     */
    public static function main(array $arguments): int
    {
        return (new self())->run($arguments);
    }

    protected function probe(): array
    {
        $info = function_exists('gd_info') ? gd_info() : [];
        $support = [
            'jpeg' => [!empty($info['JPEG Support']), !empty($info['JPEG Support'])],
            'png' => [!empty($info['PNG Support']), !empty($info['PNG Support'])],
            'gif' => [!empty($info['GIF Read Support']), !empty($info['GIF Create Support'])],
            'bmp' => [!empty($info['BMP Support']), !empty($info['BMP Support'])],
            'webp' => [!empty($info['WebP Support']), !empty($info['WebP Support'])],
        ];
        $inputs = [];
        $outputs = [];
        foreach (self::FUNCTIONS as $format => $functions) {
            if ($support[$format][0] && function_exists($functions[0])) {
                $inputs[] = $format;
            }
            if ($support[$format][1] && function_exists($functions[1])) {
                $outputs[] = $format;
            }
        }

        return [
            'inputs' => $inputs,
            'outputs' => $outputs,
            'lossless' => in_array('webp', $outputs, true) && defined('IMG_WEBP_LOSSLESS') ? ['webp'] : [],
        ];
    }

    protected function convert(array $job): void
    {
        $input = $job['input'];
        error_clear_last();
        switch ($job['input_format']) {
            case 'jpeg':
                $image = @imagecreatefromjpeg($input);
                break;
            case 'png':
                $image = @imagecreatefrompng($input);
                break;
            case 'gif':
                $image = @imagecreatefromgif($input);
                break;
            case 'bmp':
                $image = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($input) : false;
                break;
            case 'webp':
                $image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($input) : false;
                break;
            default:
                $image = false;
        }
        if ($image === false) {
            // libgd reports a failed allocation as a warning ("cannot allocate image data", libjpeg's "Insufficient
            // memory"), which tells the memory limit from a broken file.
            $error = error_get_last();
            $message = is_array($error) ? strtolower($error['message']) : '';
            if (strpos($message, 'alloc') !== false || strpos($message, 'memory') !== false) {
                throw new \RuntimeException('The input could not be decoded within the memory limit.', self::EXIT_RESOURCE_LIMIT);
            }

            throw new \RuntimeException('The input could not be decoded.', self::EXIT_FAILED);
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        // Turned upright (AbstractWorker::uprightTransform()); imagerotate() turns anticlockwise.
        list($turns, $mirror) = self::uprightTransform($job['orientation']);
        if ($turns > 0) {
            imagealphablending($image, false);
            $image = imagerotate($image, 360 - 90 * $turns, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
            if ($image === false) {
                throw new \RuntimeException('The image could not be rotated.', self::EXIT_RESOURCE_LIMIT);
            }
        }
        if ($mirror) {
            // It fails only for an unknown mode (always true since PHP 8.0).
            imageflip($image, (int) constant('IMG_FLIP_HORIZONTAL'));
        }
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width !== $job['width'] || $height !== $job['height']) {
            $region = $job['fit'] === 'cover'
                ? self::coverRegion($width, $height, $job['width'], $job['height'])
                : [0, 0, $width, $height];
            $resized = imagecreatetruecolor(max(1, $job['width']), max(1, $job['height']));
            if ($resized === false) {
                throw new \RuntimeException('The output image could not be allocated.', self::EXIT_RESOURCE_LIMIT);
            }
            imagealphablending($resized, false);
            imagefill($resized, 0, 0, (int) imagecolorallocatealpha($resized, 0, 0, 0, 127));
            imagecopyresampled($resized, $image, 0, 0, $region[0], $region[1], $job['width'], $job['height'], $region[2], $region[3]);
            $image = $resized;
        }
        imagesavealpha($image, true);
        $output = $job['output'];
        switch ($job['output_format']) {
            case 'jpeg':
                $written = imagejpeg($image, $output, $job['quality']);
                break;
            case 'png':
                $written = imagepng($image, $output, min(9, max(1, $job['effort'])));
                break;
            case 'gif':
                imagetruecolortopalette($image, true, 255);
                $written = imagegif($image, $output);
                break;
            case 'bmp':
                $written = function_exists('imagebmp') && imagebmp($image, $output, false);
                break;
            case 'webp':
                if ($job['lossless'] && !defined('IMG_WEBP_LOSSLESS')) {
                    throw new \RuntimeException('GD cannot encode a lossless WEBP.', self::EXIT_FAILED);
                }
                $written = imagewebp($image, $output, $job['lossless'] ? (int) constant('IMG_WEBP_LOSSLESS') : $job['quality']);
                break;
            default:
                throw new \RuntimeException('GD does not support this format.', self::EXIT_FAILED);
        }
        if ($written !== true) {
            throw new \RuntimeException('The output could not be encoded.', self::EXIT_FAILED);
        }
        self::assertWritten($output);
        // GD keeps no metadata of the input, but its JPEG encoder adds a comment of its own.
        self::dropEncoderMetadata($output, $job['output_format'], false);
    }
}
