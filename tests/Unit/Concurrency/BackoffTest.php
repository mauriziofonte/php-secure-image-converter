<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Concurrency;

use Mfonte\SecureImageConverter\Concurrency\Backoff;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Concurrency\Backoff
 */
#[CoversClass(Backoff::class)]
final class BackoffTest extends TestCase
{
    public function testDefaults(): void
    {
        $backoff = new Backoff();

        self::assertSame(Backoff::DEFAULT_INITIAL_DELAY, $backoff->getInitialDelay());
        self::assertSame(Backoff::DEFAULT_MAX_DELAY, $backoff->getMaxDelay());
        self::assertSame(0.002, $backoff->getInitialDelay());
        self::assertSame(0.05, $backoff->getMaxDelay());
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function invalidDelays(): array
    {
        return [
            'zero initial delay' => [0.0, 0.05],
            'negative initial delay' => [-0.001, 0.05],
            'initial below the minimum' => [0.000001, 0.05],
            'initial above max' => [0.1, 0.05],
            'max above the maximum' => [0.01, 5.001],
            'not a number' => [NAN, 0.05],
            'infinite max' => [0.01, INF],
        ];
    }

    /**
     * @dataProvider invalidDelays
     */
    #[DataProvider('invalidDelays')]
    public function testInvalidDelaysAreRefused(float $initial, float $max): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Backoff($initial, $max);
    }

    public function testBoundaryDelaysAreAccepted(): void
    {
        $backoff = new Backoff(Backoff::MIN_DELAY, Backoff::MAX_DELAY);
        self::assertSame(0.00001, $backoff->getInitialDelay());
        self::assertSame(5.0, $backoff->getMaxDelay());

        $constant = new Backoff(0.01, 0.01);
        self::assertSame(0.01, $constant->getMaxDelay());
    }

    public function testDelaysGrowExponentiallyWithJitterUpToTheMaximum(): void
    {
        $backoff = new Backoff(0.001, 0.05);
        $distinct = [];

        for ($attempt = 0; $attempt <= 60; ++$attempt) {
            $ceiling = min(0.05, 0.001 * 2 ** $attempt);
            for ($sample = 0; $sample < 20; ++$sample) {
                $delay = $backoff->delay($attempt);
                self::assertGreaterThanOrEqual($ceiling / 2, $delay);
                self::assertLessThanOrEqual($ceiling * (1 + 1e-12), $delay);
                $distinct[(string) $delay] = true;
            }
        }

        self::assertGreaterThan(100, \count($distinct), 'the pauses are not jittered');
    }

    public function testNegativeAttemptCountsAsTheFirst(): void
    {
        $delay = (new Backoff(0.004, 0.05))->delay(-5);

        self::assertGreaterThanOrEqual(0.002, $delay);
        self::assertLessThanOrEqual(0.004, $delay);
    }

    public function testRetryUntilReturnsTheFirstObject(): void
    {
        $calls = 0;
        $result = (new Backoff(0.0001, 0.001))->retryUntil(static function () use (&$calls): ?\stdClass {
            ++$calls;

            return $calls === 3 ? new \stdClass() : null;
        }, 10.0);

        self::assertInstanceOf(\stdClass::class, $result);
        self::assertSame(3, $calls);
    }

    public function testZeroTimeoutMakesExactlyOneAttempt(): void
    {
        $calls = 0;
        $result = (new Backoff())->retryUntil(static function () use (&$calls): ?\stdClass {
            ++$calls;

            return null;
        }, 0.0);

        self::assertNull($result);
        self::assertSame(1, $calls);
    }

    public function testRetryUntilGivesUpAtTheDeadline(): void
    {
        $calls = 0;
        $started = microtime(true);
        $result = (new Backoff(0.001, 0.02))->retryUntil(static function () use (&$calls): ?\stdClass {
            ++$calls;

            return null;
        }, 0.2);
        $elapsed = microtime(true) - $started;

        self::assertNull($result);
        self::assertGreaterThanOrEqual(0.2, $elapsed);
        self::assertLessThan(1.5, $elapsed);
        // 0.2 seconds of pauses of at most 20 ms: several attempts, the last one at the deadline.
        self::assertGreaterThanOrEqual(10, $calls);
    }

    public function testExceptionsOfTheAttemptAreNotCaught(): void
    {
        $this->expectException(\LogicException::class);

        (new Backoff())->retryUntil(static function (): ?\stdClass {
            throw new \LogicException('attempt failed');
        }, 1.0);
    }

    /**
     * @return array<string, array{float}>
     */
    public static function invalidTimeouts(): array
    {
        return [
            'negative' => [-0.001],
            'above one day' => [86400.001],
            'infinite' => [INF],
            'not a number' => [NAN],
        ];
    }

    /**
     * @dataProvider invalidTimeouts
     */
    #[DataProvider('invalidTimeouts')]
    public function testInvalidTimeoutIsRefused(float $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The wait timeout must be from 0 to 86400 seconds.');

        (new Backoff())->retryUntil(static function (): \stdClass {
            return new \stdClass();
        }, $timeout);
    }

    public function testTimeoutBoundsAreAccepted(): void
    {
        Backoff::assertTimeout(0.0);
        Backoff::assertTimeout(Backoff::MAX_TIMEOUT);

        $this->expectException(InvalidArgumentException::class);
        Backoff::assertTimeout(-0.0001);
    }
}
