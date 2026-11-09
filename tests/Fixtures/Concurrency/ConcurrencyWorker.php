<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency;

use Mfonte\SecureImageConverter\Concurrency\Backoff;
use Mfonte\SecureImageConverter\Concurrency\KeyedLock;
use Mfonte\SecureImageConverter\Concurrency\LeaseInterface;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Concurrency\Semaphore;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;

/**
 * One worker process of the concurrency integration tests, run by worker.php (next to this file) and started by
 * WorkerPool with proc_open(): one process per simulated request.
 *
 * Options (a JSON object, the only command-line argument of worker.php):
 * - mode: "semaphore" acquires a Semaphore slot, "keyed" the KeyedLock of "key", "unlocked" nothing (the negative
 *   control of the log analysis); each then logs "start", holds for holdMs, logs "end" and releases.
 *   "hold-semaphore" and "hold-keyed" acquire, print their outcome and sleep until killed. "directory" only opens
 *   the lock directory. "keyed-stress" and "semaphore-stress" acquire and release "iterations" times in a row as
 *   fast as possible, with the exact overlap detector described at stress().
 * - id, lockDir, name, slots, key, timeout (seconds), holdMs: as their names say.
 * - stress modes: iterations, markerDir, holdUs (microseconds inside the marker, default 0), backoffInitial and
 *   backoffMax (Backoff delays in seconds).
 * - log: the shared event log, appended under an exclusive flock() of the log file itself, independent of the
 *   classes under test.
 * - ready, go: start barrier; the worker creates "ready" once loaded, then waits until "go" exists.
 *
 * The worker prints one JSON line: {"outcome": "acquired"} (exit status 0), {"outcome": "stress", "iterations":
 * <int>, "violations": <int>} after a stress run (exit status 0), or {"outcome": "limit", "retryAfter": <int>,
 * "waited": <seconds>} after a ConcurrencyLimitException (exit status 3). Any other failure is written to STDERR with
 * exit status 1.
 */
final class ConcurrencyWorker
{
    public const EXIT_ACQUIRED = 0;
    public const EXIT_FAILED = 1;
    public const EXIT_LIMIT = 3;

    /**
     * @var array<string, mixed>
     */
    private $options;

    /**
     * @param array<string, mixed> $options
     */
    private function __construct(array $options)
    {
        $this->options = $options;
    }

    /**
     * Entry point of worker.php.
     *
     * @param list<string> $arguments command-line arguments, script name first
     *
     * @return int exit status
     */
    public static function main(array $arguments): int
    {
        try {
            $options = json_decode($arguments[1] ?? '', true);
            if (!\is_array($options)) {
                throw new \InvalidArgumentException('Usage: php worker.php <JSON object>');
            }
            $worker = new self([]);
            foreach ($options as $name => $value) {
                $worker->options[(string) $name] = $value;
            }

            return $worker->run();
        } catch (\Throwable $exception) {
            fwrite(STDERR, \get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");

            return self::EXIT_FAILED;
        }
    }

    private function run(): int
    {
        $mode = $this->string('mode');
        $directory = new LockDirectory($this->string('lockDir'));
        if ($mode === 'directory') {
            self::report(['outcome' => 'directory', 'posix' => \function_exists('posix_geteuid')]);

            return self::EXIT_ACQUIRED;
        }
        if ($mode === 'keyed-stress' || $mode === 'semaphore-stress') {
            $this->waitForStartSignal();

            return $this->stress($mode, $directory);
        }

        $this->waitForStartSignal();
        $started = microtime(true);
        try {
            $lease = $this->acquire($mode, $directory);
        } catch (ConcurrencyLimitException $exception) {
            self::report([
                'outcome' => 'limit',
                'retryAfter' => $exception->getRetryAfterSeconds(),
                'waited' => microtime(true) - $started,
            ]);

            return self::EXIT_LIMIT;
        }

        if ($mode === 'hold-semaphore' || $mode === 'hold-keyed') {
            self::report(['outcome' => 'acquired']);
            for (;;) {
                sleep(60);
            }
        }

        $key = isset($this->options['key']) ? $this->string('key') : '-';
        $this->log(sprintf('start %d %s', (int) $this->number('id'), $key));
        usleep((int) ($this->number('holdMs') * 1000));
        $this->log(sprintf('end %d %s', (int) $this->number('id'), $key));
        if ($lease !== null) {
            $lease->release();
        }
        self::report(['outcome' => 'acquired']);

        return self::EXIT_ACQUIRED;
    }

    /**
     * Acquires and releases "iterations" times in a row. While it holds the lease, the worker claims a marker
     * directory in markerDir with mkdir(), which is atomic and fails when the marker already exists, and removes the
     * marker before it releases. keyed-stress uses one marker, semaphore-stress one per slot, tried in order.
     *
     * A holder that finds every marker claimed counts a violation, and that can only happen when more holders than
     * slots are inside at the same time: each marker it found claimed belonged to a holder that was inside together
     * with it (markers are removed before release), and a holder claims a later marker only after finding the
     * earlier ones claimed by holders inside with it. The detector therefore has no false positives, and it misses
     * only overlaps shorter than one mkdir().
     */
    private function stress(string $mode, LockDirectory $directory): int
    {
        $backoff = new Backoff($this->number('backoffInitial'), $this->number('backoffMax'));
        $timeout = $this->number('timeout');
        if ($mode === 'keyed-stress') {
            $markers = 1;
            $lock = new KeyedLock($directory, $this->string('name'), $backoff);
            $key = $this->string('key');
            $acquire = static function () use ($lock, $key, $timeout): LeaseInterface {
                return $lock->acquire($key, $timeout);
            };
        } else {
            $markers = (int) $this->number('slots');
            $semaphore = new Semaphore($directory, $this->string('name'), $markers, $backoff);
            $acquire = static function () use ($semaphore, $timeout): LeaseInterface {
                return $semaphore->acquire($timeout);
            };
        }
        $markerDirectory = $this->string('markerDir');
        $holdUs = isset($this->options['holdUs']) ? (int) $this->number('holdUs') : 0;
        $iterations = (int) $this->number('iterations');

        $violations = 0;
        for ($iteration = 0; $iteration < $iterations; ++$iteration) {
            $lease = $acquire();
            $claimed = null;
            for ($marker = 0; $marker < $markers && $claimed === null; ++$marker) {
                $path = sprintf('%s/marker-%d', $markerDirectory, $marker);
                if (@mkdir($path)) {
                    $claimed = $path;
                }
            }
            if ($claimed === null) {
                ++$violations;
            } else {
                if ($holdUs > 0) {
                    usleep($holdUs);
                }
                rmdir($claimed);
            }
            $lease->release();
        }
        self::report(['outcome' => 'stress', 'iterations' => $iterations, 'violations' => $violations]);

        return self::EXIT_ACQUIRED;
    }

    private function acquire(string $mode, LockDirectory $directory): ?LeaseInterface
    {
        switch ($mode) {
            case 'semaphore':
            case 'hold-semaphore':
                $semaphore = new Semaphore($directory, $this->string('name'), (int) $this->number('slots'));

                return $semaphore->acquire($this->number('timeout'));
            case 'keyed':
            case 'hold-keyed':
                $lock = new KeyedLock($directory, $this->string('name'));

                return $lock->acquire($this->string('key'), $this->number('timeout'));
            case 'unlocked':
                return null;
        }

        throw new \InvalidArgumentException(sprintf('Unknown mode "%s".', $mode));
    }

    /**
     * Creates the ready file, then waits until the go file exists (30 seconds at most). Does nothing without them.
     */
    private function waitForStartSignal(): void
    {
        if (!isset($this->options['ready'], $this->options['go'])) {
            return;
        }
        touch($this->string('ready'));
        $go = $this->string('go');
        $deadline = microtime(true) + 30.0;
        while (!file_exists($go)) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('The start signal never came.');
            }
            usleep(1000);
            clearstatcache(true, $go);
        }
    }

    /**
     * Appends a line to the shared event log under an exclusive flock() of the log file.
     */
    private function log(string $line): void
    {
        $handle = fopen($this->string('log'), 'a');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the event log.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock the event log.');
            }
            fwrite($handle, $line . "\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function report(array $result): void
    {
        fwrite(STDOUT, json_encode($result) . "\n");
    }

    private function string(string $name): string
    {
        $value = $this->options[$name] ?? null;
        if (!\is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Option "%s" must be a string.', $name));
        }

        return $value;
    }

    private function number(string $name): float
    {
        $value = $this->options[$name] ?? null;
        if (!\is_int($value) && !\is_float($value)) {
            throw new \InvalidArgumentException(sprintf('Option "%s" must be a number.', $name));
        }

        return (float) $value;
    }
}
