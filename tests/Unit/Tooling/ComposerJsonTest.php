<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Guards the package definition invariants required by the plan (section 4) and conventions.md: PHP range, the
 * single runtime dependency, test tooling capped at PHPUnit 11, no platform pinning, autoloading and script names.
 *
 * @coversNothing
 */
#[CoversNothing]
final class ComposerJsonTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private static function composerJson(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/composer.json');
        self::assertIsString($contents);
        $data = json_decode($contents, true);
        self::assertIsArray($data, 'composer.json must be valid JSON');

        return $data;
    }

    /**
     * @return array<mixed>
     */
    private static function section(string $name): array
    {
        $data = self::composerJson();
        self::assertArrayHasKey($name, $data);
        self::assertIsArray($data[$name]);

        return $data[$name];
    }

    public function testPackageIdentity(): void
    {
        $data = self::composerJson();

        self::assertSame('mfonte/secure-image-converter', $data['name']);
        self::assertSame('library', $data['type']);
        self::assertSame('BSD-3-Clause', $data['license']);
        self::assertSame('stable', $data['minimum-stability']);
        self::assertTrue($data['prefer-stable']);
    }

    public function testPhpRangeIsSevenTwoFiveToEightX(): void
    {
        self::assertSame('^7.2.5 || ^8.0', self::section('require')['php']);
    }

    public function testPsrLogIsTheOnlyRuntimePackage(): void
    {
        $packages = [];
        foreach (self::section('require') as $name => $constraint) {
            self::assertIsString($name);
            if ($name !== 'php' && strpos($name, 'ext-') !== 0) {
                $packages[$name] = $constraint;
            }
        }

        self::assertSame(['psr/log' => '^1.1 || ^2.0 || ^3.0'], $packages);
    }

    public function testDevelopmentRequirementsAreOnlyPhpunitAndPolyfills(): void
    {
        $requireDev = self::section('require-dev');

        self::assertSame(['phpunit/phpunit', 'yoast/phpunit-polyfills'], array_keys($requireDev));
    }

    public function testPhpunitIsCappedAtMajorEleven(): void
    {
        $constraint = self::section('require-dev')['phpunit/phpunit'];
        self::assertIsString($constraint);
        preg_match_all('/\^(\d+)\./', $constraint, $matches);

        self::assertSame(['8', '9', '10', '11'], $matches[1]);
    }

    public function testPlatformIsNeverPinned(): void
    {
        $config = self::section('config');

        self::assertArrayNotHasKey('platform', $config);
        self::assertTrue($config['sort-packages']);
        self::assertTrue($config['optimize-autoloader']);
    }

    public function testAutoloadMapsTheRootNamespaces(): void
    {
        self::assertSame(['psr-4' => ['Mfonte\\SecureImageConverter\\' => 'src/']], self::section('autoload'));
        self::assertSame(['psr-4' => ['Mfonte\\SecureImageConverter\\Tests\\' => 'tests/']], self::section('autoload-dev'));
    }

    public function testSharedScriptNamesExist(): void
    {
        $scripts = self::section('scripts');

        foreach (['test', 'analyse', 'cs:check', 'cs:fix', 'ci'] as $name) {
            self::assertArrayHasKey($name, $scripts, sprintf('Composer script "%s" is missing', $name));
        }
        self::assertSame(['@cs:check', '@analyse', '@test'], $scripts['ci']);
        self::assertArrayNotHasKey('taint', $scripts, 'Psalm taint analysis is configured in the proxy and the API only');
    }
}
