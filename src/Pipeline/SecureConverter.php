<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Pipeline;

use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\BackendResolver;
use Mfonte\SecureImageConverter\Backend\BackendSelection;
use Mfonte\SecureImageConverter\Backend\Internal\OutputFile;
use Mfonte\SecureImageConverter\Concurrency\LeaseInterface;
use Mfonte\SecureImageConverter\Concurrency\SemaphoreInterface;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\ConverterInterface;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\ImageInspectorInterface;

/**
 * The library's converter: turns an untrusted image file into the output a ConversionRequest describes, as
 * ConverterInterface::convert() specifies. SecureConverterBuilder creates one with sane defaults.
 *
 * One conversion:
 *
 * 1. Checks the paths, creates a private working directory (WorkingDirectory, mode 0700) under the configured parent,
 *    and copies the input into it, reading at most max_bytes + 1 bytes from a descriptor that must be a regular file.
 *    Everything after this step reads that private copy only: it is inspected, decoded and, for a passthrough,
 *    returned, so the caller's file is never opened again (no time-of-check to time-of-use gap).
 * 2. Inspects the copy (ImageInspectorInterface: format, header, limits, no decoder) and resolves the output from
 *    the request: animation policy, upright size (the input's EXIF orientation is applied, so 5 to 8 swap width and
 *    height), output limits (Capabilities::assertOutputWithinLimits()).
 * 3. Takes a slot of the semaphore, when one is configured (ConcurrencyLimitException after the configured wait).
 * 4. Chooses the backend with BackendResolver: the requested format, then the AVIF to WEBP to input format fallback,
 *    with the reason in the result's warnings. When no available backend applies the orientation, the job is resolved
 *    again with the pixels as stored, with a warning.
 * 5. Sets the job's timeout and CPU time limit from its work (ProcessingBudget; a job above the ceiling is refused
 *    with resource_limit before it runs) and runs the backend.
 * 6. Verifies the output (OutputVerifier: sniffed format, size, frames, limits).
 * 7. Keeps the smaller file (P-12): when the output is not smaller than the input and the input already satisfies the
 *    request (PassthroughPolicy), the input copy is returned instead, byte for byte, and the result says so.
 * 8. Publishes the chosen file to the output path atomically (a new file in the output's directory renamed over it)
 *    and removes the working directory, whatever the outcome. On failure nothing is left at the output path.
 *
 * Every failure is an exception of Contract\Exception with its error code (see ConverterInterface::convert()).
 */
final class SecureConverter implements ConverterInterface
{
    /**
     * Name of the private copy of the input in the working directory.
     */
    private const SOURCE_NAME = 'source';

    /**
     * Name of the backend's verified output in the working directory.
     */
    private const CONVERTED_NAME = 'converted';

    /**
     * @var ImageInspectorInterface
     */
    private $inspector;

    /**
     * @var BackendResolver
     */
    private $resolver;

    /**
     * @var ConverterOptions
     */
    private $options;

    /**
     * @var SemaphoreInterface|null
     */
    private $semaphore;

    /**
     * @var OutputVerifier
     */
    private $verifier;

    /**
     * @var PassthroughPolicy
     */
    private $passthrough;

    /**
     * @var Capabilities|null
     */
    private $capabilities;

    /**
     * @param ImageInspectorInterface $inspector validates every input, with the limits and input formats of the converter
     * @param BackendResolver         $resolver  the backends, in order of preference
     * @param ConverterOptions|null   $options   the defaults of ConverterOptions when null
     * @param SemaphoreInterface|null $semaphore bounds the conversions that run at the same time; none when null
     */
    public function __construct(
        ImageInspectorInterface $inspector,
        BackendResolver $resolver,
        ?ConverterOptions $options = null,
        ?SemaphoreInterface $semaphore = null
    ) {
        $this->inspector = $inspector;
        $this->resolver = $resolver;
        $this->options = $options !== null ? $options : new ConverterOptions();
        $this->semaphore = $semaphore;
        $this->verifier = new OutputVerifier();
        $this->passthrough = new PassthroughPolicy();
    }

    public function getOptions(): ConverterOptions
    {
        return $this->options;
    }

    public function convert(string $inputPath, string $outputPath, ConversionRequest $request): ConversionResult
    {
        $started = microtime(true);
        list($input, $destination) = self::checkPaths($inputPath, $outputPath);
        $directory = WorkingDirectory::create($this->options->getWorkingDirectoryParent());
        $lease = null;

        try {
            $source = $directory->pathOf(self::SOURCE_NAME);
            $this->copyInput($input, $source);
            $info = $this->inspector->inspect($source);
            $capabilities = $this->getCapabilities();
            $converted = $directory->pathOf(self::CONVERTED_NAME);
            $jobs = $this->plan($source, $info, $converted, $request, $capabilities);
            if ($this->semaphore !== null) {
                $lease = $this->semaphore->acquire($this->options->getSlotWaitSeconds());
            }
            list($selection, $warnings) = $this->select($jobs, $info);
            $budget = $this->options->getBudget();
            $job = $selection->getJob();
            if (!$budget->admits($job)) {
                throw new ResourceLimitException(sprintf(
                    'This conversion needs an estimated %d seconds, above the processing ceiling of %d seconds.',
                    (int) ceil($budget->estimateSeconds($job)),
                    (int) $budget->getMaxSeconds()
                ));
            }
            $job = $budget->apply($job);
            $backendResult = $selection->getBackend()->convert($job);
            $output = $this->verifier->verify($job, $converted, $capabilities, $this->options->getMaxOutputBytes());

            $refusal = $this->passthrough->refusal($info, $request, $jobs[0]);
            $passthrough = $output->getByteSize() >= $info->getByteSize() && $refusal === null;
            if ($passthrough) {
                $warnings[] = sprintf(
                    'The %s output (%d bytes) was not smaller than the input (%d bytes): the input is returned unchanged.',
                    $output->getFormat(),
                    $output->getByteSize(),
                    $info->getByteSize()
                );
                $output = $info;
            }
            $this->publish($passthrough ? $source : $converted, $destination);

            return new ConversionResult(
                $outputPath,
                $output->getFormat(),
                $output->getWidth(),
                $output->getHeight(),
                $output->getByteSize(),
                $output->getFrameCount(),
                $backendResult->getBackend(),
                (int) max(0, round((microtime(true) - $started) * 1000)),
                $backendResult->getIsolationLevel(),
                $passthrough,
                self::warnings(array_merge($this->options->getIsolationWarnings(), $warnings))
            );
        } finally {
            if ($lease instanceof LeaseInterface) {
                $lease->release();
            }
            $directory->remove();
        }
    }

    /**
     * What this converter can do, from the inspector and the backends that are available (probed on first use, then
     * cached for the life of this object). Never throws: a failure to probe reports no backend.
     */
    public function getCapabilities(): Capabilities
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }
        $limits = $this->inspector->getLimits()->toArray();
        $level = $this->options->getIsolationLevel();

        try {
            $decoded = [];
            $outputs = [];
            $animated = [];
            $backends = [];
            foreach ($this->resolver->getCapabilities() as $name => $capabilities) {
                if (!$capabilities->isAvailable()) {
                    continue;
                }
                $backends[] = $name;
                $decoded = array_merge($decoded, $capabilities->getInputFormats(), $capabilities->getFirstFrameFormats(), $capabilities->getAnimatedInputFormats());
                $outputs = array_merge($outputs, $capabilities->getOutputFormats());
                $animated = array_merge($animated, $capabilities->getAnimatedOutputFormats());
            }
            $outputs = array_values(array_unique($outputs));

            $this->capabilities = new Capabilities(
                array_values(array_intersect($this->inspector->getInputFormats(), $decoded)),
                $outputs,
                array_values(array_intersect(array_unique($animated), $outputs, ImageFormat::animatable())),
                $backends,
                $level,
                $limits
            );
        } catch (\Exception $exception) {
            return new Capabilities([], [], [], [], $level, $limits);
        }

        return $this->capabilities;
    }

    /**
     * The jobs the request resolves to, in the order they are tried: the input turned upright, then, for an input
     * that declares an orientation, the input as stored (for hosts where no backend applies it). Each is checked
     * against the output limits before any encoder runs.
     *
     * @throws ExceptionInterface
     *
     * @return non-empty-list<BackendJob>
     */
    private function plan(string $source, ImageInfo $info, string $converted, ConversionRequest $request, Capabilities $capabilities): array
    {
        $frames = $request->resolveOutputFrameCount(
            $info->getFrameCount(),
            $capabilities->supportsAnimatedOutput($request->resolveOutputFormat($info->getFormat()))
        );
        $jobs = [];
        foreach ([true, false] as $upright) {
            if (!$upright && $info->getOrientation() === ImageInfo::ORIENTATION_NORMAL) {
                break;
            }
            list($width, $height) = $upright && $info->getOrientation() >= BackendJob::FIRST_TRANSPOSING_ORIENTATION
                ? $request->resolveOutputSize($info->getHeight(), $info->getWidth())
                : $request->resolveOutputSize($info->getWidth(), $info->getHeight());
            $capabilities->assertOutputWithinLimits($width, $height, $frames);
            $jobs[] = BackendJob::fromRequest($source, $info, $converted, $request, $upright);
        }

        return $jobs;
    }

    /**
     * Resolves the first job that a backend can perform, with the warnings to report.
     *
     * @param non-empty-list<BackendJob> $jobs
     *
     * @throws ExceptionInterface what the resolver throws for the first job, when no job can be performed
     *
     * @return array{0: BackendSelection, 1: list<string>}
     */
    private function select(array $jobs, ImageInfo $info): array
    {
        $first = null;
        foreach ($jobs as $index => $job) {
            try {
                $selection = $this->resolver->resolve($job);
            } catch (ExceptionInterface $exception) {
                $first = $first !== null ? $first : $exception;
                continue;
            }
            $warnings = [];
            if ($index > 0) {
                $warnings[] = sprintf(
                    'No available backend applies the EXIF orientation %d of this %s image: it is converted as stored.',
                    $info->getOrientation(),
                    $info->getFormat()
                );
            }
            $requested = $selection->getRequestedJob();
            $chosen = $selection->getJob();
            $reasons = $selection->getReasons();
            $reason = $reasons !== [] ? ': ' . $reasons[0] : '.';
            if ($chosen->getOutputFormat() !== $requested->getOutputFormat()) {
                $warnings[] = sprintf(
                    'The %s output is not available, fell back to %s%s',
                    $requested->getOutputFormat(),
                    $chosen->getOutputFormat(),
                    $reason
                );
            }
            if ($chosen->getFrameCount() !== $requested->getFrameCount()) {
                $warnings[] = sprintf(
                    'The animation was reduced to its first frame: no available backend writes an animated %s.',
                    $chosen->getOutputFormat()
                );
            }

            return [$selection, array_merge($warnings, $selection->getWarnings())];
        }

        throw $first;
    }

    /**
     * Copies the input into the working directory: from a descriptor that must be the regular file the path names,
     * at most max_bytes + 1 bytes.
     *
     * @throws InvalidArgumentException  when the input is not a readable regular file
     * @throws LimitExceededException    when it is larger than max_bytes
     * @throws ConversionFailedException when the copy cannot be written
     */
    private function copyInput(string $input, string $copy): void
    {
        // "n" opens without blocking, so that a FIFO put in place of the file cannot stall the conversion.
        $stream = self::attempt(static function () use ($input) {
            return @fopen($input, 'rbn');
        });
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('The input path is not a readable file.');
        }
        $target = false;
        $copied = false;

        try {
            $stat = @fstat($stream);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
                throw new InvalidArgumentException('The input path is not a regular file.');
            }
            stream_set_blocking($stream, true);
            $maxBytes = $this->inspector->getLimits()->getMaxBytes();
            if ($stat['size'] > $maxBytes) {
                throw LimitExceededException::forInput(Capabilities::LIMIT_MAX_BYTES, $maxBytes, $stat['size']);
            }
            $target = self::attempt(static function () use ($copy) {
                return @fopen($copy, 'xb');
            });
            if (!is_resource($target)) {
                $target = false;

                throw new ConversionFailedException('The input could not be copied.');
            }
            $written = self::attempt(static function () use ($stream, $target, $maxBytes) {
                $count = @stream_copy_to_stream($stream, $target, $maxBytes + 1);

                return $count !== false && @fflush($target) ? $count : false;
            });
            $closed = OutputFile::close($target);
            $target = false;
            if (!is_int($written) || !$closed) {
                throw new ConversionFailedException('The input could not be copied.');
            }
            if ($written > $maxBytes) {
                // The file grew after fstat(): what was read is refused like a large file.
                throw LimitExceededException::forInput(Capabilities::LIMIT_MAX_BYTES, $maxBytes, $written);
            }
            if ($written !== $stat['size']) {
                throw new ConversionFailedException('The input changed while it was copied.');
            }
            $copied = true;
        } finally {
            if ($target !== false) {
                OutputFile::close($target);
            }
            OutputFile::close($stream);
            if (!$copied) {
                self::attempt(static function () use ($copy): bool {
                    return @unlink($copy);
                });
            }
        }
    }

    /**
     * Runs a file system call; false when it throws an \ErrorException (an error handler that turns the warnings of
     * silenced calls into exceptions), so that a failed read or write is never anything but the converter's own
     * exception.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T|false
     */
    private static function attempt(callable $call)
    {
        try {
            return $call();
        } catch (\ErrorException $exception) {
            return false;
        }
    }

    /**
     * Copies the chosen file next to the destination and renames it over the destination.
     *
     * @throws ConversionFailedException when it cannot be written
     */
    private function publish(string $file, string $destination): void
    {
        if (is_dir($destination)) {
            throw new InvalidArgumentException('The output path is a directory.');
        }
        OutputFile::copy($file, $destination, PHP_INT_MAX);
    }

    /**
     * The input path resolved (symbolic links of the caller are followed: the input is copied, never written) and the
     * output path made absolute in its existing directory.
     *
     * @throws InvalidArgumentException when a path is not usable
     *
     * @return array{0: string, 1: string}
     */
    private static function checkPaths(string $inputPath, string $outputPath): array
    {
        foreach ([$inputPath, $outputPath] as $path) {
            if ($path === '' || strpos($path, "\0") !== false || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]+:/', $path) === 1) {
                throw new InvalidArgumentException('The input and output paths must be local file paths, not URLs or stream wrappers.');
            }
        }
        clearstatcache(true);
        $input = realpath($inputPath);
        if (!is_string($input) || !is_file($input)) {
            throw new InvalidArgumentException('The input path is not a readable regular file.');
        }
        $name = basename($outputPath);
        $directory = realpath(\dirname($outputPath));
        if ($name === '' || $name === '.' || $name === '..' || substr($outputPath, -1) === '/' || !is_string($directory) || !is_dir($directory) || !is_writable($directory)) {
            throw new InvalidArgumentException('The output path must name a file in an existing writable directory.');
        }
        $destination = rtrim($directory, '/') . '/' . $name;
        if (is_dir($destination)) {
            throw new InvalidArgumentException('The output path is a directory.');
        }
        $resolved = realpath($destination);
        if ($destination === $input || (is_string($resolved) && $resolved === $input)) {
            throw new InvalidArgumentException('The output path must differ from the input path.');
        }

        return [$input, $destination];
    }

    /**
     * Warnings in the format of ConversionResult: printable ASCII, cut to its maximum length, without duplicates.
     *
     * @param list<string> $warnings
     *
     * @return list<string>
     */
    private static function warnings(array $warnings): array
    {
        $checked = [];
        foreach ($warnings as $warning) {
            $warning = (string) preg_replace('/[^\x20-\x7E]/', '?', $warning);
            if (strlen($warning) > ConversionResult::WARNING_MAX_LENGTH) {
                $warning = substr($warning, 0, ConversionResult::WARNING_MAX_LENGTH - 3) . '...';
            }
            if ($warning !== '' && !in_array($warning, $checked, true)) {
                $checked[] = $warning;
            }
        }

        return $checked;
    }
}
