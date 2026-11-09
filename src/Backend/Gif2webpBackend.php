<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;

/**
 * libwebp's gif2webp: GIF, still or animated with every frame, to WEBP, without resize. It cannot convert the
 * first frame of an animation alone, so it only takes still GIFs and animations kept whole.
 *
 * | Setting  | Mapping                                                                                  |
 * | -------- | ---------------------------------------------------------------------------------------- |
 * | quality  | -mixed -q (each frame lossy or lossless, whichever is smaller); lossless: -q is effort   |
 * | effort   | -m 0 to 6 (scaled)                                                                       |
 * | metadata | -metadata none, icc or all (the default would copy XMP)                                  |
 *
 * It decodes one frame at a time and keeps only the canvas, the frame being encoded and the encoded output in
 * memory, so its estimate grows little with the frame count: it converts animations too large for vips under the
 * memory ceiling.
 */
final class Gif2webpBackend extends AbstractBackend
{
    public const NAME = 'gif2webp';

    /**
     * Address space by profile (AbstractBackend::estimate()): fixed bytes and bytes per pixel of one frame, plus
     * BYTES_PER_ANIMATION_PIXEL per pixel of every frame of an animation (the encoded output it keeps). The largest
     * peak address space (VmPeak, MiB) of tests/Fixtures/backend/memory-peaks.csv at each size of a still GIF, over
     * every content and effort of the profile:
     *
     * | Profile                       | Fixed  | Per pixel | 1 MP | 2.1 MP | 4 MP | 8.3 MP | 20 MP |
     * | ----------------------------- | ------ | --------- | ---- | ------ | ---- | ------ | ----- |
     * | lossy, effort 0 to 8 (-m 0-5) | 48 MiB | 76        | 50   | 127    | 233  | 484    | 884   |
     * | lossy, effort 9 (-m 6)        | 48 MiB | 96        | 77   | 155    | 271  | 595    |       |
     * | lossless, effort 0 to 7       | 64 MiB | 68        | 54   | 107    | 205  | 386    |       |
     * | lossless, effort 8 and 9      | 64 MiB | 84        | 82   | 164    | 270  | 465    | 1128  |
     *
     * Palette images with transparency need the most for lossy output, palette noise for lossless. An animation needs
     * little more than its first frame (2000 x 2000 pixels of palette noise at effort 4: 187 MiB for 6 frames, 163 for
     * one).
     */
    private const PROFILES = [
        'lossy' => [48 * self::MIB, 76],
        'lossy_slow' => [48 * self::MIB, 96],
        'lossless' => [64 * self::MIB, 68],
        'lossless_slow' => [64 * self::MIB, 84],
    ];

    private const BYTES_PER_ANIMATION_PIXEL = 2;

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

        return self::estimate($job, $fixed, 0, $perPixel, 0, self::BYTES_PER_ANIMATION_PIXEL);
    }

    protected function programs(ExecutableFinder $finder): array
    {
        return ['gif2webp' => $finder->find('gif2webp')];
    }

    protected function discover(array $programs, WorkingDirectory $directory): array
    {
        return [];
    }

    protected function candidates(array $facts): array
    {
        $candidates = self::noCandidates();
        $candidates['inputs'] = [ImageFormat::GIF];
        $candidates['outputs'] = [ImageFormat::WEBP];
        $candidates['animated_inputs'] = [ImageFormat::GIF];
        $candidates['animated_outputs'] = [ImageFormat::WEBP];
        $candidates['lossless'] = [ImageFormat::WEBP];

        return $candidates;
    }

    protected function command(BackendJob $job, string $input, string $output, array $programs, array $facts): array
    {
        $command = [$programs['gif2webp'], '-quiet'];
        if ($job->needsLosslessMode()) {
            array_push($command, '-q', (string) (int) round($job->getEffort() * 100 / 9));
        } else {
            array_push($command, '-mixed', '-q', (string) $job->getQuality());
        }
        array_push($command, '-m', (string) self::webpMethod($job));
        if (!$job->shouldStripMetadata()) {
            $metadata = 'all';
        } else {
            $metadata = $job->shouldKeepIccProfile() ? 'icc' : 'none';
        }
        array_push($command, '-metadata', $metadata, $input, '-o', $output);

        return $command;
    }
}
