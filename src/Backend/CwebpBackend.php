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
     * Address space by profile (AbstractBackend::estimate()): fixed bytes and bytes per encoded pixel, on top of 8
     * bytes per decoded pixel. The peak address space (VmPeak, MiB) of the profile's costliest effort on RGBA noise at
     * 1, 4, 6 and 20 megapixels, a 16-bit input in parentheses:
     *
     * | Profile                       | Fixed   | Per pixel | 1 MP    | 4 MP      | 6 MP | 20 MP |
     * | ----------------------------- | ------- | --------- | ------- | --------- | ---- | ----- |
     * | lossy, effort 0 to 8 (-m 0-5) | 32 MiB  | 31        | 42 (42) | 139 (139) |      | 646   |
     * | lossy, effort 9 (-m 6)        | 32 MiB  | 51        | 62 (62) | 208 (208) | 305  |       |
     * | lossless, effort 0 to 7 (-z)  | 48 MiB  | 33        | 60 (60) | 147 (147) | 215  |       |
     * | lossless, effort 8 and 9      | 48 MiB  | 57        | 73 (74) | 234 (234) |      |       |
     *
     * cwebp reads a 16-bit PNG as 8 bits. A resize keeps a rescaled copy: RESIZE_BYTES more per encoded pixel (164
     * MiB from 1 to 4 megapixels, against 139 MiB without resize). Measured with libwebp 1.3.
     */
    private const PROFILES = [
        'lossy' => [32 * self::MIB, 31],
        'lossy_slow' => [32 * self::MIB, 51],
        'lossless' => [48 * self::MIB, 33],
        'lossless_slow' => [48 * self::MIB, 57],
    ];

    private const BYTES_PER_INPUT_PIXEL = 8;

    private const RESIZE_BYTES = 16;

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
