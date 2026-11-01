<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Security;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Pipeline\SecureConverter;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageCorpus;
use Mfonte\SecureImageConverter\Tests\Fixtures\Security\Mutator;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Fuzzes the whole pipeline: mutations (see Mutator) of the corpus files that the inspector accepts, converted to WEBP
 * by SecureConverter with every enabled backend under the rules of SecurityTestCase (converted, or refused with a 4xx
 * code, in bounded time, nothing left behind). Half of the mutations go anywhere in the file (Mutator::mutate()), and
 * most of those are refused by the inspector; the other half overwrite the data past the headers in place
 * (Mutator::mutateData()), so that the file passes the inspector and its damaged data reaches a decoder. Each file is
 * inspected first, to tell the refusals of the inspector from those that came later: at least MIN_DATA_PASSED of the
 * data mutations must pass the inspector. The split of the outcomes is printed (stderr) and, with SIC_SECURITY_REPORT, each conversion is appended
 * to the report as "pipeline fuzz <iteration> (<base file>), <mode> mutation <stage>, outcome, milliseconds".
 *
 * | Variable             | Meaning                                                                           |
 * | -------------------- | --------------------------------------------------------------------------------- |
 * | SIC_FUZZ_SEED        | the seed (default 20261101): a failure is reproduced by running again with it     |
 * | SIC_FUZZ_CONVERSIONS | how many mutated files to convert (default 100; CI's security job runs 500)       |
 * | SIC_FUZZ_FAILURES    | a directory where each failing input is saved as pipeline-<seed>-<iteration>.bin  |
 *
 * For a longer local run: SIC_FUZZ_SEED=$RANDOM SIC_FUZZ_CONVERSIONS=20000 vendor/bin/phpunit --group security
 * --filter PipelineFuzzTest (in the reference image, see tests/docker/Dockerfile).
 *
 * @covers \Mfonte\SecureImageConverter\Pipeline\SecureConverter
 * @covers \Mfonte\SecureImageConverter\Backend\AbstractBackend
 *
 * @group security
 */
#[CoversClass(SecureConverter::class)]
#[CoversClass(AbstractBackend::class)]
#[Group('security')]
final class PipelineFuzzTest extends SecurityTestCase
{
    /**
     * Largest corpus file mutated, so that each conversion stays short.
     */
    private const MAX_BASE_BYTES = 65536;

    /**
     * Failures reported in full; the others are counted.
     */
    private const MAX_REPORTED = 10;

    /**
     * Smallest share of the data mutations that must pass the inspector (about two thirds do with the default seed),
     * checked from MIN_DATA_COUNTED data mutations on.
     */
    private const MIN_DATA_PASSED = 0.4;

    private const MIN_DATA_COUNTED = 50;

    public function testMutatedFilesAreRefusedOrConvertedWithinBounds(): void
    {
        $converter = self::converter();
        $seed = Mutator::seedFromEnvironment();
        $iterations = Mutator::countFromEnvironment('SIC_FUZZ_CONVERSIONS', 100);
        $mutator = new Mutator('pipeline:' . $seed);
        $bases = [];
        foreach (ImageCorpus::cases() as $path => $case) {
            if (!isset($case['expect']['error']) && strlen($case['bytes']) <= self::MAX_BASE_BYTES) {
                $bases[$path] = [$path, $case['bytes']];
            }
        }
        ksort($bases);
        $bases = array_values($bases);
        self::assertNotEmpty($bases);
        // The inspector of the converter: every input format but SVG, the default limits.
        $inspector = new ImageInspector(null, array_values(array_diff(ImageFormat::all(), [ImageFormat::SVG])));
        $failures = [];
        $failed = 0;
        $outcomes = [];
        $split = [];

        for ($iteration = 0; $iteration < $iterations; ++$iteration) {
            $base = $mutator->pick($bases);
            $mode = $iteration % 2 === 0 ? 'anywhere' : 'data';
            list($bytes, $mutation) = $mode === 'data' ? $mutator->mutateData($base[1]) : $mutator->mutate($base[1], $mutator->pick($bases)[1]);
            $stage = self::passesInspector($inspector, $bytes) ? 'passed the inspector' : 'refused by the inspector';
            list($outcome, $seconds, $problems) = $this->convertHostile($converter, $bytes, new ConversionRequest());
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
            $key = sprintf('%s mutation, %s: %s', $mode, $stage, $outcome);
            $split[$key] = ($split[$key] ?? 0) + 1;
            self::report(sprintf('pipeline fuzz %d (%s)', $iteration, $base[0]), sprintf('%s mutation, %s', $mode, $stage), $outcome, $seconds);
            if ($problems !== []) {
                ++$failed;
                if (count($failures) < self::MAX_REPORTED) {
                    $failures[] = sprintf('seed %s, iteration %d, %s (%s): %s', $seed, $iteration, $base[0], $mutation, implode('; ', $problems));
                }
                $directory = getenv('SIC_FUZZ_FAILURES');
                if (is_string($directory) && $directory !== '' && is_dir($directory)) {
                    file_put_contents(sprintf('%s/pipeline-%s-%d.bin', rtrim($directory, '/'), preg_replace('/[^A-Za-z0-9_-]/', '_', $seed), $iteration), $bytes);
                }
            }
        }

        ksort($split);
        $summary = '';
        foreach ($split as $key => $count) {
            $summary .= sprintf("%6d  %s\n", $count, $key);
        }
        fwrite(STDERR, sprintf("\nPipelineFuzzTest, seed %s, %d conversions:\n%s", $seed, $iterations, $summary));

        self::assertSame([], $failures, sprintf("%d of %d mutated files failed; outcomes:\n%s", $failed, $iterations, $summary));
        self::assertSame($iterations, array_sum($outcomes));
        $data = intdiv($iterations, 2);
        if ($data >= self::MIN_DATA_COUNTED) {
            $passed = 0;
            foreach ($split as $key => $count) {
                if (strpos($key, 'data mutation, passed the inspector:') === 0) {
                    $passed += $count;
                }
            }
            self::assertGreaterThanOrEqual(self::MIN_DATA_PASSED * $data, $passed, "too few data mutations pass the inspector; outcomes:\n" . $summary);
        }
    }

    /**
     * Whether the inspector accepts the file, so that it goes on to a backend and its decoder.
     */
    private static function passesInspector(ImageInspector $inspector, string $bytes): bool
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);
        try {
            $inspector->inspectStream($stream);

            return true;
        } catch (ExceptionInterface $exception) {
            return false;
        } finally {
            fclose($stream);
        }
    }
}
