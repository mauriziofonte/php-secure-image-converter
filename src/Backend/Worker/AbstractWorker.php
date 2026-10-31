<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Worker;

/**
 * Base of the PHP CLI workers that run GD and Imagick outside the calling process.
 *
 * A worker runs as "php -n [-d extension=...] resources/workers/<name>.php <mode>" in the job's working directory,
 * started by its backend through the ProcessRunner. It loads no php.ini (-n), no Composer autoloader and no other
 * library class: the entry script requires this file and the concrete worker only, so that the sandbox exposes
 * nothing but them.
 *
 * | Mode    | Does                                                                                         |
 * | ------- | -------------------------------------------------------------------------------------------- |
 * | probe   | prints {"inputs": [...], "outputs": [...], "lossless": [...]}, the formats its library claims |
 * | convert | reads JOB_FILE from the current directory, converts, prints {"ok": true} or an error code     |
 *
 * Exit codes: EXIT_OK, EXIT_INVALID (unusable job file or mode), EXIT_FAILED (decoding or encoding failed),
 * EXIT_RESOURCE_LIMIT (the library refused the image for a resource limit). Messages are fixed English sentences
 * without paths.
 *
 * @internal
 */
abstract class AbstractWorker
{
    public const EXIT_OK = 0;

    public const EXIT_INVALID = 1;

    public const EXIT_FAILED = 2;

    public const EXIT_RESOURCE_LIMIT = 3;

    /**
     * Name of the job file in the working directory.
     */
    public const JOB_FILE = 'job.json';

    /**
     * Formats a worker may name, as ImageFormat values.
     */
    public const FORMATS = ['jpeg', 'png', 'gif', 'bmp', 'webp', 'avif', 'tiff'];

    public const FITS = ['contain', 'cover', 'fill'];

    private const JOB_MAX_BYTES = 65536;

    /**
     * Runs a mode and prints its JSON answer.
     *
     * @param list<string> $arguments the command-line arguments, the script name first
     */
    final public function run(array $arguments): int
    {
        $mode = $arguments[1] ?? '';

        try {
            if ($mode === 'probe') {
                self::answer($this->probe());

                return self::EXIT_OK;
            }
            if ($mode !== 'convert') {
                throw new \RuntimeException('The worker mode must be probe or convert.', self::EXIT_INVALID);
            }
            $this->convert(self::readJob(self::JOB_FILE));
            self::answer(['ok' => true]);

            return self::EXIT_OK;
        } catch (\Throwable $exception) {
            $code = $exception instanceof \RuntimeException
                && in_array($exception->getCode(), [self::EXIT_INVALID, self::EXIT_FAILED, self::EXIT_RESOURCE_LIMIT], true)
                ? (int) $exception->getCode()
                : self::EXIT_FAILED;
            self::answer(['ok' => false, 'code' => $code, 'error' => $exception instanceof \RuntimeException ? $exception->getMessage() : 'The worker failed.']);

            return $code;
        }
    }

    /**
     * The formats the library claims to decode, encode, and encode losslessly.
     *
     * @return array{inputs: list<string>, outputs: list<string>, lossless: list<string>}
     */
    abstract protected function probe(): array;

    /**
     * Converts a validated job; throws a \RuntimeException whose code is an EXIT_* value on failure.
     *
     * @param array{input: string, input_format: string, input_width: int, input_height: int, output: string, output_format: string, width: int, height: int, fit: string, quality: int, effort: int, lossless: bool, strip: bool, keep_icc: bool, memory_bytes: int} $job
     */
    abstract protected function convert(array $job): void;

    /**
     * The centred region of the input that covers the output size: left, top, width, height.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    final protected static function coverRegion(int $width, int $height, int $targetWidth, int $targetHeight): array
    {
        $scale = max($targetWidth / $width, $targetHeight / $height);
        $regionWidth = max(1, min($width, (int) round($targetWidth / $scale)));
        $regionHeight = max(1, min($height, (int) round($targetHeight / $scale)));

        return [intdiv($width - $regionWidth, 2), intdiv($height - $regionHeight, 2), $regionWidth, $regionHeight];
    }

    /**
     * Fails unless the output file exists and is not empty.
     */
    final protected static function assertWritten(string $path): void
    {
        clearstatcache(true, $path);
        if (!is_file($path) || (int) filesize($path) < 1) {
            throw new \RuntimeException('The output could not be encoded.', self::EXIT_FAILED);
        }
    }

    /**
     * Reads and validates a job file.
     *
     * @return array{input: string, input_format: string, input_width: int, input_height: int, output: string, output_format: string, width: int, height: int, fit: string, quality: int, effort: int, lossless: bool, strip: bool, keep_icc: bool, memory_bytes: int}
     */
    final public static function readJob(string $path): array
    {
        $json = @file_get_contents($path, false, null, 0, self::JOB_MAX_BYTES + 1);
        $job = is_string($json) && strlen($json) <= self::JOB_MAX_BYTES ? json_decode($json, true) : null;
        $invalid = new \RuntimeException('The job file is not valid.', self::EXIT_INVALID);
        if (!is_array($job)) {
            throw $invalid;
        }
        foreach (['input', 'output'] as $key) {
            if (!is_string($job[$key] ?? null) || preg_match('/\A' . $key . '\.[a-z]{3,4}\z/', $job[$key]) !== 1) {
                throw $invalid;
            }
        }
        foreach (['input_format', 'output_format'] as $key) {
            if (!in_array($job[$key] ?? null, self::FORMATS, true)) {
                throw $invalid;
            }
        }
        if (!in_array($job['fit'] ?? null, self::FITS, true)) {
            throw $invalid;
        }
        $ranges = [
            'input_width' => [1, 2147483647],
            'input_height' => [1, 2147483647],
            'width' => [1, 65535],
            'height' => [1, 65535],
            'quality' => [1, 100],
            'effort' => [0, 9],
            'memory_bytes' => [1, PHP_INT_MAX],
        ];
        foreach ($ranges as $key => $range) {
            if (!is_int($job[$key] ?? null) || $job[$key] < $range[0] || $job[$key] > $range[1]) {
                throw $invalid;
            }
        }
        foreach (['lossless', 'strip', 'keep_icc'] as $key) {
            if (!is_bool($job[$key] ?? null)) {
                throw $invalid;
            }
        }

        return [
            'input' => $job['input'],
            'input_format' => $job['input_format'],
            'input_width' => $job['input_width'],
            'input_height' => $job['input_height'],
            'output' => $job['output'],
            'output_format' => $job['output_format'],
            'width' => $job['width'],
            'height' => $job['height'],
            'fit' => $job['fit'],
            'quality' => $job['quality'],
            'effort' => $job['effort'],
            'lossless' => $job['lossless'],
            'strip' => $job['strip'],
            'keep_icc' => $job['keep_icc'],
            'memory_bytes' => $job['memory_bytes'],
        ];
    }

    /**
     * @param array<string, mixed> $answer
     */
    private static function answer(array $answer): void
    {
        echo json_encode($answer), "\n";
    }
}
