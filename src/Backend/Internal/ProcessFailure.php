<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Internal;

use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;

/**
 * Maps a failed run to the library exception that says why it failed.
 *
 * | How the process ended                                              | Exception                  | Code               |
 * | ------------------------------------------------------------------ | -------------------------- | ------------------ |
 * | wall-clock timeout                                                 | ConversionTimeoutException | conversion_timeout |
 * | SIGXCPU or SIGKILL without a timeout (CPU limit, cgroup OOM kill)  | ResourceLimitException     | resource_limit     |
 * | SIGXFSZ (file size limit)                                          | ResourceLimitException     | resource_limit     |
 * | an exit or crash whose error output reports memory exhaustion      | ResourceLimitException     | resource_limit     |
 * | exit code WORKER_RESOURCE_LIMIT_EXIT of a PHP worker                | ResourceLimitException     | resource_limit     |
 * | any other exit code or signal                                      | ConversionFailedException  | conversion_failed  |
 *
 * AbstractBackend::convert() turns the last row into InvalidImageException (invalid_image) when the backend still
 * converts its probe image of the input's format: the program is sound, the input is what it failed on.
 *
 * Under the address space limit an allocation fails: the program reports it and exits (ENOMEM, "out of memory",
 * "failed to allocate"), or aborts (GLib's g_error() raises SIGTRAP or SIGABRT after "failed to allocate"), or
 * crashes without a word (SIGSEGV). A tight limit also stops the dynamic loader ("failed to map segment") and GLib's
 * thread pool ("Error creating thread"), and both count as a resource limit. A crash without such a message is
 * reported as a failure, not as a resource limit: it may as well be a decoder bug. In the sandbox, bubblewrap
 * reports a signal death as exit code 128 plus the signal number, and both forms are recognised.
 *
 * Messages name the backend and the cause, never a path or the program's own output.
 *
 * @internal
 */
final class ProcessFailure
{
    /**
     * Exit code with which a PHP worker reports that its library refused the job for a resource limit.
     */
    public const WORKER_RESOURCE_LIMIT_EXIT = 3;

    private const SIGKILL = 9;

    private const SIGXCPU = 24;

    private const SIGXFSZ = 25;

    /**
     * Error output that reports memory exhaustion, in lower case.
     */
    private const MEMORY_PATTERNS = [
        'out of memory',
        'cannot allocate memory',
        'failed to allocate',
        'memory allocation failed',
        'unable to allocate',
        'bad_alloc',
        'enomem',
        'memoryallocationfailed',
        'cache resources exhausted',
        'resourcelimiterror',
        'exceeds limit',
        'failed to map segment',
        'error creating thread',
    ];

    /**
     * The exception for a run that did not succeed.
     *
     * @return ConversionFailedException|ConversionTimeoutException|ResourceLimitException
     */
    public static function exception(string $backend, ProcessResult $result, bool $worker = false): ExceptionInterface
    {
        if ($result->isTimedOut()) {
            return new ConversionTimeoutException(sprintf('The %s conversion exceeded its time limit.', $backend));
        }
        $signal = self::signal($result);
        if ($signal === self::SIGXCPU || $signal === self::SIGKILL) {
            return new ResourceLimitException(sprintf(
                'The %s conversion was killed (signal %d): it exceeded its CPU time or memory budget.',
                $backend,
                $signal
            ));
        }
        if ($signal === self::SIGXFSZ) {
            return new ResourceLimitException(sprintf('The %s conversion exceeded its file size budget.', $backend));
        }
        if ($worker && $signal === null && $result->getExitCode() === self::WORKER_RESOURCE_LIMIT_EXIT) {
            return new ResourceLimitException(sprintf('The %s conversion exceeded its memory or pixel budget.', $backend));
        }
        if (self::reportsMemoryExhaustion($result->getStderr() . "\n" . $result->getStdout())) {
            return new ResourceLimitException(sprintf('The %s conversion ran out of memory.', $backend));
        }
        if ($signal !== null) {
            return new ConversionFailedException(sprintf('The %s conversion crashed (signal %d).', $backend, $signal));
        }

        return new ConversionFailedException(sprintf(
            'The %s conversion failed (exit code %d).',
            $backend,
            (int) $result->getExitCode()
        ));
    }

    /**
     * The signal that ended the process, also when bubblewrap reported it as an exit code.
     */
    public static function signal(ProcessResult $result): ?int
    {
        if ($result->getSignal() !== null) {
            return $result->getSignal();
        }
        $code = $result->getExitCode();
        if ($result->getIsolationLevel() === IsolationLevel::SANDBOX && $code !== null && $code > 128 && $code <= 192) {
            return $code - 128;
        }

        return null;
    }

    /**
     * Whether program output reports that an allocation failed.
     */
    public static function reportsMemoryExhaustion(string $output): bool
    {
        $output = strtolower($output);
        foreach (self::MEMORY_PATTERNS as $pattern) {
            if (strpos($output, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
