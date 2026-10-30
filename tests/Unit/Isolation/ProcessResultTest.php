<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Isolation\ProcessResult
 */
#[CoversClass(ProcessResult::class)]
final class ProcessResultTest extends TestCase
{
    public function testExitedProcess(): void
    {
        $result = new ProcessResult(0, null, false, 0.0123, "out\n", "err\n", false, true, 'rlimit');

        self::assertSame(0, $result->getExitCode());
        self::assertNull($result->getSignal());
        self::assertFalse($result->isTimedOut());
        self::assertTrue($result->isSuccessful());
        self::assertSame(0.0123, $result->getDurationSeconds());
        self::assertSame(13, $result->getDurationMs());
        self::assertSame("out\n", $result->getStdout());
        self::assertSame("err\n", $result->getStderr());
        self::assertFalse($result->isStdoutTruncated());
        self::assertTrue($result->isStderrTruncated());
        self::assertSame('rlimit', $result->getIsolationLevel());
    }

    public function testSignaledOrTimedOutProcessIsNotSuccessful(): void
    {
        $killed = new ProcessResult(null, 9, true, 1.5, '', '', false, false, 'process');
        self::assertNull($killed->getExitCode());
        self::assertSame(9, $killed->getSignal());
        self::assertTrue($killed->isTimedOut());
        self::assertFalse($killed->isSuccessful());
        self::assertSame(1500, $killed->getDurationMs());

        self::assertFalse((new ProcessResult(1, null, false, 0.0, '', '', false, false, 'none'))->isSuccessful());
        self::assertFalse((new ProcessResult(0, null, true, 0.0, '', '', false, false, 'none'))->isSuccessful());
    }

    /**
     * @return array<string, array{int|null, int|null, float, string}>
     */
    public static function invalidResults(): array
    {
        return [
            'neither exit code nor signal' => [null, null, 0.0, 'none'],
            'both exit code and signal' => [0, 9, 0.0, 'none'],
            'negative exit code' => [-1, null, 0.0, 'none'],
            'exit code above 255' => [256, null, 0.0, 'none'],
            'signal 0' => [null, 0, 0.0, 'none'],
            'signal above 64' => [null, 65, 0.0, 'none'],
            'negative duration' => [0, null, -0.001, 'none'],
            'NaN duration' => [0, null, NAN, 'none'],
            'infinite duration' => [0, null, INF, 'none'],
            'unknown level' => [0, null, 0.0, 'chroot'],
        ];
    }

    /**
     * @dataProvider invalidResults
     */
    #[DataProvider('invalidResults')]
    public function testInvalidValuesAreRejected(?int $exitCode, ?int $signal, float $duration, string $level): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcessResult($exitCode, $signal, false, $duration, '', '', false, false, $level);
    }
}
