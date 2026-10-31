<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;

/**
 * libavif's avifenc: still PNG and JPEG to AVIF, without resize, on one thread (-j 1), 8 bits per channel.
 *
 * | Setting  | Mapping                                                                                  |
 * | -------- | ---------------------------------------------------------------------------------------- |
 * | quality  | -q (libavif 1.0 and later), or the equivalent quantizer with --min and --max             |
 * | effort   | -s: speed 10 (effort 0) to 1 (efforts 8 and 9; speed 0 is over twice as slow again)     |
 * | lossless | -l                                                                                       |
 * | metadata | --ignore-exif, --ignore-xmp and --ignore-icc as requested                                |
 *
 * Lossy output is 4:2:0. avifenc copies an EXIF orientation into the AVIF transform boxes only when it keeps EXIF.
 */
final class AvifencBackend extends AbstractBackend
{
    public const NAME = 'avifenc';

    /**
     * Fixed address space and bytes per decoded pixel, lossy and lossless. Measured in the sandbox on RGBA noise
     * (the costliest content): 71 and 127 MiB at 1 and 4 megapixels, 95 and 215 MiB lossless; rounded up.
     */
    private const BASE_BYTES = 83886080;

    private const BYTES_PER_INPUT_PIXEL = 32;

    private const LOSSLESS_BYTES_PER_INPUT_PIXEL = 48;

    public function getName(): string
    {
        return self::NAME;
    }

    public function estimateMemoryBytes(BackendJob $job): int
    {
        return self::addressSpace(
            self::BASE_BYTES,
            $job->needsLosslessMode() ? self::LOSSLESS_BYTES_PER_INPUT_PIXEL : self::BYTES_PER_INPUT_PIXEL,
            $job->getInputPixels(),
            0,
            0
        );
    }

    protected function programs(ExecutableFinder $finder): array
    {
        return ['avifenc' => $finder->find('avifenc')];
    }

    protected function discover(array $programs, WorkingDirectory $directory): array
    {
        $help = $this->execute([$programs['avifenc'], '--help'], $directory, self::BASE_BYTES);
        $text = $help->getStdout() . $help->getStderr();
        if (strpos($text, 'avifenc') === false) {
            return [self::FACT_UNAVAILABLE => 'avifenc --help failed'];
        }

        return [
            'qcolor' => strpos($text, '--qcolor') !== false ? '1' : '0',
        ];
    }

    protected function candidates(array $facts): array
    {
        $candidates = self::noCandidates();
        $candidates['inputs'] = [ImageFormat::JPEG, ImageFormat::PNG];
        $candidates['outputs'] = [ImageFormat::AVIF];
        $candidates['lossless'] = [ImageFormat::AVIF];
        $candidates['icc_profile'] = true;
        $candidates['metadata'] = true;

        return $candidates;
    }

    protected function command(BackendJob $job, string $input, string $output, array $programs, array $facts): array
    {
        $speed = max(1, 10 - (int) round($job->getEffort() * 10 / 9));
        $command = [$programs['avifenc'], '-j', '1', '-s', (string) $speed, '-d', '8'];
        if ($job->needsLosslessMode()) {
            $command[] = '-l';
        } elseif (($facts['qcolor'] ?? '0') === '1') {
            array_push($command, '-q', (string) $job->getQuality(), '-y', '420');
        } else {
            $quantizer = (string) (int) round((100 - $job->getQuality()) * 63 / 99);
            array_push($command, '--min', $quantizer, '--max', $quantizer, '-y', '420');
        }
        if ($job->shouldStripMetadata()) {
            array_push($command, '--ignore-exif', '--ignore-xmp');
            if (!$job->shouldKeepIccProfile()) {
                $command[] = '--ignore-icc';
            }
        }
        array_push($command, $input, $output);

        return $command;
    }
}
