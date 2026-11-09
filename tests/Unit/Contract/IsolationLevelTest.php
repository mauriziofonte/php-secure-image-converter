<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Contract;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Contract\IsolationLevel
 */
#[CoversClass(IsolationLevel::class)]
final class IsolationLevelTest extends TestCase
{
    public function testLevelsAreOrderedFromWeakestToStrongest(): void
    {
        self::assertSame(['none', 'process', 'rlimit', 'sandbox'], IsolationLevel::all());
        self::assertSame(
            [IsolationLevel::NONE, IsolationLevel::PROCESS, IsolationLevel::RLIMIT, IsolationLevel::SANDBOX],
            IsolationLevel::all()
        );

        foreach (IsolationLevel::all() as $rank => $level) {
            self::assertTrue(IsolationLevel::isValid($level));
            self::assertSame($rank, IsolationLevel::rank($level));
        }
    }

    /**
     * Every ordered pair of levels.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function pairs(): array
    {
        $levels = ['none', 'process', 'rlimit', 'sandbox'];
        $pairs = [];
        foreach ($levels as $levelRank => $level) {
            foreach ($levels as $minimumRank => $minimum) {
                $pairs[$level . ' >= ' . $minimum] = [$level, $minimum, $levelRank >= $minimumRank];
            }
        }

        return $pairs;
    }

    /**
     * @dataProvider pairs
     */
    #[DataProvider('pairs')]
    public function testIsAtLeastFollowsTheOrdering(string $level, string $minimum, bool $expected): void
    {
        self::assertSame($expected, IsolationLevel::isAtLeast($level, $minimum));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownLevels(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['SANDBOX'],
            'unknown' => ['bwrap'],
        ];
    }

    /**
     * @dataProvider unknownLevels
     */
    #[DataProvider('unknownLevels')]
    public function testUnknownLevelIsInvalidAndHasNoRank(string $level): void
    {
        self::assertFalse(IsolationLevel::isValid($level));

        $this->expectException(InvalidArgumentException::class);
        IsolationLevel::rank($level);
    }

    /**
     * @dataProvider unknownLevels
     */
    #[DataProvider('unknownLevels')]
    public function testIsAtLeastRejectsUnknownMinimum(string $level): void
    {
        $this->expectException(InvalidArgumentException::class);

        IsolationLevel::isAtLeast(IsolationLevel::SANDBOX, $level);
    }

    /**
     * @dataProvider unknownLevels
     */
    #[DataProvider('unknownLevels')]
    public function testIsAtLeastRejectsUnknownLevel(string $level): void
    {
        $this->expectException(InvalidArgumentException::class);

        IsolationLevel::isAtLeast($level, IsolationLevel::NONE);
    }
}
