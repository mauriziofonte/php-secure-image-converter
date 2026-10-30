<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency;

/**
 * Reads the event log written by ConcurrencyWorker: one "start <id> <key>" line after a worker acquired, one
 * "end <id> <key>" line before it released, appended under an exclusive lock of the log file.
 *
 * A worker logs "start" only once it holds its slot and logs "end" before it releases it, and every line is written
 * under the lock of the log, at one instant. A worker counted between its "start" and its "end" when a line is written
 * therefore held its slot at that instant: the count never exceeds the number of real holders, so a correct
 * semaphore keeps its maximum at or below the number of slots, and a maximum above it proves that more workers held
 * a slot at once. Workers that do not lock anything (the negative control) show how high the count rises when nothing
 * limits them.
 */
final class EventLog
{
    /**
     * @var int
     */
    private $starts = 0;

    /**
     * @var int
     */
    private $ends = 0;

    /**
     * @var int
     */
    private $maxConcurrency = 0;

    /**
     * @var array<string, int> key => largest number of workers inside at the same time
     */
    private $maxConcurrencyPerKey = [];

    /**
     * @var list<int> ids of the workers that started
     */
    private $startedIds = [];

    /**
     * @throws \RuntimeException when the log is unreadable, malformed, or a worker ends without starting
     */
    public function __construct(string $path)
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('The event log cannot be read.');
        }

        $inside = 0;
        $insidePerKey = [];
        $open = [];
        foreach (explode("\n", rtrim($contents, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/\A(start|end) (\d+) (\S+)\z/', $line, $match) !== 1) {
                throw new \RuntimeException(sprintf('Malformed event log line "%s".', $line));
            }
            list(, $event, $id, $key) = $match;
            if (!isset($insidePerKey[$key])) {
                $insidePerKey[$key] = 0;
                $this->maxConcurrencyPerKey[$key] = 0;
            }
            if ($event === 'start') {
                if (isset($open[$id])) {
                    throw new \RuntimeException(sprintf('Worker %s started twice.', $id));
                }
                $open[$id] = true;
                ++$this->starts;
                $this->startedIds[] = (int) $id;
                ++$inside;
                ++$insidePerKey[$key];
                $this->maxConcurrency = max($this->maxConcurrency, $inside);
                $this->maxConcurrencyPerKey[$key] = max($this->maxConcurrencyPerKey[$key], $insidePerKey[$key]);
            } else {
                if (!isset($open[$id])) {
                    throw new \RuntimeException(sprintf('Worker %s ended without starting.', $id));
                }
                unset($open[$id]);
                ++$this->ends;
                --$inside;
                --$insidePerKey[$key];
            }
        }
    }

    public function getStarts(): int
    {
        return $this->starts;
    }

    public function getEnds(): int
    {
        return $this->ends;
    }

    /**
     * Largest number of workers between "start" and "end" at the same point of the log, over all keys.
     */
    public function getMaxConcurrency(): int
    {
        return $this->maxConcurrency;
    }

    /**
     * @return array<string, int> key => largest number of workers of that key between "start" and "end" at once
     */
    public function getMaxConcurrencyPerKey(): array
    {
        return $this->maxConcurrencyPerKey;
    }

    /**
     * @return list<int> ids of the workers that logged "start", in log order
     */
    public function getStartedIds(): array
    {
        return $this->startedIds;
    }
}
