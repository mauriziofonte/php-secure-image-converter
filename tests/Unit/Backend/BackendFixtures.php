<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\BackendCapabilities;
use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;

/**
 * Builders shared by the backend unit tests.
 */
trait BackendFixtures
{
    private static function info(string $format, int $width = 16, int $height = 12, int $frames = 1): ImageInfo
    {
        return new ImageInfo($format, $width, $height, $frames, 1000);
    }

    private static function job(ImageInfo $input, string $output, ?int $width = null, ?int $height = null): BackendJob
    {
        return new BackendJob(
            '/tmp/sic-test/in.' . ImageFormat::extension($input->getFormat()),
            $input,
            '/tmp/sic-test/out.' . ImageFormat::extension($output),
            $output,
            $width !== null ? $width : $input->getWidth(),
            $height !== null ? $height : $input->getHeight()
        );
    }

    /**
     * Capabilities converting the still images of $inputs to $outputs, with every fit.
     *
     * @param list<string> $inputs
     * @param list<string> $outputs
     */
    private static function capabilities(string $backend, array $inputs, array $outputs): BackendCapabilities
    {
        return (new BackendCapabilities($backend, $inputs, $outputs))->withFits(ConversionRequest::fitModes());
    }
}
