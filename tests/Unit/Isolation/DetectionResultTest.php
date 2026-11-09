<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Isolation\DetectionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Isolation\DetectionResult
 */
#[CoversClass(DetectionResult::class)]
final class DetectionResultTest extends TestCase
{
    private const REASONS = [
        'sandbox' => 'bubblewrap (bwrap) failed to run a test command (exit code 1)',
    ];

    public function testAccessors(): void
    {
        $result = new DetectionResult('rlimit', self::REASONS, 1700000000);

        self::assertSame('rlimit', $result->getLevel());
        self::assertSame(self::REASONS, $result->getReasons());
        self::assertSame(self::REASONS['sandbox'], $result->getReason());
        self::assertSame(1700000000, $result->getDetectedAt());
        self::assertFalse($result->isFromCache());
        self::assertTrue((new DetectionResult('rlimit', self::REASONS, 1, true))->isFromCache());
    }

    public function testReasonsAreOrderedFromTheWeakestLevel(): void
    {
        $result = new DetectionResult('none', [
            'sandbox' => 'it requires the rlimit level, which is unavailable',
            'rlimit' => 'it requires the process level, which is unavailable',
            'process' => 'proc_open() is disabled',
        ], 0);

        self::assertSame(['process', 'rlimit', 'sandbox'], array_keys($result->getReasons()));
        self::assertSame('proc_open() is disabled', $result->getReason());
    }

    public function testSandboxHasNoReason(): void
    {
        $result = new DetectionResult('sandbox', [], 0);

        self::assertSame([], $result->getReasons());
        self::assertNull($result->getReason());
    }

    public function testArrayRoundTrip(): void
    {
        $result = new DetectionResult('rlimit', self::REASONS, 1700000000);
        $array = $result->toArray();

        self::assertSame(['level' => 'rlimit', 'reasons' => self::REASONS, 'detected_at' => 1700000000], $array);
        self::assertEquals($result, DetectionResult::fromArray($array));
        self::assertTrue(DetectionResult::fromArray($array, true)->isFromCache());

        $decoded = json_decode((string) json_encode((new DetectionResult('sandbox', [], 5))->toArray()), true);
        self::assertIsArray($decoded);
        self::assertSame('sandbox', DetectionResult::fromArray($decoded)->getLevel());
    }

    /**
     * @return array<string, array{string, array<string, string>, int}>
     */
    public static function invalidResults(): array
    {
        return [
            'unknown level' => ['chroot', [], 0],
            'missing reason' => ['process', ['sandbox' => 'x'], 0],
            'reason for an available level' => ['rlimit', ['sandbox' => 'x', 'process' => 'x'], 0],
            'reason for an unknown level' => ['sandbox', ['jail' => 'x'], 0],
            'empty reason' => ['rlimit', ['sandbox' => ''], 0],
            'reason with a newline' => ['rlimit', ['sandbox' => "a\nb"], 0],
            'non-ASCII reason' => ['rlimit', ['sandbox' => "\u{e9}"], 0],
            'reason too long' => ['rlimit', ['sandbox' => str_repeat('a', 161)], 0],
            'negative time' => ['sandbox', [], -1],
        ];
    }

    /**
     * @dataProvider invalidResults
     *
     * @param array<string, string> $reasons
     */
    #[DataProvider('invalidResults')]
    public function testInvalidResultsAreRejected(string $level, array $reasons, int $detectedAt): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DetectionResult($level, $reasons, $detectedAt);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function invalidArrays(): array
    {
        return [
            'empty' => [[]],
            'missing key' => [['level' => 'sandbox', 'reasons' => []]],
            'extra key' => [['level' => 'sandbox', 'reasons' => [], 'detected_at' => 0, 'from_cache' => true]],
            'level not a string' => [['level' => 3, 'reasons' => [], 'detected_at' => 0]],
            'reasons not an array' => [['level' => 'sandbox', 'reasons' => 'none', 'detected_at' => 0]],
            'time not an integer' => [['level' => 'sandbox', 'reasons' => [], 'detected_at' => '0']],
            'reason not a string' => [['level' => 'rlimit', 'reasons' => ['sandbox' => 1], 'detected_at' => 0]],
            'reasons as a list' => [['level' => 'rlimit', 'reasons' => ['x'], 'detected_at' => 0]],
            'invalid level' => [['level' => 'root', 'reasons' => [], 'detected_at' => 0]],
        ];
    }

    /**
     * @dataProvider invalidArrays
     *
     * @param array<mixed> $data
     */
    #[DataProvider('invalidArrays')]
    public function testFromArrayIsStrict(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        DetectionResult::fromArray($data);
    }

    public function testIsValidReason(): void
    {
        self::assertTrue(DetectionResult::isValidReason('setsid is not available'));
        self::assertTrue(DetectionResult::isValidReason(str_repeat('a', DetectionResult::REASON_MAX_LENGTH)));
        self::assertFalse(DetectionResult::isValidReason(''));
        self::assertFalse(DetectionResult::isValidReason("tab\there"));
    }
}
