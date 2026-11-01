<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Internal;

/**
 * The centred region of an image that, scaled to an exact size, covers it without distortion (fit cover).
 *
 * @internal
 */
final class CoverCrop
{
    /**
     * @return array{0: int, 1: int, 2: int, 3: int} left, top, width and height of the region, inside the image
     */
    public static function region(int $width, int $height, int $targetWidth, int $targetHeight): array
    {
        $scale = max($targetWidth / $width, $targetHeight / $height);
        $regionWidth = max(1, min($width, (int) round($targetWidth / $scale)));
        $regionHeight = max(1, min($height, (int) round($targetHeight / $scale)));

        return [
            intdiv($width - $regionWidth, 2),
            intdiv($height - $regionHeight, 2),
            $regionWidth,
            $regionHeight,
        ];
    }
}
