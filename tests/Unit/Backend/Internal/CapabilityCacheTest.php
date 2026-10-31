<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Internal;

use Mfonte\SecureImageConverter\Backend\BackendCapabilities;
use Mfonte\SecureImageConverter\Backend\Internal\CapabilityCache;
use Mfonte\SecureImageConverter\Backend\Internal\Facts;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\PrivateCacheDirectory;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\CapabilityCache
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\Facts
 */
#[CoversClass(CapabilityCache::class)]
#[CoversClass(Facts::class)]
final class CapabilityCacheTest extends TestCase
{
    /**
     * @var WorkingDirectory|null
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('The capability cache needs the posix extension.');
        }
        $this->directory = WorkingDirectory::create();
    }

    protected function tear_down(): void
    {
        if ($this->directory !== null) {
            $this->directory->remove();
        }
        parent::tear_down();
    }

    private function cache(int $ttl = 60): CapabilityCache
    {
        self::assertNotNull($this->directory);

        return new CapabilityCache(new PrivateCacheDirectory($this->directory->getPath()), $ttl);
    }

    private static function capabilities(): BackendCapabilities
    {
        return (new BackendCapabilities('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP]))->withIccProfile(true);
    }

    public function testAnEntryIsReadBackForTheSameFingerprint(): void
    {
        $cache = $this->cache();

        self::assertTrue($cache->store('cwebp', 'fingerprint', self::capabilities(), ['qcolor' => '1']));
        $entry = $cache->load('cwebp', 'fingerprint');

        self::assertNotNull($entry);
        self::assertSame(self::capabilities()->toArray(), $entry[0]->toArray());
        self::assertSame(['qcolor' => '1'], $entry[1]);
        self::assertNull($cache->load('cwebp', 'other fingerprint'));
        self::assertNull($cache->load('vips', 'fingerprint'));
    }

    public function testAnExpiredEntryIsIgnored(): void
    {
        $this->cache()->store('cwebp', 'fingerprint', self::capabilities(), []);

        self::assertNull($this->cache(-1)->load('cwebp', 'fingerprint'));
    }

    public function testATamperedEntryIsIgnored(): void
    {
        $cache = $this->cache();
        $cache->store('cwebp', 'fingerprint', self::capabilities(), []);
        self::assertNotNull($this->directory);
        $files = glob($this->directory->getPath() . '/backend-cwebp-*.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        $data = json_decode((string) file_get_contents($files[0]), true);
        self::assertIsArray($data);
        self::assertIsArray($data['capabilities']);
        $data['capabilities']['output_formats'] = ['svg'];
        file_put_contents($files[0], json_encode($data));

        self::assertNull($cache->load('cwebp', 'fingerprint'));
    }

    public function testADisabledCacheStoresNothing(): void
    {
        $cache = new CapabilityCache(null, 60);

        self::assertFalse($cache->store('cwebp', 'fingerprint', self::capabilities(), []));
        self::assertNull($cache->load('cwebp', 'fingerprint'));
    }

    public function testFactsAreValidated(): void
    {
        self::assertSame(['a' => '1', 'b' => 'x,y'], Facts::check(['b' => 'x,y', 'a' => '1']));
        $this->expectException(InvalidArgumentException::class);
        Facts::check(['Bad-Name' => '1']);
    }
}
