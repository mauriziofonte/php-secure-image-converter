<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Backend\BackendResult;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Backend\BackendOptions
 * @covers \Mfonte\SecureImageConverter\Backend\BackendResult
 */
#[CoversClass(BackendOptions::class)]
#[CoversClass(BackendResult::class)]
final class BackendOptionsTest extends TestCase
{
    use BackendFixtures;

    public function testDefaults(): void
    {
        $options = new BackendOptions();

        self::assertSame(2147483648, $options->getMemoryCeilingBytes());
        self::assertNull($options->getWorkingDirectoryParent());
        self::assertNull($options->getPhpBinary());
        self::assertTrue($options->isCapabilityCacheEnabled());
        self::assertNull($options->getCapabilityCacheDirectory());
        self::assertSame(86400, $options->getCapabilityCacheTtlSeconds());
    }

    public function testWithersReturnCopies(): void
    {
        $options = new BackendOptions();
        $changed = $options->withMemoryCeilingBytes(536870912)
            ->withWorkingDirectoryParent('/var/tmp')
            ->withPhpBinary('/usr/bin/php8.3')
            ->withCapabilityCache(false, '/var/cache/sic')
            ->withCapabilityCacheTtlSeconds(60);

        self::assertSame(2147483648, $options->getMemoryCeilingBytes());
        self::assertSame(536870912, $changed->getMemoryCeilingBytes());
        self::assertSame('/var/tmp', $changed->getWorkingDirectoryParent());
        self::assertSame('/usr/bin/php8.3', $changed->getPhpBinary());
        self::assertFalse($changed->isCapabilityCacheEnabled());
        self::assertSame('/var/cache/sic', $changed->getCapabilityCacheDirectory());
        self::assertSame(60, $changed->getCapabilityCacheTtlSeconds());
    }

    /**
     * @return array<string, array{0: callable(BackendOptions): BackendOptions}>
     */
    public static function invalidOptions(): array
    {
        return [
            'ceiling too low' => [static function (BackendOptions $options) {
                return $options->withMemoryCeilingBytes(1048576);
            }],
            'relative parent' => [static function (BackendOptions $options) {
                return $options->withWorkingDirectoryParent('tmp');
            }],
            'php with dots' => [static function (BackendOptions $options) {
                return $options->withPhpBinary('/usr/bin/../bin/php');
            }],
            'ttl zero' => [static function (BackendOptions $options) {
                return $options->withCapabilityCacheTtlSeconds(0);
            }],
            'ttl above a week' => [static function (BackendOptions $options) {
                return $options->withCapabilityCacheTtlSeconds(604801);
            }],
        ];
    }

    /**
     * @dataProvider invalidOptions
     *
     * @param callable(BackendOptions): BackendOptions $change
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRejected(callable $change): void
    {
        $this->expectException(InvalidArgumentException::class);

        $change(new BackendOptions());
    }

    public function testAResultDescribesTheRun(): void
    {
        $job = self::job(self::info(ImageFormat::PNG), ImageFormat::WEBP);
        $result = new BackendResult('vips', $job, 12, IsolationLevel::SANDBOX);

        self::assertSame('vips', $result->getBackend());
        self::assertSame($job, $result->getJob());
        self::assertSame('/tmp/sic-test/out.webp', $result->getOutputPath());
        self::assertSame(ImageFormat::WEBP, $result->getOutputFormat());
        self::assertSame(12, $result->getDurationMs());
        self::assertSame(IsolationLevel::SANDBOX, $result->getIsolationLevel());

        $this->expectException(InvalidArgumentException::class);
        new BackendResult('vips', $job, -1, IsolationLevel::SANDBOX);
    }
}
