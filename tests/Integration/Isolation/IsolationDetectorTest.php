<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\DetectionResult;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\IsolationDetector;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessRunner;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use Mfonte\SecureImageConverter\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Detection by real probes, and the file cache and its defences.
 *
 * @covers \Mfonte\SecureImageConverter\Isolation\IsolationDetector
 *
 * @group isolation
 */
#[CoversClass(IsolationDetector::class)]
#[Group('isolation')]
final class IsolationDetectorTest extends IsolationTestCase
{
    private const PROBED = 'Isolation level detected: {level}.';

    private function cacheDirectory(): string
    {
        return $this->workingDirectory()->pathOf('cache');
    }

    private static function finder(): ExecutableFinder
    {
        return new ExecutableFinder(ProcessOptions::DEFAULT_SEARCH_PATH);
    }

    private static function program(string $name): string
    {
        $path = self::finder()->find($name);
        self::assertIsString($path, sprintf('%s is needed by this test', $name));

        return $path;
    }

    /**
     * The level reported on this host. In the CI job "isolation" SIC_EXPECTED_ISOLATION_LEVEL states what the host
     * provides (sandbox with bubblewrap usable, rlimit once user namespaces are disabled); elsewhere the level must
     * match the tools that are installed.
     */
    public function testReportsTheDetectedLevel(): void
    {
        $detection = (new IsolationDetector(null, null, 0))->detect();
        $level = $detection->getLevel();
        $expected = self::expectedLevel();

        if ($expected !== null) {
            self::assertSame($expected, $level, sprintf('Reasons: %s', json_encode($detection->getReasons())));

            return;
        }
        $finder = self::finder();
        if (ProcessRunner::isProcOpenAvailable() && function_exists('posix_kill') && $finder->find('setsid') !== null) {
            self::assertTrue(IsolationLevel::isAtLeast($level, IsolationLevel::PROCESS), (string) $detection->getReason());
            if ($finder->find('prlimit') !== null) {
                self::assertTrue(IsolationLevel::isAtLeast($level, IsolationLevel::RLIMIT), (string) $detection->getReason());
            }
        }
        if ($finder->find('bwrap') === null) {
            self::assertNotSame(IsolationLevel::SANDBOX, $level);
            self::assertSame(
                'bubblewrap (bwrap) is not available (not found, not executable or disabled)',
                $detection->getReasons()[IsolationLevel::SANDBOX]
            );
        }
        self::assertSame(array_slice(IsolationLevel::all(), IsolationLevel::rank($level) + 1), array_keys($detection->getReasons()));
        self::assertFalse($detection->isFromCache());
        self::assertEqualsWithDelta(time(), $detection->getDetectedAt(), 60);
    }

    public function testUnusableBubblewrapLowersTheLevel(): void
    {
        $this->requireLevel(IsolationLevel::RLIMIT);
        $options = (new ProcessOptions())->withToolPath(ProcessOptions::TOOL_BWRAP, self::program('false'));
        $detection = (new IsolationDetector($options, null, 0))->detect();

        self::assertSame(IsolationLevel::RLIMIT, $detection->getLevel());
        self::assertSame(
            ['sandbox' => 'bubblewrap (bwrap) failed to run a test command (exit code 1)'],
            $detection->getReasons()
        );
    }

    public function testMissingBubblewrapLowersTheLevel(): void
    {
        $this->requireLevel(IsolationLevel::RLIMIT);
        $options = (new ProcessOptions())->withToolPath(ProcessOptions::TOOL_BWRAP, null);
        $detection = (new IsolationDetector($options, null, 0))->detect();

        self::assertSame(IsolationLevel::RLIMIT, $detection->getLevel());
        self::assertSame(
            'bubblewrap (bwrap) is not available (not found, not executable or disabled)',
            $detection->getReason()
        );
    }

    public function testUnusablePrlimitLowersTheLevel(): void
    {
        $this->requireLevel(IsolationLevel::PROCESS);
        $options = (new ProcessOptions())->withToolPath(ProcessOptions::TOOL_PRLIMIT, self::program('false'));
        $detection = (new IsolationDetector($options, null, 0))->detect();

        self::assertSame(IsolationLevel::PROCESS, $detection->getLevel());
        self::assertSame([
            'rlimit' => 'prlimit failed to run a test command (exit code 1)',
            'sandbox' => 'it requires the rlimit level, which is unavailable',
        ], $detection->getReasons());
    }

    /**
     * A prlimit that runs the command without applying the limits must not be trusted: the probe reads the limits
     * back.
     */
    public function testAPrlimitThatDoesNotApplyTheLimitsIsDetected(): void
    {
        $this->requireLevel(IsolationLevel::PROCESS);
        $fake = $this->workingDirectory()->pathOf('fake-prlimit');
        file_put_contents($fake, "#!/bin/sh\nwhile [ \"\$1\" != \"--\" ]; do shift; done\nshift\nexec \"\$@\"\n");
        chmod($fake, 0700);
        $options = (new ProcessOptions())->withToolPath(ProcessOptions::TOOL_PRLIMIT, $fake);
        $detection = (new IsolationDetector($options, null, 0))->detect();

        self::assertSame(IsolationLevel::PROCESS, $detection->getLevel());
        self::assertStringStartsWith('prlimit did not apply the configured limits (', (string) $detection->getReason());
    }

    public function testMissingSetsidMeansNoProcessIsolation(): void
    {
        $options = (new ProcessOptions())->withToolPath(ProcessOptions::TOOL_SETSID, null);
        $detection = (new IsolationDetector($options, null, 0))->detect();

        self::assertSame(IsolationLevel::NONE, $detection->getLevel());
        self::assertStringStartsWith('setsid is not available', (string) $detection->getReason());
    }

    public function testDisabledProcOpenMeansNoIsolation(): void
    {
        $this->requireLevel(IsolationLevel::PROCESS);
        $autoloader = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $script = 'require $argv[1]; $detection = (new ' . IsolationDetector::class . '(null, null, 0))->detect();'
            . ' echo json_encode($detection->toArray());';
        $result = (new ProcessRunner(IsolationLevel::PROCESS))->run(
            [PHP_BINARY, '-d', 'disable_functions=proc_open', '-r', $script, $autoloader],
            $this->workingDirectory()
        );

        self::assertTrue($result->isSuccessful(), $result->getStderr());
        $data = json_decode($result->getStdout(), true);
        self::assertIsArray($data);
        $detection = DetectionResult::fromArray($data);
        self::assertSame(IsolationLevel::NONE, $detection->getLevel());
        self::assertSame('proc_open() is disabled', $detection->getReason());
    }

    public function testTheResultIsCachedInMemory(): void
    {
        $detector = new IsolationDetector(null, null, 0);

        self::assertSame($detector->detect(), $detector->detect());
    }

    public function testTheResultIsCachedInAFileSharedByDetectors(): void
    {
        $first = new IsolationDetector(null, $this->cacheDirectory());
        $probed = $first->detect();
        self::assertFalse($probed->isFromCache());

        self::assertSame(0700, fileperms($this->cacheDirectory()) & 0777);
        $files = glob($this->cacheDirectory() . '/isolation-*.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        self::assertSame(0600, fileperms($files[0]) & 0777);

        $logger = new MemoryLogger();
        $cached = (new IsolationDetector(null, $this->cacheDirectory(), 3600, $logger))->detect();
        self::assertTrue($cached->isFromCache());
        self::assertSame($probed->toArray(), $cached->toArray());
        self::assertSame([], $logger->getMessages('info'), 'nothing was probed');
        self::assertContains('Isolation level {level} read from the cache.', $logger->getMessages('debug'));
    }

    public function testRefreshProbesAgain(): void
    {
        (new IsolationDetector(null, $this->cacheDirectory()))->detect();
        $logger = new MemoryLogger();
        $detector = new IsolationDetector(null, $this->cacheDirectory(), 3600, $logger);

        self::assertTrue($detector->detect()->isFromCache());
        self::assertFalse($detector->refresh()->isFromCache());
        self::assertSame([self::PROBED], $logger->getMessages('info'));
    }

    public function testEachConfigurationHasItsOwnCacheEntry(): void
    {
        (new IsolationDetector(null, $this->cacheDirectory()))->detect();
        $options = (new ProcessOptions())->withResourceLimits((new ResourceLimits())->withOpenFiles(64));
        $logger = new MemoryLogger();
        $other = (new IsolationDetector($options, $this->cacheDirectory(), 3600, $logger))->detect();

        self::assertFalse($other->isFromCache(), 'other limits, other probes');
        self::assertSame([self::PROBED], $logger->getMessages('info'));
        $files = glob($this->cacheDirectory() . '/isolation-*.json');
        self::assertIsArray($files);
        self::assertCount(2, $files);
    }

    public function testATtlOfZeroDisablesTheFileCache(): void
    {
        (new IsolationDetector(null, $this->cacheDirectory(), 0))->detect();

        self::assertDirectoryDoesNotExist($this->cacheDirectory());
    }

    /**
     * Ways a cache file can be stale, corrupt or planted; each one makes the detector probe again.
     *
     * @return array<string, array{callable(string): void}>
     */
    public static function invalidCacheFiles(): array
    {
        $rewrite = static function (callable $change): callable {
            return static function (string $path) use ($change): void {
                /** @var callable(array<mixed>): array<mixed> $change */
                $data = json_decode((string) file_get_contents($path), true);
                self::assertIsArray($data);
                file_put_contents($path, (string) json_encode($change($data)));
            };
        };

        return [
            'expired' => [$rewrite(static function (array $data): array {
                self::assertIsArray($data['result']);
                $data['result']['detected_at'] = time() - 3601;

                return $data;
            })],
            'from the future' => [$rewrite(static function (array $data): array {
                self::assertIsArray($data['result']);
                $data['result']['detected_at'] = time() + 3600;

                return $data;
            })],
            'other key' => [$rewrite(static function (array $data): array {
                $data['key'] = str_repeat('0', 64);

                return $data;
            })],
            'other schema' => [$rewrite(static function (array $data): array {
                $data['schema'] = 2;

                return $data;
            })],
            'invalid level' => [$rewrite(static function (array $data): array {
                $data['result'] = ['level' => 'root', 'reasons' => [], 'detected_at' => time()];

                return $data;
            })],
            'claims sandbox but keeps reasons' => [$rewrite(static function (array $data): array {
                $data['result'] = ['level' => 'sandbox', 'reasons' => ['sandbox' => 'x'], 'detected_at' => time()];

                return $data;
            })],
            'not JSON' => [static function (string $path): void {
                file_put_contents($path, 'level=sandbox');
            }],
            'empty' => [static function (string $path): void {
                file_put_contents($path, '');
            }],
            'writable by others' => [static function (string $path): void {
                chmod($path, 0666);
            }],
            'symbolic link' => [static function (string $path): void {
                rename($path, $path . '.target');
                symlink($path . '.target', $path);
            }],
            'directory' => [static function (string $path): void {
                unlink($path);
                mkdir($path, 0700);
            }],
        ];
    }

    /**
     * @dataProvider invalidCacheFiles
     *
     * @param callable(string): void $tamper
     */
    #[DataProvider('invalidCacheFiles')]
    public function testInvalidCacheFilesAreIgnored(callable $tamper): void
    {
        (new IsolationDetector(null, $this->cacheDirectory()))->detect();
        $files = glob($this->cacheDirectory() . '/isolation-*.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        $tamper($files[0]);

        $logger = new MemoryLogger();
        $detection = (new IsolationDetector(null, $this->cacheDirectory(), 3600, $logger))->detect();

        self::assertFalse($detection->isFromCache());
        self::assertSame([self::PROBED], $logger->getMessages('info'));
    }

    public function testACacheDirectoryWritableByOthersIsNotUsed(): void
    {
        mkdir($this->cacheDirectory(), 0700);
        chmod($this->cacheDirectory(), 0777);
        (new IsolationDetector(null, $this->cacheDirectory()))->detect();
        $logger = new MemoryLogger();
        $detection = (new IsolationDetector(null, $this->cacheDirectory(), 3600, $logger))->detect();

        self::assertFalse($detection->isFromCache());
        self::assertSame([], glob($this->cacheDirectory() . '/*'));
        self::assertContains(
            'The isolation cache directory is not usable: it must be a directory owned by the current user and not '
            . 'writable by group or others.',
            $logger->getMessages('debug')
        );
    }

    public function testTheDefaultCacheDirectoryIsPrivateToTheUser(): void
    {
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('The file cache needs the posix extension.');
        }
        (new IsolationDetector())->detect();
        $directory = rtrim(sys_get_temp_dir(), '/') . '/secure-image-converter-' . posix_geteuid();

        self::assertDirectoryExists($directory);
        self::assertSame(posix_geteuid(), fileowner($directory));
        self::assertSame(0, fileperms($directory) & 0022);
        self::assertTrue((new IsolationDetector())->detect()->isFromCache());
    }

    /**
     * @return array<string, array{string|null, int}>
     */
    public static function invalidConstructorArguments(): array
    {
        return [
            'negative TTL' => [null, -1],
            'TTL above one week' => [null, IsolationDetector::MAX_CACHE_TTL_SECONDS + 1],
            'relative directory' => ['cache', 60],
            'directory with ..' => ['/tmp/../cache', 60],
        ];
    }

    /**
     * @dataProvider invalidConstructorArguments
     */
    #[DataProvider('invalidConstructorArguments')]
    public function testInvalidConstructorArgumentsAreRejected(?string $directory, int $ttl): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IsolationDetector(null, $directory, $ttl);
    }
}
