<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Backend\AvifencBackend;
use Mfonte\SecureImageConverter\Backend\BackendFactory;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Backend\CwebpBackend;
use Mfonte\SecureImageConverter\Backend\GdBackend;
use Mfonte\SecureImageConverter\Backend\Gif2webpBackend;
use Mfonte\SecureImageConverter\Backend\ImagickBackend;
use Mfonte\SecureImageConverter\Backend\VipsBackend;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Tests\Unit\Backend\Fake\FakeProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Backend\BackendFactory
 */
#[CoversClass(BackendFactory::class)]
final class BackendFactoryTest extends TestCase
{
    public function testEveryBackendIsCreatedInOrderOfPreference(): void
    {
        $backends = BackendFactory::createAll(new FakeProcessRunner());

        self::assertSame(
            [VipsBackend::class, CwebpBackend::class, Gif2webpBackend::class, AvifencBackend::class, ImagickBackend::class, GdBackend::class],
            array_map('get_class', $backends)
        );
        foreach ($backends as $backend) {
            self::assertTrue(Capabilities::isValidBackendName($backend->getName()));
        }
        self::assertSame(BackendFactory::NAMES, array_map(static function ($backend): string {
            return $backend->getName();
        }, $backends));
    }

    public function testASubsetKeepsTheOrderOfPreference(): void
    {
        $resolver = BackendFactory::createResolver(
            new FakeProcessRunner(),
            (new BackendOptions())->withMemoryCeilingBytes(268435456),
            ['gd', 'vips']
        );

        self::assertSame(['vips', 'gd'], array_map(static function ($backend): string {
            return $backend->getName();
        }, $resolver->getBackends()));
        self::assertSame(268435456, $resolver->getMemoryCeilingBytes());
    }

    public function testNamesAreParsed(): void
    {
        self::assertSame(['vips', 'gd'], BackendFactory::parseNames(' gd , VIPS,gd,'));
        self::assertSame(['imagick'], BackendFactory::parseNames('imagick'));
    }

    public function testAnEmptyListIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BackendFactory::parseNames(' , ');
    }

    public function testAnUnknownNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown backend; the backends are: vips, cwebp, gif2webp, avifenc, imagick, gd.');

        BackendFactory::parseNames('vips,convert');
    }
}
