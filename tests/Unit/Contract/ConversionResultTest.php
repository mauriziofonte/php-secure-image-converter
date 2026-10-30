<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Contract;

use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Contract\ConversionResult
 */
#[CoversClass(ConversionResult::class)]
final class ConversionResultTest extends TestCase
{
    use SerializedStateTrait;

    /**
     * Valid constructor arguments, keyed by constructor parameter name.
     *
     * @return array{outputPath: string, outputFormat: string, width: int, height: int, byteSize: int, frameCount: int, backend: string, durationMs: int, isolationLevel: string, passthrough: bool, warnings: array<mixed>}
     */
    private static function validArguments(): array
    {
        return [
            'outputPath' => '/tmp/out/photo.webp',
            'outputFormat' => 'webp',
            'width' => 800,
            'height' => 600,
            'byteSize' => 51234,
            'frameCount' => 1,
            'backend' => 'vips',
            'durationMs' => 37,
            'isolationLevel' => 'rlimit',
            'passthrough' => false,
            'warnings' => ['avif encoder unavailable, fell back to webp'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function create(array $overrides = []): ConversionResult
    {
        $a = array_merge(self::validArguments(), $overrides);
        self::assertIsString($a['outputPath']);
        self::assertIsString($a['outputFormat']);
        self::assertIsInt($a['width']);
        self::assertIsInt($a['height']);
        self::assertIsInt($a['byteSize']);
        self::assertIsInt($a['frameCount']);
        self::assertIsString($a['backend']);
        self::assertIsInt($a['durationMs']);
        self::assertIsString($a['isolationLevel']);
        self::assertIsBool($a['passthrough']);
        self::assertIsArray($a['warnings']);

        return new ConversionResult(
            $a['outputPath'],
            $a['outputFormat'],
            $a['width'],
            $a['height'],
            $a['byteSize'],
            $a['frameCount'],
            $a['backend'],
            $a['durationMs'],
            $a['isolationLevel'],
            $a['passthrough'],
            // @phpstan-ignore argument.type (malformed warnings are passed on purpose to test run-time validation)
            $a['warnings']
        );
    }

    public function testGettersReturnTheConstructorValues(): void
    {
        $result = self::create();

        self::assertSame('/tmp/out/photo.webp', $result->getOutputPath());
        self::assertSame('webp', $result->getOutputFormat());
        self::assertSame('image/webp', $result->getMimeType());
        self::assertSame(800, $result->getWidth());
        self::assertSame(600, $result->getHeight());
        self::assertSame(51234, $result->getByteSize());
        self::assertSame(1, $result->getFrameCount());
        self::assertFalse($result->isAnimated());
        self::assertSame('vips', $result->getBackend());
        self::assertSame(37, $result->getDurationMs());
        self::assertSame('rlimit', $result->getIsolationLevel());
        self::assertFalse($result->isPassthrough());
        self::assertSame(['avif encoder unavailable, fell back to webp'], $result->getWarnings());
    }

    public function testToArrayIsSafeForClientsAndLeavesOutTheOutputPath(): void
    {
        self::assertSame(
            [
                'output_format' => 'webp',
                'mime_type' => 'image/webp',
                'width' => 800,
                'height' => 600,
                'byte_size' => 51234,
                'frame_count' => 1,
                'backend' => 'vips',
                'duration_ms' => 37,
                'isolation_level' => 'rlimit',
                'passthrough' => false,
                'warnings' => ['avif encoder unavailable, fell back to webp'],
            ],
            self::create()->toArray()
        );
    }

    public function testToInternalArrayAddsTheOutputPath(): void
    {
        $result = self::create();

        self::assertSame(['output_path' => '/tmp/out/photo.webp'] + $result->toArray(), $result->toInternalArray());
        self::assertSame('output_path', array_keys($result->toInternalArray())[0]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function animatableFormats(): array
    {
        return ['png' => ['png'], 'gif' => ['gif'], 'webp' => ['webp'], 'avif' => ['avif']];
    }

    /**
     * @dataProvider animatableFormats
     */
    #[DataProvider('animatableFormats')]
    public function testAnimatableFormatsCanHaveSeveralFrames(string $format): void
    {
        self::assertTrue(self::create(['outputFormat' => $format, 'frameCount' => 2])->isAnimated());
    }

    public function testWarningsOfPrintableAsciiUpToTheMaximumLengthAreAccepted(): void
    {
        $warnings = [' !"#$%&\'()*+,-./0123456789:;<=>?@AZ[\\]^_`az{|}~', str_repeat('w', ConversionResult::WARNING_MAX_LENGTH)];

        self::assertSame($warnings, self::create(['warnings' => $warnings])->getWarnings());
    }

    public function testPassthroughWithoutBackendAndAnimatedOutput(): void
    {
        $result = self::create([
            'outputFormat' => 'gif',
            'frameCount' => 12,
            'backend' => ConversionResult::BACKEND_NONE,
            'durationMs' => 0,
            'isolationLevel' => 'none',
            'passthrough' => true,
            'warnings' => [],
        ]);

        self::assertSame('image/gif', $result->getMimeType());
        self::assertTrue($result->isAnimated());
        self::assertSame(12, $result->getFrameCount());
        self::assertSame('none', $result->getBackend());
        self::assertSame(0, $result->getDurationMs());
        self::assertTrue($result->isPassthrough());
        self::assertSame([], $result->getWarnings());
    }

    public function testMinimumValuesAreAccepted(): void
    {
        $result = self::create(['width' => 1, 'height' => 1, 'byteSize' => 1, 'frameCount' => 1, 'durationMs' => 0]);

        self::assertSame([1, 1, 1, 1, 0], [
            $result->getWidth(),
            $result->getHeight(),
            $result->getByteSize(),
            $result->getFrameCount(),
            $result->getDurationMs(),
        ]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidArguments(): array
    {
        return [
            'empty output path' => [['outputPath' => '']],
            'NUL byte in output path' => [['outputPath' => "/tmp/out\0.webp"]],
            'unknown output format' => [['outputFormat' => 'heic']],
            'target pseudo-format as output format' => [['outputFormat' => 'original']],
            'width zero' => [['width' => 0]],
            'height zero' => [['height' => 0]],
            'negative width' => [['width' => -1]],
            'byte size zero' => [['byteSize' => 0]],
            'frame count zero' => [['frameCount' => 0]],
            'several frames of jpeg' => [['outputFormat' => 'jpeg', 'frameCount' => 2]],
            'several frames of bmp' => [['outputFormat' => 'bmp', 'frameCount' => 2]],
            'several frames of tiff' => [['outputFormat' => 'tiff', 'frameCount' => 2]],
            'negative duration' => [['durationMs' => -1]],
            'empty backend' => [['backend' => '']],
            'uppercase backend' => [['backend' => 'Vips']],
            'backend with space' => [['backend' => 'vips cli']],
            'backend too long' => [['backend' => str_repeat('a', 33)]],
            'backend none for a converted output' => [['backend' => 'none', 'passthrough' => false]],
            'unknown isolation level' => [['isolationLevel' => 'bwrap']],
            'warnings not a list' => [['warnings' => ['first' => 'fell back']]],
            'warning not a string' => [['warnings' => [42]]],
            'empty warning' => [['warnings' => ['']]],
            'warning with LF (log or header injection)' => [['warnings' => ["fell back\nX-Injected: 1"]]],
            'warning with CR' => [['warnings' => ["fell back\r"]]],
            'warning with tab' => [['warnings' => ["fell\tback"]]],
            'warning with NUL' => [['warnings' => ["fell back\0"]]],
            'warning with DEL' => [['warnings' => ["fell back\x7F"]]],
            'warning with non-ASCII' => [['warnings' => ["fell back \u{2192} webp"]]],
            'warning too long' => [['warnings' => [str_repeat('w', ConversionResult::WARNING_MAX_LENGTH + 1)]]],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @dataProvider invalidArguments
     */
    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentIsRejected(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::create($overrides);
    }

    public function testSerializationRoundTrip(): void
    {
        $result = self::create();

        self::assertEquals($result, unserialize(serialize($result), ['allowed_classes' => [ConversionResult::class]]));
    }

    /**
     * Private states that the constructor refuses.
     *
     * @return array<string, array{string, mixed}>
     */
    public static function tamperedStates(): array
    {
        return [
            'unknown output format' => ['outputFormat', 'heic'],
            'width as a string' => ['width', '800'],
            'height zero' => ['height', 0],
            'frames of a still format' => ['frameCount', 3],
            'passthrough as an integer' => ['passthrough', 1],
            'warning with LF' => ['warnings', ["a\nb"]],
            'warnings not an array' => ['warnings', 'fell back'],
            'output path null' => ['outputPath', null],
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
        $state = self::stateOf(self::create(['outputFormat' => 'jpeg']));
        self::assertArrayHasKey($property, $state);
        $state[$property] = $value;

        $this->expectException(InvalidArgumentException::class);

        unserialize(self::serializedWithState(ConversionResult::class, $state), ['allowed_classes' => [ConversionResult::class]]);
    }
}
