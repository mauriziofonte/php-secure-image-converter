<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Concurrency;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * Bounded waiting for Semaphore and KeyedLock: exponential backoff with jitter between non-blocking attempts, up to a
 * deadline.
 *
 * flock() cannot wait with a timeout, so a waiting caller polls with LOCK_NB instead. The n-th pause (from 0) is drawn
 * uniformly from [d / 2, d] where d = min(maxDelay, initialDelay * 2^n): pauses grow quickly while a slot is busy,
 * never exceed maxDelay, and the jitter keeps waiting processes from polling in lockstep. maxDelay is also the longest
 * time a waiter can stay unaware that the lock was released, so it bounds the extra latency of a waiter.
 *
 * Time is measured with the monotonic clock (hrtime()) on PHP 7.3 and later, with microtime() on PHP 7.2.
 */
final class Backoff
{
    /**
     * Default first pause, in seconds.
     */
    public const DEFAULT_INITIAL_DELAY = 0.002;

    /**
     * Default longest pause, in seconds.
     */
    public const DEFAULT_MAX_DELAY = 0.05;

    /**
     * Smallest accepted pause, in seconds (10 microseconds).
     */
    public const MIN_DELAY = 0.00001;

    /**
     * Largest accepted pause, in seconds.
     */
    public const MAX_DELAY = 5.0;

    /**
     * Longest accepted timeout, in seconds (one day).
     */
    public const MAX_TIMEOUT = 86400.0;

    /**
     * @var float
     */
    private $initialDelay;

    /**
     * @var float
     */
    private $maxDelay;

    /**
     * @param float $initialDelay first pause in seconds, from MIN_DELAY to $maxDelay
     * @param float $maxDelay     longest pause in seconds, from $initialDelay to MAX_DELAY
     *
     * @throws InvalidArgumentException when a delay is out of range
     */
    public function __construct(float $initialDelay = self::DEFAULT_INITIAL_DELAY, float $maxDelay = self::DEFAULT_MAX_DELAY)
    {
        if (!($initialDelay >= self::MIN_DELAY && $initialDelay <= $maxDelay && $maxDelay <= self::MAX_DELAY)) {
            throw new InvalidArgumentException(sprintf(
                'Backoff delays must satisfy %s <= initial <= max <= %s seconds.',
                self::MIN_DELAY,
                self::MAX_DELAY
            ));
        }

        $this->initialDelay = $initialDelay;
        $this->maxDelay = $maxDelay;
    }

    /**
     * First pause, in seconds.
     */
    public function getInitialDelay(): float
    {
        return $this->initialDelay;
    }

    /**
     * Longest pause, in seconds.
     */
    public function getMaxDelay(): float
    {
        return $this->maxDelay;
    }

    /**
     * Pause before the attempt that follows failed attempt number $attempt (from 0), in seconds, jitter included:
     * uniformly in [d / 2, d] where d = min(maxDelay, initialDelay * 2^$attempt).
     *
     * @param int $attempt number of the attempt that just failed, from 0
     */
    public function delay(int $attempt): float
    {
        $ceiling = min($this->maxDelay, $this->initialDelay * 2 ** max(0, min($attempt, 40)));

        return $ceiling / 2 * (1 + random_int(0, 1000000) / 1000000);
    }

    /**
     * Calls $attempt until it returns an object or $timeout seconds have passed, pausing between attempts as
     * described in the class documentation. $attempt is called at least once, and once more at the deadline when
     * the pauses reach it, so a timeout of 0 makes exactly one attempt. Exceptions thrown by $attempt are not caught.
     *
     * @template T of object
     *
     * @param callable(): (T|null) $attempt non-blocking attempt; null means "not yet"
     * @param float                $timeout longest wait in seconds, from 0 to MAX_TIMEOUT
     *
     * @throws InvalidArgumentException when the timeout is out of range
     *
     * @return T|null the first object returned by $attempt, or null when the timeout expired first
     */
    public function retryUntil(callable $attempt, float $timeout): ?object
    {
        self::assertTimeout($timeout);
        $deadline = self::now() + $timeout;

        for ($failed = 0; ; ++$failed) {
            $result = $attempt();
            if ($result !== null) {
                return $result;
            }

            $remaining = $deadline - self::now();
            if ($remaining <= 0.0) {
                return null;
            }
            usleep(max(1, (int) ceil(min($this->delay($failed), $remaining) * 1000000)));
        }
    }

    /**
     * Checks a wait timeout.
     *
     * @param float $timeout seconds, from 0 to MAX_TIMEOUT
     *
     * @throws InvalidArgumentException when the timeout is negative, above MAX_TIMEOUT or NaN
     */
    public static function assertTimeout(float $timeout): void
    {
        if (!($timeout >= 0.0 && $timeout <= self::MAX_TIMEOUT)) {
            throw new InvalidArgumentException(sprintf(
                'The wait timeout must be from 0 to %d seconds.',
                (int) self::MAX_TIMEOUT
            ));
        }
    }

    /**
     * Seconds on a monotonic clock where available; only differences are meaningful.
     */
    private static function now(): float
    {
        if (\function_exists('hrtime')) {
            // An int on 64-bit PHP, which the package requires.
            $nanoseconds = hrtime(true);
            if (\is_int($nanoseconds)) {
                return $nanoseconds / 1.0e9;
            }
        }

        return microtime(true);
    }
}
