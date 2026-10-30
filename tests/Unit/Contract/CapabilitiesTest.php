<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Contract;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Contract\Capabilities
 */
#[CoversClass(Capabilities::class)]
final class CapabilitiesTest extends TestCase
{
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
            ],
        ];
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
            ['max_bytes', 'max_width', 'max_height', 'max_megapixels', 'max_frames'],
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
            ['max_frames' => 5, 'max_megapixels' => 4, 'max_height' => 3, 'max_width' => 2, 'max_bytes' => 1]
        );

        self::assertSame(['jpeg', 'gif'], $capabilities->getInputFormats());
        self::assertSame(['jpeg', 'webp', 'avif'], $capabilities->getOutputFormats());
        self::assertSame(['gd', 'vips'], $capabilities->getBackends());
        self::assertSame(
            ['max_bytes' => 1, 'max_width' => 2, 'max_height' => 3, 'max_megapixels' => 4, 'max_frames' => 5],
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
}
