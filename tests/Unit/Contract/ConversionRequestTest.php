<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Contract;

use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidRequestException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Contract\ConversionRequest
 * @covers \Mfonte\SecureImageConverter\Contract\Exception\InvalidRequestException
 */
#[CoversClass(ConversionRequest::class)]
#[CoversClass(InvalidRequestException::class)]
final class ConversionRequestTest extends TestCase
{
    /**
     * Fingerprint of a default request. Pinned so that any change of the canonical form is deliberate: changing it
     * invalidates every cache entry keyed by fingerprint(). It is the first 32 hex digits of the SHA-256 of these
     * lines joined with "\n": v=1, format=webp, quality=default(webp:80,avif:60,jpeg:85), effort=4, lossless=0,
     * width=auto, height=auto, fit=-, enlarge=-, strip=1, keep_icc=1, animation=preserve.
     */
    private const DEFAULT_FINGERPRINT = '79b8070fd39817ba4ae0ea90844ec406';

    public function testNewRequestHoldsTheDefaults(): void
    {
        $request = new ConversionRequest();

        self::assertSame('webp', $request->getFormat());
        self::assertFalse($request->keepsOriginalFormat());
        self::assertNull($request->getQuality());
        self::assertSame(4, $request->getEffort());
        self::assertFalse($request->isLossless());
        self::assertNull($request->getWidth());
        self::assertNull($request->getHeight());
        self::assertFalse($request->hasResize());
        self::assertSame('contain', $request->getFit());
        self::assertFalse($request->isEnlargementAllowed());
        self::assertTrue($request->shouldStripMetadata());
        self::assertTrue($request->shouldKeepIccProfile());
        self::assertSame('preserve', $request->getAnimationPolicy());
        self::assertSame(80, $request->getEffectiveQuality('webp'));
    }

    public function testDocumentedConstants(): void
    {
        self::assertSame('original', ConversionRequest::FORMAT_ORIGINAL);
        self::assertSame('webp', ConversionRequest::DEFAULT_FORMAT);
        self::assertSame(80, ConversionRequest::DEFAULT_QUALITY_WEBP);
        self::assertSame(60, ConversionRequest::DEFAULT_QUALITY_AVIF);
        self::assertSame(85, ConversionRequest::DEFAULT_QUALITY_JPEG);
        self::assertSame([1, 100], [ConversionRequest::QUALITY_MIN, ConversionRequest::QUALITY_MAX]);
        self::assertSame([0, 9, 4], [ConversionRequest::EFFORT_MIN, ConversionRequest::EFFORT_MAX, ConversionRequest::DEFAULT_EFFORT]);
        self::assertSame([1, 16384], [ConversionRequest::DIMENSION_MIN, ConversionRequest::DIMENSION_MAX]);
    }

    public function testListsOfAcceptedValues(): void
    {
        self::assertSame(
            ['format', 'quality', 'effort', 'lossless', 'width', 'height', 'fit', 'enlarge', 'strip', 'keep_icc', 'animation'],
            ConversionRequest::parameterNames()
        );
        self::assertSame(['webp', 'avif', 'original'], ConversionRequest::targetFormats());
        self::assertSame(['contain', 'cover', 'fill'], ConversionRequest::fitModes());
        self::assertSame(['preserve', 'first_frame', 'reject'], ConversionRequest::animationPolicies());
    }

    public function testDefaultParameters(): void
    {
        self::assertSame(
            [
                'format' => 'webp',
                'effort' => '4',
                'lossless' => '0',
                'fit' => 'contain',
                'enlarge' => '0',
                'strip' => '1',
                'keep_icc' => '1',
                'animation' => 'preserve',
            ],
            (new ConversionRequest())->toParameters()
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function targetFormats(): array
    {
        return [
            'webp' => ['webp'],
            'avif' => ['avif'],
            'original' => ['original'],
        ];
    }

    /**
     * @dataProvider targetFormats
     */
    #[DataProvider('targetFormats')]
    public function testTargetFormatIsAccepted(string $format): void
    {
        self::assertSame($format, (new ConversionRequest($format))->getFormat());
        self::assertSame($format, (new ConversionRequest())->withFormat($format)->getFormat());
        self::assertSame($format, ConversionRequest::fromParameters(['format' => $format])->getFormat());
        self::assertSame($format === 'original', (new ConversionRequest($format))->keepsOriginalFormat());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTargetFormats(): array
    {
        return [
            'jpeg is not a target' => ['jpeg'],
            'png is not a target' => ['png'],
            'uppercase' => ['WEBP'],
            'empty' => [''],
            'leading space' => [' webp'],
            'trailing space' => ['original '],
        ];
    }

    /**
     * @dataProvider invalidTargetFormats
     */
    #[DataProvider('invalidTargetFormats')]
    public function testConstructorRejectsInvalidFormat(string $format): void
    {
        $this->expectException(InvalidRequestException::class);

        new ConversionRequest($format);
    }

    /**
     * @dataProvider invalidTargetFormats
     */
    #[DataProvider('invalidTargetFormats')]
    public function testWithFormatRejectsInvalidFormat(string $format): void
    {
        $exception = self::catchInvalidRequest(static function () use ($format): void {
            (new ConversionRequest())->withFormat($format);
        });

        self::assertSame('format', $exception->getParameter());
    }

    /**
     * Valid boundaries and a value inside each integer range.
     *
     * @return array<string, array{string, int}>
     */
    public static function validIntegers(): array
    {
        return [
            'quality min' => ['quality', 1],
            'quality inside' => ['quality', 50],
            'quality max' => ['quality', 100],
            'effort min' => ['effort', 0],
            'effort inside' => ['effort', 5],
            'effort max' => ['effort', 9],
            'width min' => ['width', 1],
            'width inside' => ['width', 1200],
            'width max' => ['width', 16384],
            'height min' => ['height', 1],
            'height inside' => ['height', 900],
            'height max' => ['height', 16384],
        ];
    }

    /**
     * @dataProvider validIntegers
     */
    #[DataProvider('validIntegers')]
    public function testIntegerWithinRangeIsAccepted(string $name, int $value): void
    {
        self::assertSame($value, self::integerOf(self::withInteger(new ConversionRequest(), $name, $value), $name));
        self::assertSame($value, self::integerOf(ConversionRequest::fromParameters([$name => $value]), $name));
        self::assertSame($value, self::integerOf(ConversionRequest::fromParameters([$name => (string) $value]), $name));
    }

    /**
     * Values just outside each range, and extremes.
     *
     * @return array<string, array{string, int}>
     */
    public static function outOfRangeIntegers(): array
    {
        return [
            'quality below min' => ['quality', 0],
            'quality above max' => ['quality', 101],
            'quality negative' => ['quality', -1],
            'quality PHP_INT_MAX' => ['quality', PHP_INT_MAX],
            'quality PHP_INT_MIN' => ['quality', PHP_INT_MIN],
            'effort below min' => ['effort', -1],
            'effort above max' => ['effort', 10],
            'width below min' => ['width', 0],
            'width above max' => ['width', 16385],
            'width negative' => ['width', -1],
            'height below min' => ['height', 0],
            'height above max' => ['height', 16385],
            'height negative' => ['height', -1],
        ];
    }

    /**
     * @dataProvider outOfRangeIntegers
     */
    #[DataProvider('outOfRangeIntegers')]
    public function testIntegerOutOfRangeIsRejectedByWithMethod(string $name, int $value): void
    {
        $exception = self::catchInvalidRequest(static function () use ($name, $value): void {
            self::withInteger(new ConversionRequest(), $name, $value);
        });

        self::assertSame($name, $exception->getParameter());
        self::assertStringContainsString(sprintf('got %d', $value), $exception->getMessage());
    }

    /**
     * @dataProvider outOfRangeIntegers
     */
    #[DataProvider('outOfRangeIntegers')]
    public function testIntegerOutOfRangeIsRejectedByFromParameters(string $name, int $value): void
    {
        $exception = self::catchInvalidRequest(static function () use ($name, $value): void {
            ConversionRequest::fromParameters([$name => $value]);
        });

        self::assertSame($name, $exception->getParameter());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nullableIntegers(): array
    {
        return [
            'quality' => ['quality'],
            'width' => ['width'],
            'height' => ['height'],
        ];
    }

    /**
     * @dataProvider nullableIntegers
     */
    #[DataProvider('nullableIntegers')]
    public function testNullableIntegerCanBeReset(string $name): void
    {
        $set = self::withInteger(new ConversionRequest(), $name, 10);
        $reset = self::withInteger($set, $name, null);

        self::assertSame(10, self::integerOf($set, $name));
        self::assertNull(self::integerOf($reset, $name));
        self::assertEquals(new ConversionRequest(), $reset);
    }

    public function testHasResizeWithEitherDimension(): void
    {
        self::assertTrue((new ConversionRequest())->withWidth(10)->hasResize());
        self::assertTrue((new ConversionRequest())->withHeight(10)->hasResize());
        self::assertTrue((new ConversionRequest())->withWidth(10)->withHeight(10)->hasResize());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validChoices(): array
    {
        return [
            'fit contain' => ['fit', 'contain'],
            'fit cover' => ['fit', 'cover'],
            'fit fill' => ['fit', 'fill'],
            'animation preserve' => ['animation', 'preserve'],
            'animation first_frame' => ['animation', 'first_frame'],
            'animation reject' => ['animation', 'reject'],
        ];
    }

    /**
     * @dataProvider validChoices
     */
    #[DataProvider('validChoices')]
    public function testChoiceIsAccepted(string $name, string $value): void
    {
        $viaWith = $name === 'fit'
            ? (new ConversionRequest())->withFit($value)
            : (new ConversionRequest())->withAnimationPolicy($value);
        $viaParameters = ConversionRequest::fromParameters([$name => $value]);

        foreach ([$viaWith, $viaParameters] as $request) {
            self::assertSame($value, $name === 'fit' ? $request->getFit() : $request->getAnimationPolicy());
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidChoices(): array
    {
        return [
            'fit uppercase' => ['fit', 'CONTAIN'],
            'fit unknown' => ['fit', 'inside'],
            'fit css keyword' => ['fit', 'scale-down'],
            'fit empty' => ['fit', ''],
            'animation dash' => ['animation', 'first-frame'],
            'animation capitalised' => ['animation', 'Preserve'],
            'animation unknown' => ['animation', 'none'],
            'animation empty' => ['animation', ''],
        ];
    }

    /**
     * @dataProvider invalidChoices
     */
    #[DataProvider('invalidChoices')]
    public function testInvalidChoiceIsRejected(string $name, string $value): void
    {
        $viaWith = self::catchInvalidRequest(static function () use ($name, $value): void {
            if ($name === 'fit') {
                (new ConversionRequest())->withFit($value);
            } else {
                (new ConversionRequest())->withAnimationPolicy($value);
            }
        });
        $viaParameters = self::catchInvalidRequest(static function () use ($name, $value): void {
            ConversionRequest::fromParameters([$name => $value]);
        });

        self::assertSame($name, $viaWith->getParameter());
        self::assertSame($name, $viaParameters->getParameter());
    }

    public function testBooleansAreSetByWithMethods(): void
    {
        $request = (new ConversionRequest())
            ->withLossless(true)
            ->withEnlargementAllowed(true)
            ->withStripMetadata(false)
            ->withKeepIccProfile(false);

        self::assertTrue($request->isLossless());
        self::assertTrue($request->isEnlargementAllowed());
        self::assertFalse($request->shouldStripMetadata());
        self::assertFalse($request->shouldKeepIccProfile());
    }

    /**
     * One modification per with*() method, each to a value different from the default.
     *
     * @return array<string, array{\Closure(ConversionRequest): ConversionRequest}>
     */
    public static function modifications(): array
    {
        return [
            'withFormat' => [static function (ConversionRequest $r): ConversionRequest {
                return $r->withFormat('avif');
            }],
            'withQuality' => [static function (ConversionRequest $r): ConversionRequest {
                return $r->withQuality(42);
            }],
            'withEffort' => [static function (ConversionRequest $r): ConversionRequest {
                return $r->withEffort(9);
            }],
            'withLossless' => [static function (ConversionRequest $r): ConversionRequest {
                return $r->withLossless(true);
            }],
            'withWidth' => [static function (ConversionRequest $r): ConversionRequest {
                return $r->withWidth(640);
            }],
            'withHeight' => [static function (ConversionRequest $r): ConversionRequest {
                return $r->withHeight(480);
            }],
            'withFit' => [static function (ConversionRequest $r): ConversionRequest {
                return $r->withFit('cover');
            }],
            'withEnlargementAllowed' => [static function (ConversionRequest $r): ConversionRequest {
                return $r->withEnlargementAllowed(true);
            }],
            'withStripMetadata' => [static function (ConversionRequest $r): ConversionRequest {
                return $r->withStripMetadata(false);
            }],
            'withKeepIccProfile' => [static function (ConversionRequest $r): ConversionRequest {
                return $r->withKeepIccProfile(false);
            }],
            'withAnimationPolicy' => [static function (ConversionRequest $r): ConversionRequest {
                return $r->withAnimationPolicy('reject');
            }],
        ];
    }

    /**
     * @param \Closure(ConversionRequest): ConversionRequest $modify
     *
     * @dataProvider modifications
     */
    #[DataProvider('modifications')]
    public function testWithMethodReturnsModifiedCopyAndLeavesOriginalUnchanged(\Closure $modify): void
    {
        $original = new ConversionRequest();
        $snapshot = clone $original;
        $before = $original->toParameters();

        $modified = $modify($original);

        self::assertNotSame($original, $modified);
        self::assertEquals($snapshot, $original);
        self::assertSame($before, $original->toParameters());
        self::assertNotSame($before, $modified->toParameters());
    }

    public function testWithMethodReturnsNewInstanceEvenForUnchangedValue(): void
    {
        $original = new ConversionRequest();
        $same = $original->withEffort($original->getEffort());

        self::assertNotSame($original, $same);
        self::assertEquals($original, $same);
    }

    public function testRejectedWithCallLeavesOriginalUnchanged(): void
    {
        $original = (new ConversionRequest('avif'))->withQuality(70)->withWidth(100);
        $snapshot = clone $original;

        self::catchInvalidRequest(static function () use ($original): void {
            $original->withQuality(101);
        });
        self::catchInvalidRequest(static function () use ($original): void {
            $original->withWidth(0);
        });
        self::catchInvalidRequest(static function () use ($original): void {
            $original->withFormat('png');
        });

        self::assertEquals($snapshot, $original);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function defaultQualities(): array
    {
        return [
            'webp' => ['webp', 80],
            'avif' => ['avif', 60],
            'jpeg' => ['jpeg', 85],
            'png' => ['png', 85],
            'gif' => ['gif', 85],
            'bmp' => ['bmp', 85],
            'tiff' => ['tiff', 85],
            'svg' => ['svg', 85],
        ];
    }

    /**
     * @dataProvider defaultQualities
     */
    #[DataProvider('defaultQualities')]
    public function testEffectiveQualityFallsBackToTheOutputFormatDefault(string $outputFormat, int $expected): void
    {
        self::assertSame($expected, ConversionRequest::defaultQualityFor($outputFormat));
        self::assertSame($expected, (new ConversionRequest())->getEffectiveQuality($outputFormat));
        self::assertSame($expected, (new ConversionRequest('original'))->getEffectiveQuality($outputFormat));
    }

    /**
     * @dataProvider defaultQualities
     */
    #[DataProvider('defaultQualities')]
    public function testEffectiveQualityIsTheRequestedQualityWhenSet(string $outputFormat): void
    {
        self::assertSame(42, (new ConversionRequest())->withQuality(42)->getEffectiveQuality($outputFormat));
    }

    public function testEffectiveQualityRejectsUnknownFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ConversionRequest())->withQuality(42)->getEffectiveQuality('original');
    }

    public function testDefaultQualityRejectsUnknownFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConversionRequest::defaultQualityFor('heic');
    }

    public function testOutputFormatIsTheTargetFormatOrTheInputFormatForOriginal(): void
    {
        self::assertSame('webp', (new ConversionRequest('webp'))->resolveOutputFormat('jpeg'));
        self::assertSame('webp', (new ConversionRequest('webp'))->resolveOutputFormat('gif'));
        self::assertSame('avif', (new ConversionRequest('avif'))->resolveOutputFormat('png'));
        self::assertSame('png', (new ConversionRequest('original'))->resolveOutputFormat('png'));
        self::assertSame('gif', (new ConversionRequest('original'))->resolveOutputFormat('gif'));
    }

    public function testOutputFormatRejectsUnknownInputFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ConversionRequest('original'))->resolveOutputFormat('heic');
    }

    public function testToParametersOfAFullRequest(): void
    {
        $request = (new ConversionRequest('avif'))
            ->withQuality(55)
            ->withEffort(9)
            ->withLossless(true)
            ->withWidth(800)
            ->withHeight(600)
            ->withFit('cover')
            ->withEnlargementAllowed(true)
            ->withStripMetadata(false)
            ->withKeepIccProfile(false)
            ->withAnimationPolicy('first_frame');

        self::assertSame(
            [
                'format' => 'avif',
                'quality' => '55',
                'effort' => '9',
                'lossless' => '1',
                'width' => '800',
                'height' => '600',
                'fit' => 'cover',
                'enlarge' => '1',
                'strip' => '0',
                'keep_icc' => '0',
                'animation' => 'first_frame',
            ],
            $request->toParameters()
        );
    }

    /**
     * @return array<string, array{ConversionRequest}>
     */
    public static function requests(): array
    {
        return [
            'default' => [new ConversionRequest()],
            'every parameter set' => [(new ConversionRequest('avif'))
                ->withQuality(55)
                ->withEffort(9)
                ->withLossless(true)
                ->withWidth(800)
                ->withHeight(600)
                ->withFit('cover')
                ->withEnlargementAllowed(true)
                ->withStripMetadata(false)
                ->withKeepIccProfile(false)
                ->withAnimationPolicy('first_frame')],
            'original with width only' => [(new ConversionRequest('original'))->withWidth(320)],
            'height only and lowest quality' => [(new ConversionRequest())->withHeight(1)->withQuality(1)],
            'upper boundaries' => [(new ConversionRequest('avif'))
                ->withQuality(100)
                ->withEffort(9)
                ->withWidth(16384)
                ->withHeight(16384)
                ->withFit('fill')
                ->withAnimationPolicy('reject')],
            'lower boundaries' => [(new ConversionRequest())->withEffort(0)->withWidth(1)->withHeight(1)],
        ];
    }

    /**
     * @dataProvider requests
     */
    #[DataProvider('requests')]
    public function testParametersRoundTrip(ConversionRequest $request): void
    {
        $parameters = $request->toParameters();
        $restored = ConversionRequest::fromParameters($parameters);

        self::assertEquals($request, $restored);
        self::assertSame($parameters, $restored->toParameters());
        self::assertSame($request->fingerprint(), $restored->fingerprint());
        self::assertSame(
            array_values(array_intersect(ConversionRequest::parameterNames(), array_keys($parameters))),
            array_keys($parameters),
            'toParameters() must list parameters in canonical order'
        );
    }

    /**
     * @dataProvider requests
     */
    #[DataProvider('requests')]
    public function testParametersRoundTripThroughAQueryString(ConversionRequest $request): void
    {
        parse_str(http_build_query($request->toParameters()), $decoded);

        self::assertEquals($request, ConversionRequest::fromParameters($decoded));
    }

    public function testFromParametersAcceptsNativeTypesAndBothBooleanSpellings(): void
    {
        $fromStrings = ConversionRequest::fromParameters([
            'format' => 'avif',
            'quality' => '55',
            'effort' => '0',
            'lossless' => 'true',
            'width' => '800',
            'enlarge' => '1',
            'strip' => 'false',
            'keep_icc' => '0',
        ]);
        $fromNative = ConversionRequest::fromParameters([
            'format' => 'avif',
            'quality' => 55,
            'effort' => 0,
            'lossless' => true,
            'width' => 800,
            'enlarge' => true,
            'strip' => false,
            'keep_icc' => false,
        ]);

        self::assertEquals($fromStrings, $fromNative);
        self::assertSame(55, $fromNative->getQuality());
        self::assertSame(0, $fromNative->getEffort());
        self::assertTrue($fromNative->isLossless());
        self::assertSame(800, $fromNative->getWidth());
        self::assertNull($fromNative->getHeight());
        self::assertTrue($fromNative->isEnlargementAllowed());
        self::assertFalse($fromNative->shouldStripMetadata());
        self::assertFalse($fromNative->shouldKeepIccProfile());
    }

    public function testFromParametersOfAnEmptyArrayIsTheDefaultRequest(): void
    {
        self::assertEquals(new ConversionRequest(), ConversionRequest::fromParameters([]));
    }

    public function testFromParametersDoesNotDependOnKeyOrder(): void
    {
        $parameters = (new ConversionRequest('original'))->withQuality(30)->withWidth(10)->withHeight(20)->withFit('fill')->toParameters();

        self::assertEquals(
            ConversionRequest::fromParameters($parameters),
            ConversionRequest::fromParameters(array_reverse($parameters, true))
        );
    }

    /**
     * Malformed parameters, with the parameter name the exception must report (null for an unknown key).
     *
     * @return array<string, array{array<mixed>, string|null}>
     */
    public static function malformedParameters(): array
    {
        return [
            'unknown key' => [['size' => '100'], null],
            'key in wrong case' => [['Format' => 'webp'], null],
            'key with trailing space' => [['format ' => 'webp'], null],
            'integer key (loose switch on PHP 7)' => [[0 => 'webp'], null],
            'format not a target' => [['format' => 'jpeg'], 'format'],
            'format uppercase' => [['format' => 'WEBP'], 'format'],
            'format array' => [['format' => ['webp']], 'format'],
            'format null' => [['format' => null], 'format'],
            'format integer' => [['format' => 1], 'format'],
            'quality leading zero' => [['quality' => '080'], 'quality'],
            'quality zero padded zero' => [['quality' => '00'], 'quality'],
            'quality plus sign' => [['quality' => '+80'], 'quality'],
            'quality minus sign' => [['quality' => '-1'], 'quality'],
            'quality leading space' => [['quality' => ' 80'], 'quality'],
            'quality trailing space' => [['quality' => '80 '], 'quality'],
            'quality trailing newline' => [['quality' => "80\n"], 'quality'],
            'quality decimal string' => [['quality' => '80.0'], 'quality'],
            'quality exponent' => [['quality' => '8e1'], 'quality'],
            'quality hexadecimal' => [['quality' => '0x50'], 'quality'],
            'quality empty' => [['quality' => ''], 'quality'],
            'quality word' => [['quality' => 'high'], 'quality'],
            'quality float' => [['quality' => 80.0], 'quality'],
            'quality boolean' => [['quality' => true], 'quality'],
            'quality null' => [['quality' => null], 'quality'],
            'quality array' => [['quality' => ['80']], 'quality'],
            'quality zero' => [['quality' => '0'], 'quality'],
            'quality above max' => [['quality' => '101'], 'quality'],
            'quality ten digits' => [['quality' => '1000000000'], 'quality'],
            'effort above max' => [['effort' => '10'], 'effort'],
            'effort negative' => [['effort' => '-1'], 'effort'],
            'effort decimal' => [['effort' => '4.5'], 'effort'],
            'width zero' => [['width' => '0'], 'width'],
            'width above max' => [['width' => '16385'], 'width'],
            'width huge' => [['width' => '99999999999999999999'], 'width'],
            'width null' => [['width' => null], 'width'],
            'height zero' => [['height' => '0'], 'height'],
            'height above max' => [['height' => '16385'], 'height'],
            'height above max as integer' => [['height' => 16385], 'height'],
            'lossless yes' => [['lossless' => 'yes'], 'lossless'],
            'lossless uppercase TRUE' => [['lossless' => 'TRUE'], 'lossless'],
            'lossless capitalised True' => [['lossless' => 'True'], 'lossless'],
            'lossless on' => [['lossless' => 'on'], 'lossless'],
            'lossless empty' => [['lossless' => ''], 'lossless'],
            'lossless two' => [['lossless' => '2'], 'lossless'],
            'lossless integer 1' => [['lossless' => 1], 'lossless'],
            'lossless integer 0' => [['lossless' => 0], 'lossless'],
            'lossless null' => [['lossless' => null], 'lossless'],
            'enlarge no' => [['enlarge' => 'no'], 'enlarge'],
            'strip off' => [['strip' => 'off'], 'strip'],
            'keep_icc y' => [['keep_icc' => 'y'], 'keep_icc'],
            'fit capitalised' => [['fit' => 'Cover'], 'fit'],
            'fit unknown' => [['fit' => 'inside'], 'fit'],
            'fit integer' => [['fit' => 1], 'fit'],
            'animation dash' => [['animation' => 'first-frame'], 'animation'],
            'animation unknown' => [['animation' => 'all'], 'animation'],
            'valid parameters then an invalid one' => [['format' => 'avif', 'quality' => '50', 'width' => '0'], 'width'],
        ];
    }

    /**
     * @param array<mixed> $parameters
     *
     * @dataProvider malformedParameters
     */
    #[DataProvider('malformedParameters')]
    public function testFromParametersRejectsMalformedInput(array $parameters, ?string $expectedParameter): void
    {
        $exception = self::catchInvalidRequest(static function () use ($parameters): void {
            ConversionRequest::fromParameters($parameters);
        });

        self::assertSame($expectedParameter, $exception->getParameter());
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function hostileParameters(): array
    {
        return [
            'markup as value' => [['format' => '<script>alert(1)</script>']],
            'header injection in value' => [['fit' => "cover\r\nX-Injected: 1"]],
            'header injection in key' => [["format\r\nX-Injected: 1" => 'webp']],
            'markup as key' => [['<img src=x onerror=alert(1)>' => '1']],
            'overlong value' => [['animation' => str_repeat('a', 4096)]],
        ];
    }

    /**
     * Error messages may be returned to clients and logged: they must not reflect hostile input.
     *
     * @param array<mixed> $parameters
     *
     * @dataProvider hostileParameters
     */
    #[DataProvider('hostileParameters')]
    public function testErrorMessageDoesNotReflectHostileInput(array $parameters): void
    {
        $exception = self::catchInvalidRequest(static function () use ($parameters): void {
            ConversionRequest::fromParameters($parameters);
        });
        $message = $exception->getMessage();

        self::assertDoesNotMatchRegularExpression('/[<>\r\n]/', $message);
        self::assertStringNotContainsString('X-Injected', $message);
        self::assertStringNotContainsString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $message);
        self::assertLessThan(300, strlen($message));
    }

    public function testErrorMessageQuotesSafeTokens(): void
    {
        $exception = self::catchInvalidRequest(static function (): void {
            ConversionRequest::fromParameters(['format' => 'jpeg']);
        });

        self::assertSame(
            'Invalid parameter "format": expected one of webp, avif, original, got "jpeg".',
            $exception->getMessage()
        );
    }

    public function testFingerprintIsShortLowercaseHex(): void
    {
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', (new ConversionRequest())->fingerprint());
    }

    public function testFingerprintOfTheDefaultRequestIsStable(): void
    {
        self::assertSame(self::DEFAULT_FINGERPRINT, (new ConversionRequest())->fingerprint());
    }

    public function testFingerprintIsIndependentOfHowTheRequestWasBuilt(): void
    {
        $chained = (new ConversionRequest())->withHeight(200)->withFormat('avif')->withWidth(100)->withQuality(50);
        $constructed = (new ConversionRequest('avif'))->withWidth(100)->withHeight(200)->withQuality(50);
        $parsed = ConversionRequest::fromParameters(['height' => '200', 'quality' => 50, 'width' => '100', 'format' => 'avif']);

        self::assertSame($chained->fingerprint(), $constructed->fingerprint());
        self::assertSame($chained->fingerprint(), $parsed->fingerprint());
    }

    /**
     * Requests that differ from the default in exactly one way that changes the output.
     *
     * @return array<string, ConversionRequest>
     */
    private static function outputAffectingVariants(): array
    {
        $default = new ConversionRequest();
        $box = $default->withWidth(100)->withHeight(100);

        return [
            'default' => $default,
            'format avif' => $default->withFormat('avif'),
            'format original' => $default->withFormat('original'),
            'quality 80 set explicitly' => $default->withQuality(80),
            'quality 81' => $default->withQuality(81),
            'effort 3' => $default->withEffort(3),
            'lossless' => $default->withLossless(true),
            'width 100' => $default->withWidth(100),
            'height 100' => $default->withHeight(100),
            'box contain' => $box,
            'box cover' => $box->withFit('cover'),
            'box fill' => $box->withFit('fill'),
            'width 100 enlarged' => $default->withWidth(100)->withEnlargementAllowed(true),
            'metadata kept' => $default->withStripMetadata(false),
            'ICC profile stripped' => $default->withKeepIccProfile(false),
            'first frame only' => $default->withAnimationPolicy('first_frame'),
            'animation rejected' => $default->withAnimationPolicy('reject'),
        ];
    }

    public function testFingerprintChangesWithEveryOutputAffectingParameter(): void
    {
        $fingerprints = [];
        foreach (self::outputAffectingVariants() as $label => $request) {
            $fingerprints[$label] = $request->fingerprint();
        }

        self::assertSame(array_values($fingerprints), array_values(array_unique($fingerprints)), 'Two different requests share a fingerprint');
    }

    public function testFingerprintIgnoresFitUnlessBothDimensionsAreSet(): void
    {
        $default = new ConversionRequest();
        $widthOnly = $default->withWidth(100);

        self::assertSame($default->fingerprint(), $default->withFit('cover')->fingerprint());
        self::assertSame($widthOnly->fingerprint(), $widthOnly->withFit('fill')->fingerprint());
        self::assertNotSame(
            $widthOnly->withHeight(100)->fingerprint(),
            $widthOnly->withHeight(100)->withFit('fill')->fingerprint()
        );
    }

    public function testFingerprintIgnoresEnlargementWithoutResize(): void
    {
        $default = new ConversionRequest();

        self::assertSame($default->fingerprint(), $default->withEnlargementAllowed(true)->fingerprint());
        self::assertNotSame(
            $default->withHeight(50)->fingerprint(),
            $default->withHeight(50)->withEnlargementAllowed(true)->fingerprint()
        );
    }

    public function testFingerprintIgnoresIccProfileWhenMetadataIsKept(): void
    {
        $default = new ConversionRequest();
        $keepAll = $default->withStripMetadata(false);

        self::assertSame($keepAll->fingerprint(), $keepAll->withKeepIccProfile(false)->fingerprint());
        self::assertNotSame($default->fingerprint(), $default->withKeepIccProfile(false)->fingerprint());
    }

    /**
     * @throws \LogicException when $name is not an integer parameter
     */
    private static function withInteger(ConversionRequest $request, string $name, ?int $value): ConversionRequest
    {
        switch ($name) {
            case 'quality':
                return $request->withQuality($value);
            case 'effort':
                if ($value === null) {
                    throw new \LogicException('effort is not nullable');
                }

                return $request->withEffort($value);
            case 'width':
                return $request->withWidth($value);
            case 'height':
                return $request->withHeight($value);
        }

        throw new \LogicException('Unknown integer parameter ' . $name);
    }

    /**
     * @throws \LogicException when $name is not an integer parameter
     */
    private static function integerOf(ConversionRequest $request, string $name): ?int
    {
        switch ($name) {
            case 'quality':
                return $request->getQuality();
            case 'effort':
                return $request->getEffort();
            case 'width':
                return $request->getWidth();
            case 'height':
                return $request->getHeight();
        }

        throw new \LogicException('Unknown integer parameter ' . $name);
    }

    /**
     * Runs the callback and returns the InvalidRequestException it throws, failing the test when it throws nothing.
     *
     * @param \Closure(): void $callback
     */
    private static function catchInvalidRequest(\Closure $callback): InvalidRequestException
    {
        try {
            $callback();
        } catch (InvalidRequestException $exception) {
            return $exception;
        }

        self::fail('InvalidRequestException was not thrown');
    }
}
