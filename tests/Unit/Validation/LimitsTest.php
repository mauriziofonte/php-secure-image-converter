<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Validation;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Tests\Unit\Contract\SerializedStateTrait;
use Mfonte\SecureImageConverter\Validation\Limits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Validation\Limits
 */
#[CoversClass(Limits::class)]
final class LimitsTest extends TestCase
{
    use SerializedStateTrait;

    private const DEFAULTS = [
        'max_bytes' => 20971520,
        'max_width' => 8192,
        'max_height' => 8192,
        'max_megapixels' => 40,
        'max_frames' => 300,
        'max_total_megapixels' => 400,
    ];

    public function testNewLimitsHoldTheDocumentedDefaults(): void
    {
        $limits = new Limits();

        self::assertSame(self::DEFAULTS, $limits->toArray());
        self::assertSame(20971520, $limits->getMaxBytes());
        self::assertSame(8192, $limits->getMaxWidth());
        self::assertSame(8192, $limits->getMaxHeight());
        self::assertSame(40, $limits->getMaxMegapixels());
        self::assertSame(300, $limits->getMaxFrames());
        self::assertSame(400, $limits->getMaxTotalMegapixels());
    }

    /**
     * The defaults are the example values of the Capabilities documentation, so that the two documents agree.
     */
    public function testDefaultsMatchTheCapabilitiesDocumentation(): void
    {
        $docComment = (new \ReflectionClass(Capabilities::class))->getDocComment();
        self::assertIsString($docComment);

        foreach (self::DEFAULTS as $name => $value) {
            self::assertStringContainsString(sprintf('"%s": %d', $name, $value), $docComment);
        }
    }

    public function testToArrayHasExactlyTheCapabilitiesLimitNamesInCanonicalOrder(): void
    {
        self::assertSame(Capabilities::limitNames(), array_keys((new Limits())->toArray()));
    }

    public function testCapabilitiesBuiltFromLimitsPublishThemAndReadBackEqual(): void
    {
        $limits = (new Limits())->withMaxBytes(1048576)->withMaxFrames(12)->withMaxTotalMegapixels(Capabilities::LIMIT_VALUE_MAX);
        $capabilities = new Capabilities(['jpeg'], ['webp'], [], ['vips'], 'rlimit', $limits->toArray());

        self::assertSame($limits->toArray(), $capabilities->getLimits());
        self::assertEquals($limits, Limits::fromCapabilities($capabilities));
        self::assertEquals($limits, Limits::fromCapabilities(Capabilities::fromArray($capabilities->toArray())));
    }

    /**
     * @return array<string, array{string, \Closure(Limits, int): Limits, \Closure(Limits): int}>
     */
    public static function limitAccessors(): array
    {
        return [
            'max_bytes' => [
                'max_bytes',
                static function (Limits $limits, int $value): Limits {
                    return $limits->withMaxBytes($value);
                },
                static function (Limits $limits): int {
                    return $limits->getMaxBytes();
                },
            ],
            'max_width' => [
                'max_width',
                static function (Limits $limits, int $value): Limits {
                    return $limits->withMaxWidth($value);
                },
                static function (Limits $limits): int {
                    return $limits->getMaxWidth();
                },
            ],
            'max_height' => [
                'max_height',
                static function (Limits $limits, int $value): Limits {
                    return $limits->withMaxHeight($value);
                },
                static function (Limits $limits): int {
                    return $limits->getMaxHeight();
                },
            ],
            'max_megapixels' => [
                'max_megapixels',
                static function (Limits $limits, int $value): Limits {
                    return $limits->withMaxMegapixels($value);
                },
                static function (Limits $limits): int {
                    return $limits->getMaxMegapixels();
                },
            ],
            'max_frames' => [
                'max_frames',
                static function (Limits $limits, int $value): Limits {
                    return $limits->withMaxFrames($value);
                },
                static function (Limits $limits): int {
                    return $limits->getMaxFrames();
                },
            ],
            'max_total_megapixels' => [
                'max_total_megapixels',
                static function (Limits $limits, int $value): Limits {
                    return $limits->withMaxTotalMegapixels($value);
                },
                static function (Limits $limits): int {
                    return $limits->getMaxTotalMegapixels();
                },
            ],
        ];
    }

    /**
     * @param \Closure(Limits, int): Limits $with
     * @param \Closure(Limits): int         $get
     *
     * @dataProvider limitAccessors
     */
    #[DataProvider('limitAccessors')]
    public function testWithMethodReturnsAModifiedCopyAndLeavesTheOriginalUnchanged(string $name, \Closure $with, \Closure $get): void
    {
        $original = new Limits();
        foreach ([1, 77, Capabilities::LIMIT_VALUE_MAX] as $value) {
            $modified = $with($original, $value);

            self::assertNotSame($original, $modified);
            self::assertSame($value, $get($modified));
            self::assertSame($value, $modified->get($name));
            self::assertSame($value, $modified->toArray()[$name]);
            self::assertEquals($modified, $original->withLimit($name, $value));
            self::assertSame(self::DEFAULTS, $original->toArray());
        }
    }

    /**
     * @param \Closure(Limits, int): Limits $with
     *
     * @dataProvider limitAccessors
     */
    #[DataProvider('limitAccessors')]
    public function testWithMethodRejectsAValueOutOfRange(string $name, \Closure $with): void
    {
        foreach ([0, -1, Capabilities::LIMIT_VALUE_MAX + 1, PHP_INT_MIN] as $value) {
            try {
                $with(new Limits(), $value);
                self::fail(sprintf('%s = %d was accepted', $name, $value));
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INTERNAL_ERROR, $exception->getErrorCode());
            }
        }
    }

    public function testUnknownLimitNameIsRejected(): void
    {
        foreach (['max_depth', 'MAX_BYTES', ''] as $name) {
            try {
                (new Limits())->withLimit($name, 10);
                self::fail('An unknown name was accepted by withLimit()');
            } catch (InvalidArgumentException $exception) {
                self::assertStringNotContainsString($name === '' ? 'unknown name' : $name, $exception->getMessage());
            }
            try {
                (new Limits())->get($name);
                self::fail('An unknown name was accepted by get()');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INTERNAL_ERROR, $exception->getErrorCode());
            }
        }
    }

    public function testFromArrayKeepsTheDefaultOfEveryMissingName(): void
    {
        $limits = Limits::fromArray(['max_frames' => 1, 'max_bytes' => 4096]);

        self::assertSame(array_replace(self::DEFAULTS, ['max_bytes' => 4096, 'max_frames' => 1]), $limits->toArray());
        self::assertEquals(new Limits(), Limits::fromArray([]));
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function invalidArrays(): array
    {
        return [
            'unknown name' => [['max_depth' => 3]],
            'integer key' => [[0 => 3]],
            'numeric string' => [['max_bytes' => '1024']],
            'float' => [['max_width' => 100.0]],
            'boolean' => [['max_frames' => true]],
            'null' => [['max_height' => null]],
            'zero' => [['max_megapixels' => 0]],
            'above LIMIT_VALUE_MAX' => [['max_total_megapixels' => Capabilities::LIMIT_VALUE_MAX + 1]],
        ];
    }

    /**
     * @param array<mixed> $limits
     *
     * @dataProvider invalidArrays
     */
    #[DataProvider('invalidArrays')]
    public function testFromArrayRejectsInvalidInput(array $limits): void
    {
        $this->expectException(InvalidArgumentException::class);

        Limits::fromArray($limits);
    }

    public function testAssertFileSizeUsesTheCapabilitiesCheck(): void
    {
        $limits = (new Limits())->withMaxBytes(100);
        $limits->assertFileSize(0);
        $limits->assertFileSize(100);

        try {
            $limits->assertFileSize(101);
            self::fail('A file above max_bytes was accepted');
        } catch (LimitExceededException $exception) {
            self::assertSame(ErrorCode::FILE_TOO_LARGE, $exception->getErrorCode());
            self::assertSame(Capabilities::LIMIT_MAX_BYTES, $exception->getLimit());
            self::assertSame([100, 101], [$exception->getMaximum(), $exception->getActual()]);
            self::assertFalse($exception->isOutputLimit());
        }

        $this->expectException(InvalidArgumentException::class);
        $limits->assertFileSize(-1);
    }

    /**
     * @return array<string, array{int, int, int, string|null}>
     */
    public static function images(): array
    {
        // Width and height in thousands of pixels, against limitsForImages(): 100000 x 80000 px, 5000 MP per frame,
        // 10 frames and 20000 MP over all frames.
        return [
            'within every limit' => [100, 50, 4, null],
            'width above max_width' => [101, 10, 1, Capabilities::LIMIT_MAX_WIDTH],
            'height above max_height' => [10, 81, 1, Capabilities::LIMIT_MAX_HEIGHT],
            'width reported before height' => [101, 81, 1, Capabilities::LIMIT_MAX_WIDTH],
            'area above max_megapixels' => [100, 80, 1, Capabilities::LIMIT_MAX_MEGAPIXELS],
            'frames above max_frames' => [10, 10, 11, Capabilities::LIMIT_MAX_FRAMES],
            'all frames above max_total_megapixels' => [100, 50, 5, Capabilities::LIMIT_MAX_TOTAL_MEGAPIXELS],
        ];
    }

    private static function limitsForImages(): Limits
    {
        return (new Limits())->withMaxWidth(100000)->withMaxHeight(80000)->withMaxMegapixels(5000)
            ->withMaxFrames(10)->withMaxTotalMegapixels(20000);
    }

    /**
     * @dataProvider images
     */
    #[DataProvider('images')]
    public function testAssertImageUsesTheCapabilitiesCheck(int $width, int $height, int $frames, ?string $exceeded): void
    {
        try {
            self::limitsForImages()->assertImage($width * 1000, $height * 1000, $frames);
            self::assertNull($exceeded, 'No limit was reported');
        } catch (LimitExceededException $exception) {
            self::assertSame($exceeded, $exception->getLimit());
            self::assertSame(ErrorCode::IMAGE_TOO_LARGE, $exception->getErrorCode());
            self::assertFalse($exception->isOutputLimit());
        }
    }

    public function testAssertImageRejectsAValueBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Limits())->assertImage(1, 0, 1);
    }

    public function testMaxFramesForCombinesBothFrameLimits(): void
    {
        $limits = new Limits();

        self::assertSame(300, $limits->maxFramesFor(1, 1));
        self::assertSame(300, $limits->maxFramesFor(1000, 1000));
        self::assertSame(10, $limits->maxFramesFor(8000, 5000));
        self::assertSame(0, (new Limits())->withMaxTotalMegapixels(1)->maxFramesFor(1001, 1000));
        self::assertSame(
            Capabilities::LIMIT_VALUE_MAX,
            (new Limits())->withMaxFrames(Capabilities::LIMIT_VALUE_MAX)->withMaxTotalMegapixels(Capabilities::LIMIT_VALUE_MAX)
                ->maxFramesFor(1, 1)
        );
        self::assertSame(0, $limits->maxFramesFor(Capabilities::LIMIT_VALUE_MAX, Capabilities::LIMIT_VALUE_MAX));
    }

    /**
     * Going one frame above maxFramesFor() is always reported by assertImage(), so a parser that stops there reports
     * the right limit.
     */
    public function testOneFrameAboveMaxFramesForIsAlwaysALimitViolation(): void
    {
        $limits = new Limits();
        foreach ([[1, 1], [1000, 1000], [8000, 5000], [3000, 3000]] as $size) {
            list($width, $height) = $size;
            $frames = $limits->maxFramesFor($width, $height);
            $limits->assertImage($width, $height, $frames);

            try {
                $limits->assertImage($width, $height, $frames + 1);
                self::fail('One frame above maxFramesFor() was accepted');
            } catch (LimitExceededException $exception) {
                self::assertContains($exception->getLimit(), [Capabilities::LIMIT_MAX_FRAMES, Capabilities::LIMIT_MAX_TOTAL_MEGAPIXELS]);
            }
        }
    }

    public function testMaxFramesForRejectsAnInvalidSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Limits())->maxFramesFor(0, 10);
    }

    public function testSerializationRoundTrip(): void
    {
        $limits = (new Limits())->withMaxBytes(1)->withMaxFrames(Capabilities::LIMIT_VALUE_MAX);

        self::assertEquals($limits, unserialize(serialize($limits), ['allowed_classes' => [Limits::class]]));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function tamperedStates(): array
    {
        return [
            'zero' => ['maxBytes', 0],
            'negative' => ['maxWidth', -5],
            'above LIMIT_VALUE_MAX' => ['maxFrames', Capabilities::LIMIT_VALUE_MAX + 1],
            'string' => ['maxHeight', '100'],
            'null' => ['maxTotalMegapixels', null],
            'array' => ['maxMegapixels', [40]],
        ];
    }

    /**
     * @param mixed $value
     *
     * @dataProvider tamperedStates
     */
    #[DataProvider('tamperedStates')]
    public function testUnserializeRejectsATamperedState(string $property, $value): void
    {
        $state = self::stateOf(new Limits());
        self::assertArrayHasKey($property, $state);
        $state[$property] = $value;

        $this->expectException(InvalidArgumentException::class);

        unserialize(self::serializedWithState(Limits::class, $state), ['allowed_classes' => [Limits::class]]);
    }
}
