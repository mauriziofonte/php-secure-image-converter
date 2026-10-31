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
 * It keeps only the canvas and the frame being encoded in memory, so its estimate does not grow with the frame
 * count: it converts animations too large for vips under the memory ceiling.
 */
final class Gif2webpBackend extends AbstractBackend
{
    public const NAME = 'gif2webp';

    /**
     * Fixed address space and bytes per pixel of one frame, measured with prlimit (35 MiB for 30 frames of
     * 0.75 megapixels) and rounded up; plus a little per frame for the encoded output it keeps.
     */
    private const BASE_BYTES = 33554432;

    private const BYTES_PER_FRAME_PIXEL = 32;

    private const BYTES_PER_FRAME = 65536;

    public function getName(): string
    {
        return self::NAME;
    }

    public function estimateMemoryBytes(BackendJob $job): int
    {
        $input = $job->getInput();

        return self::BASE_BYTES
            + (int) min(PHP_INT_MAX / 4, self::BYTES_PER_FRAME_PIXEL * (float) $input->getWidth() * $input->getHeight())
            + (int) min(PHP_INT_MAX / 4, self::BYTES_PER_FRAME * (float) $job->getFrameCount());
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
        array_push($command, '-m', (string) (int) round($job->getEffort() * 6 / 9));
        if (!$job->shouldStripMetadata()) {
            $metadata = 'all';
        } else {
            $metadata = $job->shouldKeepIccProfile() ? 'icc' : 'none';
        }
        array_push($command, '-metadata', $metadata, $input, '-o', $output);

        return $command;
    }
}
