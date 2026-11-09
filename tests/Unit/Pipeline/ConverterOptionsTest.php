<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Pipeline;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\ProcessingBudget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Pipeline\ConverterOptions
 */
#[CoversClass(ConverterOptions::class)]
final class ConverterOptionsTest extends TestCase
{
    public function testTheDefaults(): void
    {
        $options = new ConverterOptions();

        self::assertNull($options->getWorkingDirectoryParent());
        self::assertSame(ConverterOptions::DEFAULT_SLOT_WAIT_SECONDS, $options->getSlotWaitSeconds());
        self::assertSame(ResourceLimits::DEFAULT_FILE_SIZE_BYTES, $options->getMaxOutputBytes());
        self::assertEquals(new ProcessingBudget(), $options->getBudget());
        self::assertSame(IsolationLevel::NONE, $options->getIsolationLevel());
        self::assertSame([], $options->getIsolationWarnings());
    }

    public function testEveryOptionIsSetOnACopy(): void
    {
        $budget = (new ProcessingBudget())->withScale(2.0);
        $options = new ConverterOptions();
        $changed = $options->withWorkingDirectoryParent('/var/tmp')->withSlotWaitSeconds(0.0)->withMaxOutputBytes(1024)
            ->withBudget($budget)->withIsolation(IsolationLevel::RLIMIT, ['Isolation is rlimit, below sandbox.']);

        self::assertSame('/var/tmp', $changed->getWorkingDirectoryParent());
        self::assertSame(0.0, $changed->getSlotWaitSeconds());
        self::assertSame(1024, $changed->getMaxOutputBytes());
        self::assertSame($budget, $changed->getBudget());
        self::assertSame(IsolationLevel::RLIMIT, $changed->getIsolationLevel());
        self::assertSame(['Isolation is rlimit, below sandbox.'], $changed->getIsolationWarnings());
        self::assertEquals(new ConverterOptions(), $options);
        self::assertNull($changed->withWorkingDirectoryParent(null)->getWorkingDirectoryParent());
    }

    /**
     * @return array<string, array{callable(ConverterOptions): ConverterOptions}>
     */
    public static function invalidOptions(): array
    {
        return [
            'relative parent' => [static function (ConverterOptions $options): ConverterOptions {
                return $options->withWorkingDirectoryParent('tmp');
            }],
            'parent with dot segments' => [static function (ConverterOptions $options): ConverterOptions {
                return $options->withWorkingDirectoryParent('/tmp/../etc');
            }],
            'negative slot wait' => [static function (ConverterOptions $options): ConverterOptions {
                return $options->withSlotWaitSeconds(-1.0);
            }],
            'slot wait not a number' => [static function (ConverterOptions $options): ConverterOptions {
                return $options->withSlotWaitSeconds(NAN);
            }],
            'slot wait too long' => [static function (ConverterOptions $options): ConverterOptions {
                return $options->withSlotWaitSeconds(1.0e6);
            }],
            'no output bytes' => [static function (ConverterOptions $options): ConverterOptions {
                return $options->withMaxOutputBytes(0);
            }],
            'output bytes above the maximum' => [static function (ConverterOptions $options): ConverterOptions {
                return $options->withMaxOutputBytes(ResourceLimits::MAX_BYTES + 1);
            }],
            'unknown level' => [static function (ConverterOptions $options): ConverterOptions {
                return $options->withIsolation('chroot');
            }],
            'warning not a string' => [static function (ConverterOptions $options): ConverterOptions {
                return $options->withIsolation(IsolationLevel::NONE, [42]);
            }],
            'warning with a newline' => [static function (ConverterOptions $options): ConverterOptions {
                return $options->withIsolation(IsolationLevel::NONE, ["one\ntwo"]);
            }],
            'empty warning' => [static function (ConverterOptions $options): ConverterOptions {
                return $options->withIsolation(IsolationLevel::NONE, ['']);
            }],
            'warning too long' => [static function (ConverterOptions $options): ConverterOptions {
                return $options->withIsolation(IsolationLevel::NONE, [str_repeat('a', 257)]);
            }],
        ];
    }

    /**
     * @dataProvider invalidOptions
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRefused(callable $change): void
    {
        $this->expectException(InvalidArgumentException::class);

        $change(new ConverterOptions());
    }
}
