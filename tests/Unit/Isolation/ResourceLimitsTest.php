<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Isolation\ResourceLimits
 */
#[CoversClass(ResourceLimits::class)]
final class ResourceLimitsTest extends TestCase
{
    public function testDefaults(): void
    {
        $limits = new ResourceLimits();

        self::assertSame(1073741824, $limits->getAddressSpaceBytes());
        self::assertSame(60, $limits->getCpuSeconds());
        self::assertSame(268435456, $limits->getFileSizeBytes());
        self::assertSame(128, $limits->getOpenFiles());
        self::assertNull($limits->getProcesses());
        self::assertSame(
            ['--as=1073741824', '--cpu=60', '--fsize=268435456', '--nofile=128', '--core=0'],
            $limits->toPrlimitArguments()
        );
        self::assertSame([
            'address_space_bytes' => 1073741824,
            'cpu_seconds' => 60,
            'file_size_bytes' => 268435456,
            'open_files' => 128,
            'processes' => null,
        ], $limits->toArray());
    }

    public function testWithersReturnModifiedCopies(): void
    {
        $defaults = new ResourceLimits();
        $limits = $defaults
            ->withAddressSpaceBytes(268435456)
            ->withCpuSeconds(5)
            ->withFileSizeBytes(1048576)
            ->withOpenFiles(32)
            ->withProcesses(64);

        self::assertSame(
            ['--as=268435456', '--cpu=5', '--fsize=1048576', '--nofile=32', '--core=0', '--nproc=64'],
            $limits->toPrlimitArguments()
        );
        self::assertSame(64, $limits->getProcesses());
        self::assertNull($limits->withProcesses(null)->getProcesses());
        self::assertSame(1073741824, $defaults->getAddressSpaceBytes(), 'the original is unchanged');
        self::assertNull($defaults->getProcesses());
    }

    public function testBoundariesAreAccepted(): void
    {
        $limits = (new ResourceLimits())
            ->withAddressSpaceBytes(ResourceLimits::MIN_ADDRESS_SPACE_BYTES)
            ->withCpuSeconds(1)
            ->withFileSizeBytes(1)
            ->withOpenFiles(ResourceLimits::MIN_OPEN_FILES)
            ->withProcesses(1);
        self::assertSame(ResourceLimits::MIN_ADDRESS_SPACE_BYTES, $limits->getAddressSpaceBytes());

        $limits = (new ResourceLimits())
            ->withAddressSpaceBytes(ResourceLimits::MAX_BYTES)
            ->withCpuSeconds(ResourceLimits::MAX_CPU_SECONDS)
            ->withFileSizeBytes(ResourceLimits::MAX_BYTES)
            ->withOpenFiles(ResourceLimits::MAX_COUNT)
            ->withProcesses(ResourceLimits::MAX_COUNT);
        self::assertSame(ResourceLimits::MAX_COUNT, $limits->getProcesses());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function outOfRangeValues(): array
    {
        return [
            'address space below 16 MiB' => ['withAddressSpaceBytes', ResourceLimits::MIN_ADDRESS_SPACE_BYTES - 1],
            'address space above 1 TiB' => ['withAddressSpaceBytes', ResourceLimits::MAX_BYTES + 1],
            'cpu 0' => ['withCpuSeconds', 0],
            'cpu above one day' => ['withCpuSeconds', ResourceLimits::MAX_CPU_SECONDS + 1],
            'file size 0' => ['withFileSizeBytes', 0],
            'file size above 1 TiB' => ['withFileSizeBytes', ResourceLimits::MAX_BYTES + 1],
            'open files below 8' => ['withOpenFiles', ResourceLimits::MIN_OPEN_FILES - 1],
            'open files above max' => ['withOpenFiles', ResourceLimits::MAX_COUNT + 1],
            'processes 0' => ['withProcesses', 0],
            'processes negative' => ['withProcesses', -1],
            'processes above max' => ['withProcesses', ResourceLimits::MAX_COUNT + 1],
        ];
    }

    /**
     * @dataProvider outOfRangeValues
     */
    #[DataProvider('outOfRangeValues')]
    public function testOutOfRangeValuesAreRejected(string $method, int $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $callable = [new ResourceLimits(), $method];
        self::assertIsCallable($callable);
        $callable($value);
    }
}
