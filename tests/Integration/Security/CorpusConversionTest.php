<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Security;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Pipeline\SecureConverter;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageCorpus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The security corpus end to end: every file of ImageCorpus (truncated files, decompression bombs of PNG, GIF and
 * JPEG, polyglots, wrong magic bytes, oversized EXIF, a GIF of 10,000 frames and the hostile files built to pass the
 * header checks) converted by SecureConverter with every enabled backend, under the rules of SecurityTestCase.
 *
 * - A file the inspector refuses is refused by the converter with the same error code, in under REFUSAL_SECONDS,
 *   before any backend runs.
 * - A file the inspector accepts is converted to WEBP, to AVIF, to its own format and to its own format resized to
 *   32 x 32, or refused with a 4xx code; never conversion_failed. A valid file is converted (see
 *   SecurityTestCase::KNOWN_REFUSALS). With the default limits the bombs of 40 megapixels and the animation of 300
 *   frames reach the decoders.
 * - Memory: the same accepted files, converted to WEBP at the rlimit level with a memory ceiling of
 *   MEMORY_CEILING_BYTES in a separate PHP process: the decompression bombs of 40 megapixels, which need more, are
 *   refused with resource_limit, the valid files still convert, and no conversion process ever held more than the
 *   ceiling.
 *
 * SIC_SECURITY_REPORT names a file to which every conversion is appended as a line "path, request, outcome,
 * milliseconds" (CI prints it).
 *
 * @covers \Mfonte\SecureImageConverter\Pipeline\SecureConverter
 * @covers \Mfonte\SecureImageConverter\Backend\AbstractBackend
 *
 * @group security
 */
#[CoversClass(SecureConverter::class)]
#[CoversClass(AbstractBackend::class)]
#[Group('security')]
final class CorpusConversionTest extends SecurityTestCase
{
    /**
     * Largest time the converter may take to refuse a file that the inspector refuses.
     */
    private const REFUSAL_SECONDS = 1.0;

    /**
     * Memory ceiling of the memory test: 384 MiB, below what each decompression bomb of 40 megapixels needs and above
     * what every backend needs for the valid files (below about 336 MiB, vips is left out and the valid files go to
     * backends that decode less).
     */
    private const MEMORY_CEILING_BYTES = 402653184;

    /**
     * Files the memory test expects refused with resource_limit.
     */
    private const MEMORY_BOMBS = [
        'png/bomb-decompression-40-megapixels.png',
        'jpeg/bomb-decompression-40-megapixels.jpg',
        'gif/bomb-decompression-40-megapixels.gif',
    ];

    /**
     * Largest peak memory of the PHP process of the memory test, which holds the whole corpus (about 30 MB).
     */
    private const MAX_PHP_PEAK_BYTES = 201326592;

    /**
     * @return array<string, array{string, string, array<string, bool|int|string>}>
     */
    public static function corpus(): array
    {
        $cases = [];
        foreach (ImageCorpus::cases() as $path => $case) {
            $cases[$path] = [$path, $case['bytes'], $case['expect']];
        }

        return $cases;
    }

    /**
     * @param array<string, bool|int|string> $expect
     *
     * @dataProvider corpus
     */
    #[DataProvider('corpus')]
    public function testEveryFileIsRefusedOrConvertedWithinBounds(string $path, string $bytes, array $expect): void
    {
        $converter = self::converter();
        if (isset($expect['error'])) {
            list($outcome, $seconds, $problems) = $this->convertHostile($converter, $bytes, new ConversionRequest());
            self::report($path, 'webp', $outcome, $seconds);
            self::assertSame([], $problems, $path);
            self::assertSame($expect['error'], $outcome, $path . ' is refused as the inspector refuses it');
            self::assertNull(self::validProblem($path, $outcome));
            self::assertLessThan(self::REFUSAL_SECONDS, $seconds, $path . ' is refused before any decoder runs');

            return;
        }

        $requests = [
            'webp' => new ConversionRequest(),
            'avif' => new ConversionRequest(ImageFormat::AVIF),
            'original' => new ConversionRequest(ConversionRequest::FORMAT_ORIGINAL),
            'original 32x32 cover' => (new ConversionRequest(ConversionRequest::FORMAT_ORIGINAL))->withWidth(32)->withHeight(32)->withFit(ConversionRequest::FIT_COVER),
        ];
        $failures = [];
        foreach ($requests as $label => $request) {
            list($outcome, $seconds, $problems) = $this->convertHostile($converter, $bytes, $request);
            self::report($path, $label, $outcome, $seconds);
            $problem = self::validProblem($path, $outcome);
            foreach ($problem !== null ? array_merge($problems, [$problem]) : $problems as $problem) {
                $failures[] = $label . ': ' . $problem;
            }
        }
        self::assertSame([], $failures, $path);
    }

    /**
     * Every file the inspector accepts, converted at the rlimit level with a memory ceiling of 384 MiB: the bombs of
     * MEMORY_BOMBS refused with resource_limit, the valid files converted, the others converted or refused with a 4xx
     * code, while no conversion process exceeded the ceiling (getrusage() of the child PHP process; the address space
     * limit is the ceiling, and a resident set never exceeds the address space).
     */
    public function testAMemoryCeilingBoundsEveryDecoder(): void
    {
        self::requireLevel(IsolationLevel::RLIMIT);
        self::converter();
        $parent = WorkingDirectory::create();

        try {
            $command = implode(' ', array_map('escapeshellarg', [
                PHP_BINARY,
                \dirname(__DIR__, 2) . '/Fixtures/Security/memory-bound.php',
                $parent->getPath(),
                (string) self::MEMORY_CEILING_BYTES,
                implode(',', self::enabledNames()),
            ]));
            $output = [];
            exec($command . ' 2>&1', $output, $status);
            $text = implode("\n", $output);
            self::assertSame(0, $status, $text);
            $report = json_decode((string) end($output), true);
            self::assertIsArray($report, $text);
            self::assertIsArray($report['outcomes']);
            self::assertNotEmpty($report['outcomes']);
            $failures = [];
            foreach ($report['outcomes'] as $path => $outcome) {
                $path = (string) $path;
                $outcome = is_string($outcome) ? $outcome : '?';
                $problem = self::validProblem($path, $outcome);
                if (in_array($path, self::MEMORY_BOMBS, true) && $outcome !== ErrorCode::RESOURCE_LIMIT) {
                    $problem = 'a bomb above the ceiling ended as ' . $outcome;
                } elseif ($outcome !== self::CONVERTED && !in_array($outcome, self::REFUSALS, true)) {
                    $problem = $outcome;
                }
                if ($problem !== null) {
                    $failures[] = $path . ': ' . $problem;
                }
            }
            self::assertSame([], $failures, 'every file is converted or refused under the memory ceiling');
            self::assertSame(self::MEMORY_BOMBS, array_values(array_intersect(self::MEMORY_BOMBS, array_keys($report['outcomes']))));
            self::assertIsInt($report['children_max_rss_bytes']);
            self::assertGreaterThan(0, $report['children_max_rss_bytes']);
            self::assertLessThanOrEqual(self::MEMORY_CEILING_BYTES, $report['children_max_rss_bytes'], 'no conversion process exceeded the ceiling');
            self::assertIsInt($report['php_peak_bytes']);
            self::assertLessThanOrEqual(self::MAX_PHP_PEAK_BYTES, $report['php_peak_bytes'], 'the converter holds no image in memory');
            self::assertSame(['.', '..'], scandir($parent->getPath()), 'no file is left behind');
            self::report('memory test', 'max rss of a conversion', (string) $report['children_max_rss_bytes'], 0.0);
        } finally {
            $parent->remove();
        }
    }
}
