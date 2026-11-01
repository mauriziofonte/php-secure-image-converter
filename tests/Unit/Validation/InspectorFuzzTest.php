<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Validation;

use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageCorpus;
use Mfonte\SecureImageConverter\Tests\Fixtures\Security\Mutator;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use Mfonte\SecureImageConverter\Validation\Limits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Fuzzes the inspector with mutations of the corpus (see Mutator), every format enabled: each mutated file is
 * accepted or refused with an error code of the inspector, within 50 ms, and nothing else escapes (tests/bootstrap.php
 * turns a PHP notice or warning into an exception, so that fails the run too). An accepted file is within the
 * default limits and reports its byte size.
 *
 * | Variable            | Meaning                                                                            |
 * | ------------------- | ---------------------------------------------------------------------------------- |
 * | SIC_FUZZ_SEED       | the seed (default 20261101): a failure is reproduced by running again with it      |
 * | SIC_FUZZ_ITERATIONS | how many mutated files to inspect (default 5000; CI's security job runs 200,000)   |
 * | SIC_FUZZ_FAILURES   | a directory where each failing input is saved as <seed>-<iteration>.bin            |
 *
 * For a longer local run: SIC_FUZZ_SEED=$RANDOM SIC_FUZZ_ITERATIONS=1000000 vendor/bin/phpunit --group fuzz
 *
 * @covers \Mfonte\SecureImageConverter\Validation\ImageInspector
 *
 * @group fuzz
 */
#[CoversClass(ImageInspector::class)]
#[Group('fuzz')]
final class InspectorFuzzTest extends TestCase
{
    /**
     * Largest time one inspection may take, as in ImageCorpusTest.
     */
    private const MAX_SECONDS = 0.05;

    /**
     * Largest corpus file mutated: the larger ones (EXIF of 1 MiB) add nothing a smaller one does not.
     */
    private const MAX_BASE_BYTES = 524288;

    /**
     * Failures reported in full; the others are counted.
     */
    private const MAX_REPORTED = 10;

    /**
     * Error codes the inspector refuses a file with.
     */
    private const REFUSALS = [
        ErrorCode::UNSUPPORTED_FORMAT,
        ErrorCode::INVALID_IMAGE,
        ErrorCode::FILE_TOO_LARGE,
        ErrorCode::IMAGE_TOO_LARGE,
    ];

    public function testMutatedCorpusFilesAreAcceptedOrRefusedInBoundedTime(): void
    {
        $seed = Mutator::seedFromEnvironment();
        $iterations = Mutator::countFromEnvironment('SIC_FUZZ_ITERATIONS', 5000);
        $bases = self::bases();
        $mutator = new Mutator($seed);
        $inspector = new ImageInspector(null, ImageFormat::all());
        $limits = new Limits();
        $failures = [];
        $failed = 0;
        $outcomes = [];

        for ($iteration = 0; $iteration < $iterations; ++$iteration) {
            $base = $mutator->pick($bases);
            list($bytes, $mutation) = $mutator->mutate($base[1], $mutator->pick($bases)[1]);
            list($outcome, $seconds, $problem) = self::inspect($inspector, $limits, $bytes);
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
            if ($problem === null && $seconds >= self::MAX_SECONDS) {
                $problem = sprintf('took %.1f ms', $seconds * 1000);
            }
            if ($problem !== null) {
                ++$failed;
                if (count($failures) < self::MAX_REPORTED) {
                    $failures[] = sprintf('seed %s, iteration %d, %s (%s): %s', $seed, $iteration, $base[0], $mutation, $problem);
                }
                self::saveFailure($seed, $iteration, $bytes);
            }
        }

        self::assertSame([], $failures, sprintf('%d of %d mutated files failed', $failed, $iterations));
        self::assertSame($iterations, array_sum($outcomes));
        self::assertGreaterThan(1, count($outcomes), 'the mutations reach more than one outcome');
    }

    /**
     * The corpus files mutated, by path, in a fixed order.
     *
     * @return non-empty-list<array{0: string, 1: string}>
     */
    private static function bases(): array
    {
        $bases = [];
        foreach (ImageCorpus::cases() as $path => $case) {
            if (strlen($case['bytes']) <= self::MAX_BASE_BYTES) {
                $bases[$path] = [$path, $case['bytes']];
            }
        }
        ksort($bases);
        self::assertNotEmpty($bases);

        return array_values($bases);
    }

    /**
     * Inspects one file, the best of three runs when the first is slow (a busy machine).
     *
     * @return array{0: string, 1: float, 2: string|null} the outcome (format or error code), the seconds taken and
     *                                                    the problem, if any
     */
    private static function inspect(ImageInspector $inspector, Limits $limits, string $bytes): array
    {
        $seconds = INF;
        $outcome = '';
        $problem = null;
        for ($run = 0; $run < 3 && $seconds >= self::MAX_SECONDS; ++$run) {
            $stream = CountingStreamWrapper::open($bytes);
            $start = microtime(true);
            try {
                $info = $inspector->inspectStream($stream);
                $outcome = $info->getFormat();
                $problem = self::check($info, $limits, strlen($bytes));
            } catch (ExceptionInterface $exception) {
                $outcome = $exception->getErrorCode();
                $problem = in_array($outcome, self::REFUSALS, true) ? null : 'refused with ' . $outcome . ': ' . $exception->getMessage();
            } catch (\Throwable $throwable) {
                $outcome = get_class($throwable);
                $problem = sprintf('threw %s: %s at %s:%d', get_class($throwable), $throwable->getMessage(), $throwable->getFile(), $throwable->getLine());
            }
            $seconds = min($seconds, microtime(true) - $start);
            fclose($stream);
        }

        return [$outcome, $seconds, $problem];
    }

    /**
     * What is wrong with an accepted file, or null.
     */
    private static function check(ImageInfo $info, Limits $limits, int $byteSize): ?string
    {
        if ($info->getByteSize() !== $byteSize) {
            return sprintf('reported %d bytes instead of %d', $info->getByteSize(), $byteSize);
        }
        if ($info->getWidth() < 1 || $info->getHeight() < 1 || $info->getWidth() > $limits->getMaxWidth() || $info->getHeight() > $limits->getMaxHeight()) {
            return sprintf('accepted %d x %d pixels', $info->getWidth(), $info->getHeight());
        }
        if ($info->getFrameCount() < 1 || $info->getFrameCount() > $limits->getMaxFrames()) {
            return sprintf('accepted %d frames', $info->getFrameCount());
        }

        return null;
    }

    private static function saveFailure(string $seed, int $iteration, string $bytes): void
    {
        $directory = getenv('SIC_FUZZ_FAILURES');
        if (is_string($directory) && $directory !== '' && is_dir($directory)) {
            file_put_contents(sprintf('%s/%s-%d.bin', rtrim($directory, '/'), preg_replace('/[^A-Za-z0-9_-]/', '_', $seed), $iteration), $bytes);
        }
    }
}
