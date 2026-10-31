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
     * Address space by profile (AbstractBackend::estimate()): fixed bytes and bytes per encoded pixel, on top of
     * BYTES_PER_INPUT_PIXEL per decoded pixel and, for a resize, RESIZE_BYTES per encoded pixel (the rescaled copy).
     * The largest peak address space (VmPeak, MiB) of tests/Fixtures/backend/memory-peaks.csv at each size of a still
     * image, over every content, input format, bit depth and effort of the profile:
     *
     * | Profile                       | Fixed  | Per pixel | 1 MP | 2.1 MP | 4 MP | 8.3 MP | 20 MP |
     * | ----------------------------- | ------ | --------- | ---- | ------ | ---- | ------ | ----- |
     * | lossy, effort 0 to 8 (-m 0-5) | 64 MiB | 36        | 42   | 90     | 140  | 278    | 646   |
     * | lossy, effort 9 (-m 6)        | 48 MiB | 64        | 63   | 118    | 208  | 436    | 883   |
     * | lossless, effort 0 to 7 (-z)  | 96 MiB | 36        | 61   | 114    | 148  | 295    | 507   |
     * | lossless, effort 8 and 9      | 48 MiB | 76        | 76   | 144    | 249  | 514    | 752   |
     *
     * Noise and palette noise need the most; cwebp reads a 16-bit PNG as 8 bits.
     */
    private const PROFILES = [
        'lossy' => [64 * self::MIB, 36],
        'lossy_slow' => [48 * self::MIB, 64],
        'lossless' => [96 * self::MIB, 36],
        'lossless_slow' => [48 * self::MIB, 76],
    ];

    private const BYTES_PER_INPUT_PIXEL = 8;

    private const RESIZE_BYTES = 8;

    private const MIB = 1048576;

    public function getName(): string
    {
        return self::NAME;
    }

    public function estimateMemoryBytes(BackendJob $job): int
    {
        if ($job->needsLosslessMode()) {
            $profile = $job->getEffort() >= 8 ? 'lossless_slow' : 'lossless';
        } else {
            $profile = self::webpMethod($job) === 6 ? 'lossy_slow' : 'lossy';
        }
        list($fixed, $perPixel) = self::PROFILES[$profile];

        return self::estimate($job, $fixed, self::BYTES_PER_INPUT_PIXEL, $perPixel, 0, 0, self::RESIZE_BYTES);
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
            array_push($command, '-q', (string) $job->getQuality(), '-m', (string) self::webpMethod($job));
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
