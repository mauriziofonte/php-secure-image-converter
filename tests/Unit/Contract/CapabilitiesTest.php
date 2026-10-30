<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Contract;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Contract\Capabilities
 */
#[CoversClass(Capabilities::class)]
final class CapabilitiesTest extends TestCase
{
    use SerializedStateTrait;

    /**
     * @return array{schema: int, input_formats: list<string>, output_formats: list<string>, animated_output_formats: list<string>, backends: list<string>, isolation_level: string, limits: array<string, int>}
     */
    private static function validArray(): array
    {
        return [
            'schema' => 1,
            'input_formats' => ['jpeg', 'png', 'gif', 'bmp'],
            'output_formats' => ['jpeg', 'png', 'gif', 'bmp', 'webp', 'avif'],
            'animated_output_formats' => ['gif', 'webp'],
            'backends' => ['vips', 'cwebp', 'gif2webp'],
            'isolation_level' => 'rlimit',
            'limits' => [
                'max_bytes' => 20971520,
                'max_width' => 8192,
                'max_height' => 8192,
                'max_megapixels' => 40,
                'max_frames' => 300,
                'max_total_megapixels' => 400,
            ],
        ];
    }

    /**
     * Capabilities whose limits are all LIMIT_VALUE_MAX: every bound then comes from the contract itself.
     */
    private static function createWithLargeLimits(): Capabilities
    {
        return new Capabilities([], [], [], [], 'none', array_fill_keys(Capabilities::limitNames(), Capabilities::LIMIT_VALUE_MAX));
    }

    private static function create(): Capabilities
    {
        $data = self::validArray();

        return new Capabilities(
            $data['input_formats'],
            $data['output_formats'],
            $data['animated_output_formats'],
            $data['backends'],
            $data['isolation_level'],
            $data['limits']
        );
    }

    public function testGetters(): void
    {
        $capabilities = self::create();

        self::assertSame(['jpeg', 'png', 'gif', 'bmp'], $capabilities->getInputFormats());
        self::assertSame(['jpeg', 'png', 'gif', 'bmp', 'webp', 'avif'], $capabilities->getOutputFormats());
        self::assertSame(['gif', 'webp'], $capabilities->getAnimatedOutputFormats());
        self::assertSame(['vips', 'cwebp', 'gif2webp'], $capabilities->getBackends());
        self::assertSame('rlimit', $capabilities->getIsolationLevel());
        self::assertSame(self::validArray()['limits'], $capabilities->getLimits());
        self::assertSame(8192, $capabilities->getLimit(Capabilities::LIMIT_MAX_WIDTH));
        self::assertSame(20971520, $capabilities->getLimit(Capabilities::LIMIT_MAX_BYTES));
        self::assertSame(
            ['max_bytes', 'max_width', 'max_height', 'max_megapixels', 'max_frames', 'max_total_megapixels'],
            Capabilities::limitNames()
        );
    }

    public function testSupportQueries(): void
    {
        $capabilities = self::create();

        self::assertTrue($capabilities->supportsInput('jpeg'));
        self::assertFalse($capabilities->supportsInput('webp'));
        self::assertFalse($capabilities->supportsInput('JPEG'));
        self::assertTrue($capabilities->supportsOutput('avif'));
        self::assertFalse($capabilities->supportsOutput('svg'));
        self::assertTrue($capabilities->supportsAnimatedOutput('webp'));
        self::assertFalse($capabilities->supportsAnimatedOutput('avif'));
        self::assertFalse($capabilities->supportsAnimatedOutput('png'));
    }

    public function testFormatsAreNormalisedToCanonicalOrderAndBackendsKeepTheirOrder(): void
    {
        $capabilities = new Capabilities(
            ['gif', 'jpeg'],
            ['webp', 'avif', 'jpeg'],
            ['webp'],
            ['gd', 'vips'],
            'none',
            ['max_total_megapixels' => 6, 'max_frames' => 5, 'max_megapixels' => 4, 'max_height' => 3, 'max_width' => 2, 'max_bytes' => 1]
        );

        self::assertSame(['jpeg', 'gif'], $capabilities->getInputFormats());
        self::assertSame(['jpeg', 'webp', 'avif'], $capabilities->getOutputFormats());
        self::assertSame(['gd', 'vips'], $capabilities->getBackends());
        self::assertSame(
            ['max_bytes' => 1, 'max_width' => 2, 'max_height' => 3, 'max_megapixels' => 4, 'max_frames' => 5, 'max_total_megapixels' => 6],
            $capabilities->getLimits()
        );
    }

    public function testEmptyListsDescribeAConverterThatCanDoNothing(): void
    {
        $capabilities = new Capabilities([], [], [], [], 'none', self::validArray()['limits']);

        self::assertSame([], $capabilities->getInputFormats());
        self::assertFalse($capabilities->supportsOutput('webp'));
        self::assertEquals($capabilities, Capabilities::fromArray($capabilities->toArray()));
    }

    public function testToArray(): void
    {
        self::assertSame(self::validArray(), self::create()->toArray());
        self::assertSame(1, Capabilities::SCHEMA_VERSION);
    }

    public function testArrayRoundTrip(): void
    {
        $capabilities = self::create();

        self::assertEquals($capabilities, Capabilities::fromArray($capabilities->toArray()));
        self::assertSame($capabilities->toArray(), Capabilities::fromArray(self::validArray())->toArray());
    }

    /**
     * The API serves toArray() as JSON on /healthz and the proxy decodes it back.
     */
    public function testJsonRoundTrip(): void
    {
        $capabilities = self::create();
        $json = json_encode($capabilities->toArray());
        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        self::assertEquals($capabilities, Capabilities::fromArray($decoded));
    }

    public function testUnknownLimitNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::create()->getLimit('max_duration');
    }

    /**
     * Invalid arrays, each obtained by changing one thing in a valid one.
     *
     * @return array<string, array{\Closure(array<string, mixed>): array<mixed>}>
     */
    public static function malformedArrays(): array
    {
        return [
            'schema missing' => [static function (array $d): array {
                unset($d['schema']);

                return $d;
            }],
            'schema version 2' => [static function (array $d): array {
                $d['schema'] = 2;

                return $d;
            }],
            'schema version 0' => [static function (array $d): array {
                $d['schema'] = 0;

                return $d;
            }],
            'schema as string' => [static function (array $d): array {
                $d['schema'] = '1';

                return $d;
            }],
            'schema as float' => [static function (array $d): array {
                $d['schema'] = 1.0;

                return $d;
            }],
            'key missing' => [static function (array $d): array {
                unset($d['backends']);

                return $d;
            }],
            'unknown key' => [static function (array $d): array {
                $d['version'] = '2.0.0';

                return $d;
            }],
            'formats not an array' => [static function (array $d): array {
                $d['input_formats'] = 'jpeg,png';

                return $d;
            }],
            'formats as an object' => [static function (array $d): array {
                $d['input_formats'] = ['first' => 'jpeg'];

                return $d;
            }],
            'formats with a gap in the keys' => [static function (array $d): array {
                $d['output_formats'] = [0 => 'jpeg', 2 => 'png'];

                return $d;
            }],
            'format not a string' => [static function (array $d): array {
                $d['input_formats'] = ['jpeg', 7];

                return $d;
            }],
            'unknown input format' => [static function (array $d): array {
                $d['input_formats'] = ['jpeg', 'heic'];

                return $d;
            }],
            'format in uppercase' => [static function (array $d): array {
                $d['output_formats'] = ['WEBP'];

                return $d;
            }],
            'target pseudo-format as output' => [static function (array $d): array {
                $d['output_formats'] = ['original'];

                return $d;
            }],
            'duplicate input format' => [static function (array $d): array {
                $d['input_formats'] = ['jpeg', 'png', 'jpeg'];

                return $d;
            }],
            'animated format that is not an output format' => [static function (array $d): array {
                $d['animated_output_formats'] = ['gif', 'webp', 'svg'];

                return $d;
            }],
            'animated format that cannot hold an animation' => [static function (array $d): array {
                $d['animated_output_formats'] = ['jpeg', 'gif'];

                return $d;
            }],
            'animated bmp' => [static function (array $d): array {
                $d['animated_output_formats'] = ['bmp'];

                return $d;
            }],
            'backend name reserved for passthrough' => [static function (array $d): array {
                $d['backends'] = ['vips', 'none'];

                return $d;
            }],
            'backend name in uppercase' => [static function (array $d): array {
                $d['backends'] = ['Vips'];

                return $d;
            }],
            'backend name with a space' => [static function (array $d): array {
                $d['backends'] = ['vips cli'];

                return $d;
            }],
            'empty backend name' => [static function (array $d): array {
                $d['backends'] = [''];

                return $d;
            }],
            'backend name too long' => [static function (array $d): array {
                $d['backends'] = [str_repeat('a', 33)];

                return $d;
            }],
            'duplicate backend' => [static function (array $d): array {
                $d['backends'] = ['vips', 'cwebp', 'vips'];

                return $d;
            }],
            'backends not a list' => [static function (array $d): array {
                $d['backends'] = ['vips' => true];

                return $d;
            }],
            'unknown isolation level' => [static function (array $d): array {
                $d['isolation_level'] = 'bwrap';

                return $d;
            }],
            'isolation level not a string' => [static function (array $d): array {
                $d['isolation_level'] = 3;

                return $d;
            }],
            'limits not an array' => [static function (array $d): array {
                $d['limits'] = 100;

                return $d;
            }],
            'limit missing' => [static function (array $d): array {
                self::assertIsArray($d['limits']);
                unset($d['limits']['max_frames']);

                return $d;
            }],
            'unknown limit' => [static function (array $d): array {
                self::assertIsArray($d['limits']);
                $d['limits']['max_seconds'] = 30;

                return $d;
            }],
            'limit zero' => [static function (array $d): array {
                self::assertIsArray($d['limits']);
                $d['limits']['max_bytes'] = 0;

                return $d;
            }],
            'limit negative' => [static function (array $d): array {
                self::assertIsArray($d['limits']);
                $d['limits']['max_width'] = -1;

                return $d;
            }],
            'limit as numeric string' => [static function (array $d): array {
                self::assertIsArray($d['limits']);
                $d['limits']['max_height'] = '8192';

                return $d;
            }],
            'limit as float' => [static function (array $d): array {
                self::assertIsArray($d['limits']);
                $d['limits']['max_megapixels'] = 40.5;

                return $d;
            }],
            'limit as boolean' => [static function (array $d): array {
                self::assertIsArray($d['limits']);
                $d['limits']['max_frames'] = true;

                return $d;
            }],
            'limit null' => [static function (array $d): array {
                self::assertIsArray($d['limits']);
                $d['limits']['max_frames'] = null;

                return $d;
            }],
            'limit above LIMIT_VALUE_MAX' => [static function (array $d): array {
                self::assertIsArray($d['limits']);
                $d['limits']['max_bytes'] = Capabilities::LIMIT_VALUE_MAX + 1;

                return $d;
            }],
            'total megapixels limit missing (schema 1 has six limits)' => [static function (array $d): array {
                self::assertIsArray($d['limits']);
                unset($d['limits']['max_total_megapixels']);

                return $d;
            }],
        ];
    }

    /**
     * @param \Closure(array<string, mixed>): array<mixed> $corrupt
     *
     * @dataProvider malformedArrays
     */
    #[DataProvider('malformedArrays')]
    public function testFromArrayRejectsMalformedInput(\Closure $corrupt): void
    {
        $data = $corrupt(self::validArray());

        $this->expectException(InvalidArgumentException::class);

        Capabilities::fromArray($data);
    }

    public function testFromArrayRejectsEmptyArray(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Capabilities::fromArray([]);
    }

    public function testUnsupportedSchemaIsReportedBeforeUnknownKeys(): void
    {
        $data = self::validArray();
        $data['schema'] = 2;
        $data['new_key_of_schema_2'] = [];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported schema version');

        Capabilities::fromArray($data);
    }

    public function testUnknownKeyIsNotEchoed(): void
    {
        $data = self::validArray();
        $data["<script>\r\n"] = 1;

        try {
            Capabilities::fromArray($data);
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('<script>', $exception->getMessage());

            return;
        }

        self::fail('InvalidArgumentException was not thrown');
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function backendNames(): array
    {
        return [
            'letters' => ['vips', true],
            'digits' => ['gif2webp', true],
            'underscore and dash' => ['im_cli-7', true],
            'single character' => ['a', true],
            '32 characters' => [str_repeat('a', 32), true],
            '33 characters' => [str_repeat('a', 33), false],
            'empty' => ['', false],
            'uppercase' => ['GD', false],
            'leading dash' => ['-vips', false],
            'leading underscore' => ['_vips', false],
            'dot' => ['vips.cli', false],
            'slash' => ['../vips', false],
            'trailing newline' => ["vips\n", false],
            'reserved for passthrough' => ['none', false],
            'longer name containing none' => ['nonevips', true],
        ];
    }

    /**
     * @dataProvider backendNames
     */
    #[DataProvider('backendNames')]
    public function testBackendNameValidation(string $name, bool $valid): void
    {
        self::assertSame($valid, Capabilities::isValidBackendName($name));
    }

    public function testAnimatedOutputMayUseEveryAnimatableFormat(): void
    {
        $capabilities = new Capabilities(
            ['gif'],
            ['png', 'gif', 'webp', 'avif'],
            ['png', 'gif', 'webp', 'avif'],
            ['vips'],
            'rlimit',
            self::validArray()['limits']
        );

        self::assertSame(['png', 'gif', 'webp', 'avif'], $capabilities->getAnimatedOutputFormats());
    }

    public function testLimitValueMaxIsAccepted(): void
    {
        $limits = array_fill_keys(Capabilities::limitNames(), Capabilities::LIMIT_VALUE_MAX);
        $capabilities = new Capabilities([], [], [], [], 'none', $limits);

        self::assertSame($limits, $capabilities->getLimits());
        self::assertSame(2147483647, Capabilities::LIMIT_VALUE_MAX);
    }

    public function testFileSizeWithinTheLimitIsAccepted(): void
    {
        $capabilities = self::create();
        $capabilities->assertFileSizeWithinLimits(0);
        $capabilities->assertFileSizeWithinLimits(20971520);

        $this->expectNotToPerformAssertions();
    }

    public function testFileSizeAboveTheLimitIsFileTooLarge(): void
    {
        $exception = self::catchLimitExceeded(static function (): void {
            self::create()->assertFileSizeWithinLimits(20971521);
        });

        self::assertSame(ErrorCode::FILE_TOO_LARGE, $exception->getErrorCode());
        self::assertSame('max_bytes', $exception->getLimit());
        self::assertFalse($exception->isOutputLimit());
    }

    public function testNegativeFileSizeIsAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::create()->assertFileSizeWithinLimits(-1);
    }

    /**
     * Images at the limits of self::create(): 8192 x 8192 px, 40 megapixels per frame, 300 frames, 400 megapixels in
     * total.
     *
     * @return array<string, array{int, int, int}>
     */
    public static function imagesWithinLimits(): array
    {
        return [
            'still image' => [800, 600, 1],
            'widest' => [8192, 1, 1],
            'highest' => [1, 8192, 1],
            'exactly 40 megapixels' => [8000, 5000, 1],
            'most frames' => [10, 10, 300],
            'exactly 400 megapixels in total' => [4000, 1000, 100],
        ];
    }

    /**
     * @dataProvider imagesWithinLimits
     */
    #[DataProvider('imagesWithinLimits')]
    public function testImageWithinTheLimitsIsAccepted(int $width, int $height, int $frames): void
    {
        self::create()->assertInputWithinLimits($width, $height, $frames);
        self::create()->assertOutputWithinLimits($width, $height, $frames);

        $this->expectNotToPerformAssertions();
    }

    /**
     * Images just above one limit of self::create(), with the limit reported and its maximum and actual values.
     *
     * @return array<string, array{int, int, int, string, int, int}>
     */
    public static function imagesAboveLimits(): array
    {
        return [
            'too wide' => [8193, 1, 1, 'max_width', 8192, 8193],
            'too high' => [1, 8193, 1, 'max_height', 8192, 8193],
            'one pixel above 40 megapixels' => [8000, 5000 + 1, 1, 'max_megapixels', 40000000, 40008000],
            'too many frames' => [1, 1, 301, 'max_frames', 300, 301],
            'above 400 megapixels in total' => [4000, 1000, 101, 'max_total_megapixels', 400000000, 404000000],
            'width is checked before the area' => [20000, 20000, 1, 'max_width', 8192, 20000],
            'decompression bomb with huge header values' => [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, 'max_width', 8192, PHP_INT_MAX],
        ];
    }

    /**
     * @dataProvider imagesAboveLimits
     */
    #[DataProvider('imagesAboveLimits')]
    public function testInputAboveALimitIsImageTooLarge(int $width, int $height, int $frames, string $limit, int $maximum, int $actual): void
    {
        $exception = self::catchLimitExceeded(static function () use ($width, $height, $frames): void {
            self::create()->assertInputWithinLimits($width, $height, $frames);
        });

        self::assertSame(ErrorCode::IMAGE_TOO_LARGE, $exception->getErrorCode());
        self::assertSame([$limit, $maximum, $actual, false], [
            $exception->getLimit(),
            $exception->getMaximum(),
            $exception->getActual(),
            $exception->isOutputLimit(),
        ]);
    }

    /**
     * @dataProvider imagesAboveLimits
     */
    #[DataProvider('imagesAboveLimits')]
    public function testOutputAboveALimitIsImageTooLarge(int $width, int $height, int $frames, string $limit, int $maximum, int $actual): void
    {
        $exception = self::catchLimitExceeded(static function () use ($width, $height, $frames): void {
            self::create()->assertOutputWithinLimits($width, $height, $frames);
        });

        self::assertSame(ErrorCode::IMAGE_TOO_LARGE, $exception->getErrorCode());
        self::assertSame([$limit, $maximum, $actual, true], [
            $exception->getLimit(),
            $exception->getMaximum(),
            $exception->getActual(),
            $exception->isOutputLimit(),
        ]);
    }

    public function testTotalPixelsSaturateInsteadOfOverflowing(): void
    {
        $limits = array_fill_keys(Capabilities::limitNames(), Capabilities::LIMIT_VALUE_MAX);
        $limits['max_total_megapixels'] = 1;
        $capabilities = new Capabilities([], [], [], [], 'none', $limits);

        $exception = self::catchLimitExceeded(static function () use ($capabilities): void {
            // 2 * 10^12 pixels per frame fit max_megapixels; times 2^31 - 1 frames, they do not fit an int.
            $capabilities->assertInputWithinLimits(2000000, 1000000, Capabilities::LIMIT_VALUE_MAX);
        });

        self::assertSame('max_total_megapixels', $exception->getLimit());
        self::assertSame(PHP_INT_MAX, $exception->getActual());
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function invalidImageValues(): array
    {
        return ['zero width' => [0, 1, 1], 'zero height' => [1, 0, 1], 'zero frames' => [1, 1, 0], 'negative' => [-1, -1, -1]];
    }

    /**
     * @dataProvider invalidImageValues
     */
    #[DataProvider('invalidImageValues')]
    public function testImageValuesBelowOneAreAProgrammingError(int $width, int $height, int $frames): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::create()->assertOutputWithinLimits($width, $height, $frames);
    }

    /**
     * width=16383, height=16383, fit=fill and enlarge=1 is a valid request; enlarging a 10 x 10 input to it is
     * refused before any encoder runs, because the resolved output exceeds the dimension and pixel limits.
     */
    public function testEnlargingASmallInputBeyondTheLimitsIsRefused(): void
    {
        $request = ConversionRequest::fromParameters(['width' => '16383', 'height' => '16383', 'fit' => 'fill', 'enlarge' => '1']);
        list($width, $height) = $request->resolveOutputSize(10, 10);
        $frames = $request->resolveOutputFrameCount(1, false);
        self::create()->assertInputWithinLimits(10, 10, 1);

        $exception = self::catchLimitExceeded(static function () use ($width, $height, $frames): void {
            self::create()->assertOutputWithinLimits($width, $height, $frames);
        });

        self::assertSame(ErrorCode::IMAGE_TOO_LARGE, $exception->getErrorCode());
        self::assertTrue($exception->isOutputLimit());
        self::assertSame('Resolved output width is 16383 px, above the limit of 8192 px (max_width).', $exception->getMessage());

        $limits = ['max_width' => 16383, 'max_height' => 16383] + self::validArray()['limits'];
        $wide = new Capabilities([], [], [], [], 'none', $limits);
        $exception = self::catchLimitExceeded(static function () use ($wide, $width, $height, $frames): void {
            $wide->assertOutputWithinLimits($width, $height, $frames);
        });
        self::assertSame('max_megapixels', $exception->getLimit());
        self::assertSame(16383 * 16383, $exception->getActual());
    }

    /**
     * Resolved outputs wider or taller than OUTPUT_DIMENSION_MAX, with the limit reported and its actual value.
     *
     * @return array<string, array{int, int, string, int}>
     */
    public static function outputsAboveTheOutputDimensionMaximum(): array
    {
        return [
            'one pixel too wide' => [16384, 1, 'max_width', 16384],
            'one pixel too high' => [1, 16384, 'max_height', 16384],
            'above the JPEG and GIF maximums' => [65536, 100, 'max_width', 65536],
        ];
    }

    /**
     * Whatever max_width and max_height allow, a resolved output above OUTPUT_DIMENSION_MAX (16383 px, the WEBP
     * maximum) is refused as image_too_large (422) before any encoder runs, so that no encoder can fail on a size
     * (conversion_failed, 500). An input of that size stays within the limits and may be resized down.
     *
     * @dataProvider outputsAboveTheOutputDimensionMaximum
     */
    #[DataProvider('outputsAboveTheOutputDimensionMaximum')]
    public function testOutputAboveTheOutputDimensionMaximumIsImageTooLarge(int $width, int $height, string $limit, int $actual): void
    {
        $capabilities = self::createWithLargeLimits();
        $capabilities->assertInputWithinLimits($width, $height, 1);

        $exception = self::catchLimitExceeded(static function () use ($capabilities, $width, $height): void {
            $capabilities->assertOutputWithinLimits($width, $height, 1);
        });

        self::assertSame(ErrorCode::IMAGE_TOO_LARGE, $exception->getErrorCode());
        self::assertSame([$limit, 16383, $actual, true], [
            $exception->getLimit(),
            $exception->getMaximum(),
            $exception->getActual(),
            $exception->isOutputLimit(),
        ]);
    }

    public function testOutputAtTheOutputDimensionMaximumIsAccepted(): void
    {
        self::assertSame(16383, Capabilities::OUTPUT_DIMENSION_MAX);
        self::assertSame(ConversionRequest::DIMENSION_MAX, Capabilities::OUTPUT_DIMENSION_MAX);

        self::createWithLargeLimits()->assertOutputWithinLimits(16383, 16383, 1);
    }

    /**
     * A valid request can resolve to a side above OUTPUT_DIMENSION_MAX: only height is requested and the width
     * follows the input's 4:1 aspect ratio. The resolved output is refused as image_too_large even where the host
     * limits would allow it.
     */
    public function testRequestResolvingAboveTheOutputDimensionMaximumIsImageTooLarge(): void
    {
        $request = ConversionRequest::fromParameters(['height' => '16383', 'enlarge' => '1']);
        list($width, $height) = $request->resolveOutputSize(4000, 1000);
        self::assertSame([65532, 16383], [$width, $height]);
        $capabilities = self::createWithLargeLimits();

        $exception = self::catchLimitExceeded(static function () use ($capabilities, $width, $height): void {
            $capabilities->assertOutputWithinLimits($width, $height, 1);
        });

        self::assertSame(ErrorCode::IMAGE_TOO_LARGE, $exception->getErrorCode());
        self::assertSame('Resolved output width is 65532 px, above the limit of 16383 px (max_width).', $exception->getMessage());
    }

    /**
     * A max_width or max_height below OUTPUT_DIMENSION_MAX keeps applying to the resolved output as configured.
     */
    public function testLowerOutputLimitsAreReportedAsConfigured(): void
    {
        $exception = self::catchLimitExceeded(static function (): void {
            self::create()->assertOutputWithinLimits(16384, 1, 1);
        });

        self::assertSame(['max_width', 8192], [$exception->getLimit(), $exception->getMaximum()]);
    }

    /**
     * An animated output that fits every per-frame limit can still exceed the budget of all frames together.
     */
    public function testAnimatedOutputIsBoundByTheTotalPixelBudget(): void
    {
        $request = (new ConversionRequest())->withWidth(4000)->withEnlargementAllowed(true);
        list($width, $height) = $request->resolveOutputSize(400, 300);
        $frames = $request->resolveOutputFrameCount(200, true);
        self::assertSame([4000, 3000, 200], [$width, $height, $frames]);

        $exception = self::catchLimitExceeded(static function () use ($width, $height, $frames): void {
            self::create()->assertOutputWithinLimits($width, $height, $frames);
        });
        self::assertSame('max_total_megapixels', $exception->getLimit());
        self::assertSame(2400000000, $exception->getActual());

        $firstFrameOnly = $request->withAnimationPolicy('first_frame')->resolveOutputFrameCount(200, true);
        self::create()->assertOutputWithinLimits($width, $height, $firstFrameOnly);
    }

    public function testSerializationRoundTrip(): void
    {
        $capabilities = self::create();

        self::assertEquals($capabilities, unserialize(serialize($capabilities), ['allowed_classes' => [Capabilities::class]]));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function tamperedStates(): array
    {
        return [
            'unknown input format' => ['inputFormats', ['heic']],
            'formats out of canonical order' => ['outputFormats', ['webp', 'jpeg', 'png', 'gif', 'bmp', 'avif']],
            'animated jpeg' => ['animatedOutputFormats', ['jpeg']],
            'reserved backend name' => ['backends', ['none']],
            'isolation level as an integer' => ['isolationLevel', 3],
            'limit missing' => ['limits', ['max_bytes' => 1]],
            'limit as a string' => ['limits', ['max_bytes' => '1'] + self::validArray()['limits']],
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
        $state = self::stateOf(self::create());
        self::assertArrayHasKey($property, $state);
        $state[$property] = $value;

        $this->expectException(InvalidArgumentException::class);

        unserialize(self::serializedWithState(Capabilities::class, $state), ['allowed_classes' => [Capabilities::class]]);
    }

    /**
     * The private properties under their mangled names, in declaration order, on every PHP version. A restored
     * instance is serialized again to the same string.
     */
    public function testSerializedFormIsTheSameOnEveryPhpVersion(): void
    {
        $capabilities = self::create();
        $expected = self::serializedWithState(Capabilities::class, self::stateOf($capabilities));

        self::assertSame($expected, serialize($capabilities));
        self::assertSame($expected, serialize(unserialize($expected, ['allowed_classes' => [Capabilities::class]])));
    }

    /**
     * A crafted string can make two array elements share one PHP reference ("R:" back-reference). unserialize() must
     * rebuild the arrays by value: otherwise a caller changing the array returned by getLimits() would change the
     * limits of the restored instance.
     */
    public function testUnserializeBreaksAReferenceSharedByTwoArrayElements(): void
    {
        $state = self::stateOf(self::create());
        $limits = ['max_bytes' => 8192, 'max_width' => 8192] + self::validArray()['limits'];
        $state['limits'] = $limits;
        $maxBytesNumber = self::referenceNumberOf($state, 'limits') + 1;
        $raw = sprintf('a:%d:{', count($limits));
        foreach ($limits as $name => $value) {
            $raw .= serialize($name) . ($name === 'max_width' ? sprintf('R:%d;', $maxBytesNumber) : serialize($value));
        }
        $raw .= '}';

        $restored = unserialize(
            self::serializedWithState(Capabilities::class, $state, ['limits' => $raw]),
            ['allowed_classes' => [Capabilities::class]]
        );
        self::assertInstanceOf(Capabilities::class, $restored);
        $copy = $restored->getLimits();
        $copy['max_width'] = 1;

        self::assertSame(8192, $restored->getLimit(Capabilities::LIMIT_MAX_BYTES));
        self::assertSame(8192, $restored->getLimit(Capabilities::LIMIT_MAX_WIDTH));
        self::assertSame($limits, $restored->getLimits());
    }

    /**
     * Runs the callback and returns the LimitExceededException it throws, failing the test when it throws nothing.
     *
     * @param \Closure(): void $callback
     */
    private static function catchLimitExceeded(\Closure $callback): LimitExceededException
    {
        try {
            $callback();
        } catch (LimitExceededException $exception) {
            return $exception;
        }

        self::fail('LimitExceededException was not thrown');
    }
}
