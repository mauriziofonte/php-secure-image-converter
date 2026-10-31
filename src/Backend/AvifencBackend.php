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
     * Address space by profile (AbstractBackend::estimate()): fixed bytes and bytes per pixel (avifenc does not
     * resize). The peak address space (VmPeak, MiB) of the profile's costliest effort on RGBA noise at 1, 4, 6 and 20
     * megapixels, a 16-bit input in parentheses (it is encoded with 8 bits):
     *
     * | Profile                          | Fixed  | Per pixel | 1 MP    | 4 MP      | 6 MP | 20 MP |
     * | -------------------------------- | ------ | --------- | ------- | --------- | ---- | ----- |
     * | lossy, effort 0 to 4 (-s 6-10)   | 80 MiB | 27        | 65 (65) | 120 (118) |      | 456   |
     * | lossy, effort 5 to 9 (-s 1-4)    | 64 MiB | 46        | 69 (69) | 181 (181) |      |       |
     * | lossless, effort 0 to 4          | 80 MiB | 52        | 92 (90) | 221 (214) | 289  |       |
     * | lossless, effort 5 to 9          | 64 MiB | 72        | 94      | 273 (265) |      |       |
     *
     * Measured with libavif 1.0 and libaom 3.8 on one thread.
     */
    private const PROFILES = [
        'fast' => [80 * self::MIB, 27],
        'slow' => [64 * self::MIB, 46],
        'lossless' => [80 * self::MIB, 52],
        'lossless_slow' => [64 * self::MIB, 72],
    ];

    private const MIB = 1048576;

    public function getName(): string
    {
        return self::NAME;
    }

    public function estimateMemoryBytes(BackendJob $job): int
    {
        $fast = self::speed($job) >= 6;
        if ($job->needsLosslessMode()) {
            $profile = $fast ? 'lossless' : 'lossless_slow';
        } else {
            $profile = $fast ? 'fast' : 'slow';
        }
        list($fixed, $perPixel) = self::PROFILES[$profile];

        return self::estimate($job, $fixed, 0, $perPixel);
    }

    protected function programs(ExecutableFinder $finder): array
    {
        return ['avifenc' => $finder->find('avifenc')];
    }

    protected function discover(array $programs, WorkingDirectory $directory): array
    {
        $help = $this->execute([$programs['avifenc'], '--help'], $directory);
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
        $command = [$programs['avifenc'], '-j', '1', '-s', (string) self::speed($job), '-d', '8'];
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

    /**
     * The libavif speed of a job: 10 at effort 0 down to 1 at efforts 8 and 9.
     */
    private static function speed(BackendJob $job): int
    {
        return max(1, 10 - (int) round($job->getEffort() * 10 / 9));
    }
}
