<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Isolation;

use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\DetectionResult;
use Mfonte\SecureImageConverter\Isolation\IsolationDecision;
use Mfonte\SecureImageConverter\Isolation\IsolationPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Isolation\IsolationDecision
 * @covers \Mfonte\SecureImageConverter\Isolation\IsolationPolicy
 */
#[CoversClass(IsolationPolicy::class)]
#[CoversClass(IsolationDecision::class)]
final class IsolationPolicyTest extends TestCase
{
    /**
     * A detection result for each level, with the reasons the detector would give.
     */
    private static function detection(string $level): DetectionResult
    {
        $reasons = [
            'process' => 'proc_open() is disabled',
            'rlimit' => 'prlimit is not available (not found, not executable or disabled)',
            'sandbox' => 'bubblewrap (bwrap) failed to run a test command (exit code 1)',
        ];
        $applicable = [];
        foreach ($reasons as $candidate => $reason) {
            if (!IsolationLevel::isAtLeast($level, $candidate)) {
                $applicable[$candidate] = $reason;
            }
        }

        return new DetectionResult($level, $applicable, 1700000000);
    }

    public function testFactories(): void
    {
        $strict = IsolationPolicy::strict(IsolationLevel::RLIMIT);
        self::assertSame(IsolationPolicy::STRICT, $strict->getMode());
        self::assertSame('rlimit', $strict->getLevel());
        self::assertTrue($strict->isStrict());

        $bestEffort = IsolationPolicy::bestEffort();
        self::assertSame(IsolationPolicy::BEST_EFFORT, $bestEffort->getMode());
        self::assertSame('sandbox', $bestEffort->getLevel());
        self::assertFalse($bestEffort->isStrict());

        self::assertEquals($strict, IsolationPolicy::create('strict', 'rlimit'));
        self::assertEquals(IsolationPolicy::bestEffort('process'), IsolationPolicy::create('best_effort', 'process'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidPolicies(): array
    {
        return [
            'unknown mode' => ['lenient', 'rlimit'],
            'mode in upper case' => ['STRICT', 'rlimit'],
            'unknown level' => ['strict', 'chroot'],
            'level in upper case' => ['best_effort', 'SANDBOX'],
        ];
    }

    /**
     * @dataProvider invalidPolicies
     */
    #[DataProvider('invalidPolicies')]
    public function testInvalidPoliciesAreRejected(string $mode, string $level): void
    {
        $this->expectException(InvalidArgumentException::class);

        IsolationPolicy::create($mode, $level);
    }

    /**
     * Every policy, every detected level.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function combinations(): array
    {
        $combinations = [];
        foreach ([IsolationPolicy::STRICT, IsolationPolicy::BEST_EFFORT] as $mode) {
            foreach (IsolationLevel::all() as $configured) {
                foreach (IsolationLevel::all() as $detected) {
                    $combinations[sprintf('%s %s, %s detected', $mode, $configured, $detected)] = [$mode, $configured, $detected];
                }
            }
        }

        return $combinations;
    }

    /**
     * @dataProvider combinations
     */
    #[DataProvider('combinations')]
    public function testResolve(string $mode, string $configured, string $detected): void
    {
        $policy = IsolationPolicy::create($mode, $configured);
        $satisfied = IsolationLevel::isAtLeast($detected, $configured);

        if (!$satisfied && $mode === IsolationPolicy::STRICT) {
            try {
                $policy->resolve(self::detection($detected));
                self::fail('A strict policy must refuse a weaker level.');
            } catch (IsolationUnavailableException $exception) {
                self::assertSame(ErrorCode::ISOLATION_UNAVAILABLE, $exception->getErrorCode());
                self::assertStringContainsString(sprintf('requires the %s level', $configured), $exception->getMessage());
                self::assertStringContainsString(sprintf('provides only %s', $detected), $exception->getMessage());
                self::assertStringContainsString((string) self::detection($detected)->getReason(), $exception->getMessage());
            }

            return;
        }

        $decision = $policy->resolve(self::detection($detected));
        self::assertSame($mode, $decision->getMode());
        self::assertSame($configured, $decision->getConfiguredLevel());
        self::assertSame($detected, $decision->getLevel(), 'the strongest available level is used');
        self::assertSame(!$satisfied, $decision->isDegraded());
        if ($satisfied) {
            self::assertSame([], $decision->getWarnings());

            return;
        }
        self::assertCount(1, $decision->getWarnings());
        self::assertSame(
            sprintf(
                'Isolation degraded to the %s level, below the expected %s level: %s.',
                $detected,
                $configured,
                (string) self::detection($detected)->getReason()
            ),
            $decision->getWarnings()[0]
        );
    }

    public function testDegradationWarningsAreValidConversionResultWarnings(): void
    {
        $longest = new DetectionResult('none', [
            'process' => str_repeat('r', DetectionResult::REASON_MAX_LENGTH),
            'rlimit' => 'x',
            'sandbox' => 'x',
        ], 0);
        $decision = IsolationPolicy::bestEffort(IsolationLevel::SANDBOX)->resolve($longest);
        self::assertCount(1, $decision->getWarnings());

        $result = new ConversionResult(
            '/tmp/out.webp',
            'webp',
            1,
            1,
            1,
            1,
            'gd',
            0,
            $decision->getLevel(),
            false,
            $decision->getWarnings()
        );
        self::assertSame($decision->getWarnings(), $result->getWarnings());
        self::assertSame('none', $result->getIsolationLevel());
    }

    public function testDecisionValidatesItsValues(): void
    {
        $decision = new IsolationDecision('best_effort', 'sandbox', 'rlimit', ['Isolation degraded.']);
        self::assertTrue($decision->isDegraded());
        self::assertFalse((new IsolationDecision('strict', 'process', 'sandbox', []))->isDegraded());

        $invalid = [
            static function (): IsolationDecision {
                return new IsolationDecision('lenient', 'sandbox', 'sandbox', []);
            },
            static function (): IsolationDecision {
                return new IsolationDecision('strict', 'jail', 'sandbox', []);
            },
            static function (): IsolationDecision {
                return new IsolationDecision('strict', 'sandbox', 'jail', []);
            },
            static function (): IsolationDecision {
                return new IsolationDecision('best_effort', 'sandbox', 'none', ["line\nbreak"]);
            },
            static function (): IsolationDecision {
                return new IsolationDecision('best_effort', 'sandbox', 'none', ['']);
            },
            static function (): IsolationDecision {
                return new IsolationDecision('best_effort', 'sandbox', 'none', [str_repeat('w', ConversionResult::WARNING_MAX_LENGTH + 1)]);
            },
        ];
        foreach ($invalid as $index => $create) {
            try {
                $create();
                self::fail(sprintf('Invalid decision %d was accepted.', $index));
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INTERNAL_ERROR, $exception->getErrorCode());
            }
        }
    }
}
