<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency;

/**
 * Starts ConcurrencyWorker processes with proc_open() (real processes, not threads), releases them together through a
 * file barrier, and collects their exit status and output.
 *
 * Each worker runs "exec php worker.php <JSON options>", so the process that proc_open() reports, and that kill()
 * signals, is PHP itself rather than an intermediate shell. Standard output and error go to files in the work
 * directory, so a worker can never block on a full pipe.
 */
final class WorkerPool
{
    /**
     * @var string
     */
    private $workDirectory;

    /**
     * @var list<array{process: resource, stdout: string, stderr: string, exitCode: int|null, signal: int|null}>
     */
    private $workers = [];

    /**
     * @param string $workDirectory existing directory for the barrier files and the outputs of the workers
     */
    public function __construct(string $workDirectory)
    {
        $this->workDirectory = $workDirectory;
    }

    /**
     * Starts one worker.
     *
     * @param array<string, mixed>  $options     ConcurrencyWorker options; "ready" and "go" are added when $barrier
     * @param bool                  $barrier     whether the worker waits for releaseBarrier() before acquiring
     * @param array<string, string> $phpSettings php.ini settings passed with -d
     *
     * @return int worker index, from 0
     */
    public function start(array $options, bool $barrier = false, array $phpSettings = []): int
    {
        $index = \count($this->workers);
        if ($barrier) {
            $options['ready'] = sprintf('%s/ready-%d', $this->workDirectory, $index);
            $options['go'] = $this->workDirectory . '/go';
        }
        $stdout = sprintf('%s/stdout-%d', $this->workDirectory, $index);
        $stderr = sprintf('%s/stderr-%d', $this->workDirectory, $index);

        $command = 'exec ' . escapeshellarg(PHP_BINARY);
        foreach ($phpSettings as $name => $value) {
            $command .= ' -d ' . escapeshellarg($name . '=' . $value);
        }
        $command .= ' ' . escapeshellarg(__DIR__ . '/worker.php') . ' ' . escapeshellarg((string) json_encode($options));

        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
            $pipes
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('proc_open() failed.');
        }
        $this->workers[] = ['process' => $process, 'stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => null, 'signal' => null];

        return $index;
    }

    /**
     * Waits until every worker started with a barrier is loaded and waiting, then releases them all at once.
     *
     * @param int $count number of workers started with a barrier
     */
    public function releaseBarrier(int $count, float $timeout = 60.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (\count(glob($this->workDirectory . '/ready-*') ?: []) < $count) {
            $this->assertNoWorkerFailed();
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('The workers did not get ready in time.');
            }
            usleep(2000);
        }
        touch($this->workDirectory . '/go');
    }

    /**
     * Waits until the worker printed its outcome, and returns it.
     *
     * @return array<string, mixed>
     */
    public function waitForOutcome(int $index, float $timeout = 30.0): array
    {
        $deadline = microtime(true) + $timeout;
        while (($outcome = $this->outcome($index)) === null) {
            $this->assertNoWorkerFailed();
            if (microtime(true) > $deadline) {
                throw new \RuntimeException(sprintf('Worker %d printed nothing in time.', $index));
            }
            usleep(2000);
        }

        return $outcome;
    }

    /**
     * Waits until every worker has exited.
     */
    public function waitForAll(float $timeout = 120.0): void
    {
        $deadline = microtime(true) + $timeout;
        foreach (array_keys($this->workers) as $index) {
            while (!$this->hasExited($index)) {
                if (microtime(true) > $deadline) {
                    $this->killAll();

                    throw new \RuntimeException('The workers did not finish in time.');
                }
                usleep(2000);
            }
        }
    }

    /**
     * Kills the worker with SIGKILL, so that it cannot release anything itself, and waits until it is gone.
     */
    public function kill(int $index): void
    {
        if (!$this->hasExited($index)) {
            proc_terminate($this->workers[$index]['process'], 9);
        }
        $deadline = microtime(true) + 30.0;
        while (!$this->hasExited($index)) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException(sprintf('Worker %d survived SIGKILL.', $index));
            }
            usleep(1000);
        }
    }

    /**
     * Kills every worker that is still running and reaps all of them. Safe to call more than once.
     */
    public function killAll(): void
    {
        foreach (array_keys($this->workers) as $index) {
            if (!$this->hasExited($index)) {
                proc_terminate($this->workers[$index]['process'], 9);
            }
        }
        foreach (array_keys($this->workers) as $index) {
            $deadline = microtime(true) + 10.0;
            while (!$this->hasExited($index) && microtime(true) < $deadline) {
                usleep(1000);
            }
        }
    }

    /**
     * @return int number of workers started
     */
    public function count(): int
    {
        return \count($this->workers);
    }

    /**
     * Exit status of a worker that has exited, null while it runs or when a signal ended it.
     */
    public function exitCode(int $index): ?int
    {
        $this->hasExited($index);

        return $this->workers[$index]['exitCode'];
    }

    /**
     * Signal that ended the worker, null when it exited normally or still runs.
     */
    public function signal(int $index): ?int
    {
        $this->hasExited($index);

        return $this->workers[$index]['signal'];
    }

    /**
     * The worker's outcome line, decoded, or null when it printed none yet.
     *
     * @return array<string, mixed>|null
     */
    public function outcome(int $index): ?array
    {
        clearstatcache(true, $this->workers[$index]['stdout']);
        $contents = (string) @file_get_contents($this->workers[$index]['stdout']);
        if (strpos($contents, "\n") === false) {
            return null;
        }
        $outcome = json_decode(explode("\n", $contents, 2)[0], true);
        if (!\is_array($outcome)) {
            throw new \RuntimeException(sprintf('Worker %d printed something else than an outcome: %s', $index, $contents));
        }
        $result = [];
        foreach ($outcome as $name => $value) {
            $result[(string) $name] = $value;
        }

        return $result;
    }

    /**
     * Everything the worker wrote to its standard error.
     */
    public function errorOutput(int $index): string
    {
        return (string) @file_get_contents($this->workers[$index]['stderr']);
    }

    /**
     * Records the exit status the first time the worker is seen stopped: proc_get_status() reports it only once.
     */
    private function hasExited(int $index): bool
    {
        $worker = &$this->workers[$index];
        if ($worker['exitCode'] !== null || $worker['signal'] !== null) {
            return true;
        }
        list($running, $signal, $exitCode) = self::status(proc_get_status($worker['process']));
        if ($running) {
            return false;
        }
        if ($signal !== null) {
            $worker['signal'] = $signal;
        } else {
            $worker['exitCode'] = $exitCode;
        }
        proc_close($worker['process']);

        return true;
    }

    /**
     * Reads a proc_get_status() result, which is array|false on PHP 7 and always an array on PHP 8.
     *
     * @param mixed $status
     *
     * @return array{bool, int|null, int} running, terminating signal (null when none), exit status
     */
    private static function status($status): array
    {
        if (
            !\is_array($status) || !\is_bool($status['running'] ?? null) || !\is_bool($status['signaled'] ?? null)
            || !\is_int($status['termsig'] ?? null) || !\is_int($status['exitcode'] ?? null)
        ) {
            throw new \RuntimeException('The status of a worker cannot be read.');
        }

        return [$status['running'], $status['signaled'] ? $status['termsig'] : null, $status['exitcode']];
    }

    /**
     * Fails fast, with the worker's error output, when a worker exited with the generic failure status.
     */
    private function assertNoWorkerFailed(): void
    {
        foreach (array_keys($this->workers) as $index) {
            if ($this->hasExited($index) && $this->workers[$index]['exitCode'] === ConcurrencyWorker::EXIT_FAILED) {
                throw new \RuntimeException(sprintf('Worker %d failed: %s', $index, $this->errorOutput($index)));
            }
        }
    }
}
