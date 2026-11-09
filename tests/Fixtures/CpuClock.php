<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Fixtures;

/**
 * Times a piece of code in the CPU time of this process (user and system, from getrusage()), which other processes
 * on a busy machine do not add to, and in wall-clock time as well, for a loose bound that catches a wait.
 *
 * The time bounds of the inspector tests use measure(): the best of several runs of each, so that one run slowed by
 * a page fault, a cache miss or a CPU migration does not fail a bound that the code itself meets. Where getrusage()
 * is missing, the CPU time is the wall-clock time.
 */
final class CpuClock
{
    /**
     * Runs $code $runs times, or until a run is within both bounds.
     *
     * @param callable(): void $code
     *
     * @return array{0: float, 1: float} the least CPU seconds and the least wall-clock seconds of the runs
     */
    public static function measure(callable $code, float $maxCpuSeconds, float $maxWallSeconds, int $runs = 3): array
    {
        $cpu = INF;
        $wall = INF;
        for ($run = 0; $run < $runs && ($cpu >= $maxCpuSeconds || $wall >= $maxWallSeconds); ++$run) {
            $cpuStart = self::cpuSeconds();
            $wallStart = microtime(true);
            $code();
            $wall = min($wall, microtime(true) - $wallStart);
            $cpu = min($cpu, self::cpuSeconds() - $cpuStart);
        }

        return [$cpu, $wall];
    }

    /**
     * The CPU seconds this process has used.
     */
    public static function cpuSeconds(): float
    {
        $usage = function_exists('getrusage') ? getrusage() : false;
        $seconds = 0.0;
        foreach (['ru_utime.tv_sec' => 1.0, 'ru_utime.tv_usec' => 1e-6, 'ru_stime.tv_sec' => 1.0, 'ru_stime.tv_usec' => 1e-6] as $field => $unit) {
            if (!is_array($usage) || !isset($usage[$field]) || !is_int($usage[$field])) {
                return microtime(true);
            }
            $seconds += $usage[$field] * $unit;
        }

        return $seconds;
    }
}
