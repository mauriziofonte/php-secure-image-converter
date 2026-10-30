<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Contract;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\ValidatedSerializationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Regression tests for instances created without their constructor.
 *
 * unserialize() of the "C:" format (meant for \Serializable classes) gives, on PHP 7.2 to 8.5, a mere warning and an
 * instance holding the property defaults, without calling __unserialize() or __wakeup(); so does
 * ReflectionClass::newInstanceWithoutConstructor(). A ConversionRequest created that way used to hold a null format.
 * Every value object revalidated by ValidatedSerializationTrait must therefore declare defaults that form a valid
 * instance.
 *
 * @covers \Mfonte\SecureImageConverter\Contract\Capabilities
 * @covers \Mfonte\SecureImageConverter\Contract\ConversionRequest
 * @covers \Mfonte\SecureImageConverter\Contract\ConversionResult
 * @covers \Mfonte\SecureImageConverter\Contract\ValidatedSerializationTrait
 */
#[CoversClass(Capabilities::class)]
#[CoversClass(ConversionRequest::class)]
#[CoversClass(ConversionResult::class)]
final class ConstructorlessInstanceTest extends TestCase
{
    /**
     * Each contract value object with the instance its property defaults must form.
     *
     * @return array<string, array{class-string, object}>
     */
    public static function contractValueObjects(): array
    {
        return [
            'ConversionRequest: the default request' => [ConversionRequest::class, new ConversionRequest()],
            'Capabilities: nothing supported, every limit 1' => [
                Capabilities::class,
                new Capabilities([], [], [], [], 'none', array_fill_keys(Capabilities::limitNames(), 1)),
            ],
            'ConversionResult: a one-pixel passthrough placeholder' => [
                ConversionResult::class,
                new ConversionResult('/dev/null', 'webp', 1, 1, 1, 1, ConversionResult::BACKEND_NONE, 0, 'none', true),
            ],
        ];
    }

    /**
     * @param class-string $class
     *
     * @dataProvider contractValueObjects
     */
    #[DataProvider('contractValueObjects')]
    public function testCustomFormatUnserializeOnlyWarnsAndYieldsTheDefaultInstance(string $class, object $expected): void
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $instance = unserialize(sprintf('C:%d:"%s":0:{}', strlen($class), $class), ['allowed_classes' => [$class]]);
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertStringContainsString('has no unserializer', $warnings[0]);
        self::assertInstanceOf($class, $instance);
        self::assertEquals($expected, $instance);
    }

    /**
     * @param class-string $class
     *
     * @dataProvider contractValueObjects
     */
    #[DataProvider('contractValueObjects')]
    public function testInstanceWithoutConstructorIsTheDefaultInstance(string $class, object $expected): void
    {
        self::assertEquals($expected, (new \ReflectionClass($class))->newInstanceWithoutConstructor());
    }

    /**
     * Every class of src/ that uses ValidatedSerializationTrait, found by scanning the source tree, so that a value
     * object added later is covered without editing this test.
     *
     * @return array<string, array{class-string}>
     */
    public static function revalidatedClasses(): array
    {
        $root = dirname(__DIR__, 3) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        $cases = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if (!is_string($contents) || preg_match('/^    use ValidatedSerializationTrait;$/m', $contents) !== 1) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'Mfonte\\SecureImageConverter\\' . str_replace('/', '\\', $relative);
            if (class_exists($class)) {
                $cases[$class] = [$class];
            }
        }
        ksort($cases);

        return $cases;
    }

    public function testTheScanFindsTheContractValueObjects(): void
    {
        $classes = array_keys(self::revalidatedClasses());

        foreach ([Capabilities::class, ConversionRequest::class, ConversionResult::class] as $class) {
            self::assertContains($class, $classes);
            self::assertContains(ValidatedSerializationTrait::class, class_uses($class));
        }
    }

    /**
     * The trait revalidates an unserialized instance through the class's public validation, so a round trip of an
     * instance created without its constructor proves that its property defaults form a valid instance.
     *
     * @param class-string $class
     *
     * @dataProvider revalidatedClasses
     */
    #[DataProvider('revalidatedClasses')]
    public function testPropertyDefaultsFormAValidInstance(string $class): void
    {
        $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        self::assertEquals($instance, unserialize(serialize($instance), ['allowed_classes' => [$class]]));
    }
}
