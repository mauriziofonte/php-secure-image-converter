<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Security;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Pipeline\SecureConverter;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageCorpus;
use Mfonte\SecureImageConverter\Tests\Fixtures\Security\Mutator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Fuzzes the whole pipeline: mutations (see Mutator) of the corpus files that the inspector accepts, converted to WEBP
 * by SecureConverter with every enabled backend under the rules of SecurityTestCase (converted, or refused with a 4xx
 * code, in bounded time, nothing left behind). The mutations that pass the header checks reach the decoders with
 * damaged data.
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
        $failures = [];
        $failed = 0;
        $outcomes = [];

        for ($iteration = 0; $iteration < $iterations; ++$iteration) {
            $base = $mutator->pick($bases);
            list($bytes, $mutation) = $mutator->mutate($base[1], $mutator->pick($bases)[1]);
            list($outcome, , $problems) = $this->convertHostile($converter, $bytes, new ConversionRequest());
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
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

        self::assertSame([], $failures, sprintf('%d of %d mutated files failed', $failed, $iterations));
        self::assertSame($iterations, array_sum($outcomes));
    }
}
