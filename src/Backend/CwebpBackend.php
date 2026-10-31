<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Backend\Internal\CoverCrop;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;

/**
 * libwebp's cwebp: still PNG, JPEG, TIFF (first page only) and WEBP input to WEBP.
 *
 * | Setting  | Mapping                                                                                  |
 * | -------- | ---------------------------------------------------------------------------------------- |
 * | quality  | -q                                                                                       |
 * | effort   | -m 0 to 6 (scaled); lossless: -z 0 to 9, the lossless preset of that level              |
 * | metadata | -metadata none, icc or all                                                               |
 * | resize   | -resize to the exact size; cover first crops the centred region with -crop               |
 */
final class CwebpBackend extends AbstractBackend
{
    public const NAME = 'cwebp';

    /**
     * Fixed address space and bytes per decoded and encoded pixel. Measured in the sandbox on RGBA noise (the
     * costliest content): 47 MiB at 1 megapixel and 143 MiB at 4, about 15 MiB plus 34 bytes per pixel (lossless: 63
     * and 151 MiB); with about 15 % added.
     */
    private const BASE_BYTES = 33554432;

    private const BYTES_PER_INPUT_PIXEL = 8;

    private const BYTES_PER_OUTPUT_PIXEL = 32;

    public function getName(): string
    {
        return self::NAME;
    }

    public function estimateMemoryBytes(BackendJob $job): int
    {
        return self::addressSpace(self::BASE_BYTES, self::BYTES_PER_INPUT_PIXEL, $job->getInputPixels(), self::BYTES_PER_OUTPUT_PIXEL, $job->getOutputPixels());
    }

    protected function programs(ExecutableFinder $finder): array
    {
        return ['cwebp' => $finder->find('cwebp')];
    }

    protected function discover(array $programs, WorkingDirectory $directory): array
    {
        return [];
    }

    protected function candidates(array $facts): array
    {
        $candidates = self::noCandidates();
        $candidates['inputs'] = [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::WEBP, ImageFormat::TIFF];
        $candidates['outputs'] = [ImageFormat::WEBP];
        $candidates['fits'] = ConversionRequest::fitModes();
        $candidates['lossless'] = [ImageFormat::WEBP];
        $candidates['icc_profile'] = true;
        $candidates['metadata'] = true;

        return $candidates;
    }

    protected function command(BackendJob $job, string $input, string $output, array $programs, array $facts): array
    {
        $command = [$programs['cwebp'], '-quiet'];
        if ($job->needsLosslessMode()) {
            array_push($command, '-z', (string) $job->getEffort());
        } else {
            array_push($command, '-q', (string) $job->getQuality(), '-m', (string) (int) round($job->getEffort() * 6 / 9));
        }
        if (!$job->shouldStripMetadata()) {
            $metadata = 'all';
        } else {
            $metadata = $job->shouldKeepIccProfile() ? 'icc' : 'none';
        }
        array_push($command, '-metadata', $metadata);
        if ($job->isResized()) {
            if ($job->getFit() === ConversionRequest::FIT_COVER) {
                $region = CoverCrop::region(
                    $job->getInput()->getWidth(),
                    $job->getInput()->getHeight(),
                    $job->getWidth(),
                    $job->getHeight()
                );
                array_push($command, '-crop', (string) $region[0], (string) $region[1], (string) $region[2], (string) $region[3]);
            }
            array_push($command, '-resize', (string) $job->getWidth(), (string) $job->getHeight());
        }
        array_push($command, $input, '-o', $output);

        return $command;
    }
}
