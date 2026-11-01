<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Contract\Exception;

use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException
 */
#[CoversClass(ConcurrencyLimitException::class)]
final class ConcurrencyLimitExceptionTest extends TestCase
{
    public function testConstructorReportsTheSmallestRetryHint(): void
    {
        $exception = new ConcurrencyLimitException('busy');

        self::assertSame(1, $exception->getRetryAfterSeconds());
        self::assertSame(ConcurrencyLimitException::MIN_RETRY_AFTER_SECONDS, $exception->getRetryAfterSeconds());
        self::assertSame(ErrorCode::CONCURRENCY_LIMIT, $exception->getErrorCode());
        self::assertSame('busy', $exception->getMessage());
    }

    /**
     * @return array<string, array{float, int}>
     */
    public static function waits(): array
    {
        return [
            'non-blocking attempt' => [0.0, 1],
            'short wait' => [0.2, 1],
            'one second' => [1.0, 1],
            'just above one second' => [1.001, 2],
            'fractional wait is rounded up' => [2.5, 3],
            'ten seconds' => [10.0, 10],
            'one hour' => [3600.0, 3600],
            'above one hour is capped' => [3600.5, 3600],
            'far above the cap' => [1.0e30, 3600],
        ];
    }

    /**
     * @dataProvider waits
     */
    #[DataProvider('waits')]
    public function testRetryHintIsTheWaitRoundedUpAndClamped(float $waitedSeconds, int $expected): void
    {
        $exception = ConcurrencyLimitException::afterWaiting($waitedSeconds, 'All slots are busy.');

        self::assertSame($expected, $exception->getRetryAfterSeconds());
        self::assertSame('All slots are busy.', $exception->getMessage());
        self::assertSame(ErrorCode::CONCURRENCY_LIMIT, $exception->getErrorCode());
    }

    /**
     * @return array<string, array{float}>
     */
    public static function invalidWaits(): array
    {
        return [
            'negative' => [-0.001],
            'infinite' => [INF],
            'not a number' => [NAN],
        ];
    }

    /**
     * @dataProvider invalidWaits
     */
    #[DataProvider('invalidWaits')]
    public function testInvalidWaitIsAProgrammingError(float $waitedSeconds): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConcurrencyLimitException::afterWaiting($waitedSeconds, 'busy');
    }
}
