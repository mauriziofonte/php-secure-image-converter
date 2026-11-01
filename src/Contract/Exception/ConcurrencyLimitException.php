<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * Every conversion slot is busy, or the lock of a key is held by another process, and nothing became free within the
 * configured wait time. The request can be retried later.
 *
 * getRetryAfterSeconds() tells the caller when to retry, in whole seconds, so that the API can send it as the value of
 * the Retry-After header. The Concurrency module (Semaphore and KeyedLock) creates the exception with afterWaiting(),
 * which derives the hint from how long the caller waited: the wait timeout rounded up to whole seconds, at least
 * MIN_RETRY_AFTER_SECONDS and at most MAX_RETRY_AFTER_SECONDS. A caller that found every slot busy for T seconds is
 * told to wait about T seconds before trying again: the slots did not turn over within T, so an earlier retry would
 * most likely be refused again, and a client that waits as long as the server did keeps the load bounded. An instance
 * created with the constructor reports MIN_RETRY_AFTER_SECONDS.
 *
 * Error code ErrorCode::CONCURRENCY_LIMIT; expected HTTP status in the API: 503 Service Unavailable, with a
 * Retry-After header carrying getRetryAfterSeconds().
 */
final class ConcurrencyLimitException extends \OverflowException implements ExceptionInterface
{
    /**
     * Smallest hint, also reported by instances created with the constructor. A Retry-After of 0 would invite an
     * immediate retry of a request that was just refused for lack of capacity.
     */
    public const MIN_RETRY_AFTER_SECONDS = 1;

    /**
     * Largest hint: one hour. Waits longer than that say more about the configuration than about the load.
     */
    public const MAX_RETRY_AFTER_SECONDS = 3600;

    /**
     * @var int
     */
    private $retryAfterSeconds = self::MIN_RETRY_AFTER_SECONDS;

    /**
     * Creates the exception for a caller that waited $waitedSeconds without getting a slot or a lock, with the
     * retry hint derived from that wait: ceil($waitedSeconds), clamped to MIN_RETRY_AFTER_SECONDS ..
     * MAX_RETRY_AFTER_SECONDS. A non-blocking attempt (0 seconds) therefore suggests 1 second, a 2.5 second wait 3
     * seconds.
     *
     * @param float  $waitedSeconds how long the caller waited (its wait timeout), finite and not negative
     * @param string $message       human-readable description, without file paths or raw input
     *
     * @throws InvalidArgumentException when $waitedSeconds is negative, infinite or NaN
     */
    public static function afterWaiting(float $waitedSeconds, string $message): self
    {
        if (!is_finite($waitedSeconds) || $waitedSeconds < 0.0) {
            throw new InvalidArgumentException('The wait time must be a finite number of seconds, not negative.');
        }

        $exception = new self($message);
        $exception->retryAfterSeconds = (int) min(
            (float) self::MAX_RETRY_AFTER_SECONDS,
            max((float) self::MIN_RETRY_AFTER_SECONDS, ceil($waitedSeconds))
        );

        return $exception;
    }

    public function getErrorCode(): string
    {
        return ErrorCode::CONCURRENCY_LIMIT;
    }

    /**
     * When to retry, in whole seconds from now: from MIN_RETRY_AFTER_SECONDS to MAX_RETRY_AFTER_SECONDS. Suitable
     * as the value of an HTTP Retry-After header.
     */
    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
