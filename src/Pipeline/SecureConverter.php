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
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Contract\Exception\OutputVerificationException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\IsolationDetectorInterface;
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
 *    with conversion_timeout before it runs) and runs the backend.
 * 6. Verifies the output (OutputVerifier: sniffed format, size, frames, limits).
 * 7. Keeps the smaller file (P-12): when the output is not smaller than the input and the input already satisfies the
 *    request (PassthroughPolicy), the input copy is returned instead, byte for byte, and the result says so. Its
 *    SHA-256, taken before it was inspected, must still match: a backend running as this user could rewrite it.
 * 8. Publishes the chosen file to the output path atomically (a new file in the output's directory, checked again,
 *    renamed over it)
 *    and removes the working directory, whatever the outcome. On failure nothing is left at the output path.
 *
 * Every failure is an exception of Contract\Exception with its error code (see ConverterInterface::convert()).
 *
 * When choosing or running the backend fails with ConversionFailedException and the converter was given the isolation
 * detector it was built with, the detector probes the host again (refresh()) if its result is at least
 * ISOLATION_RECHECK_SECONDS old: a cached level that no longer works (a sandbox that a new container security profile
 * blocks) is then replaced in the detector's cache, and the next converter built with it runs at the level the host
 * provides now, instead of failing every conversion until the cache expires. The failed conversion still fails, and
 * this converter keeps the level it was built with.
 */
final class SecureConverter implements ConverterInterface
{
    /**
     * Minimum age of the isolation detection before a failed conversion makes the detector probe again, so that
     * failing inputs cannot make every conversion pay for the probes.
     */
    public const ISOLATION_RECHECK_SECONDS = 60;

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
     * @var IsolationDetectorInterface|null
     */
    private $detector;

    /**
     * @param ImageInspectorInterface         $inspector validates every input, with the limits and input formats of
     *                                                   the converter
     * @param BackendResolver                 $resolver  the backends, in order of preference
     * @param ConverterOptions|null           $options   the defaults of ConverterOptions when null
     * @param SemaphoreInterface|null         $semaphore bounds the conversions that run at the same time; none when
     *                                                   null
     * @param IsolationDetectorInterface|null $detector  the detector whose result chose the isolation level of the
     *                                                   backends' runner, probed again after a failed conversion (see
     *                                                   the class description); none when null
     */
    public function __construct(
        ImageInspectorInterface $inspector,
        BackendResolver $resolver,
        ?ConverterOptions $options = null,
        ?SemaphoreInterface $semaphore = null,
        ?IsolationDetectorInterface $detector = null
    ) {
        $this->inspector = $inspector;
        $this->resolver = $resolver;
        $this->options = $options !== null ? $options : new ConverterOptions();
        $this->semaphore = $semaphore;
        $this->detector = $detector;
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
            // A backend runs as this user at the rlimit level and below, and can reach the copy: what was inspected
            // is recorded, so that a passthrough never publishes a copy changed since.
            $digest = self::digest($source);
            $info = $this->inspector->inspect($source);
            $capabilities = $this->getCapabilities();
            $converted = $directory->pathOf(self::CONVERTED_NAME);
            $jobs = $this->plan($source, $info, $converted, $request, $capabilities);
            if ($this->semaphore !== null) {
                $lease = $this->semaphore->acquire($this->options->getSlotWaitSeconds());
            }
            try {
                list($selection, $warnings) = $this->select($jobs, $info);
                $budget = $this->options->getBudget();
                $job = $selection->getJob();
                if (!$budget->admits($job)) {
                    throw new ConversionTimeoutException(sprintf(
                        'This conversion needs an estimated %d seconds, above the processing ceiling of %d seconds.',
                        (int) ceil($budget->estimateSeconds($job)),
                        (int) $budget->getMaxSeconds()
                    ));
                }
                $job = $budget->apply($job);
                $backendResult = $selection->getBackend()->convert($job);
            } catch (ConversionFailedException $exception) {
                $this->recheckIsolation();

                throw $exception;
            }
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
            if ($passthrough) {
                $this->publish($source, $destination, static function (string $copy) use ($digest): void {
                    if (self::digest($copy) !== $digest) {
                        throw new OutputVerificationException('The private copy of the input changed during the conversion.');
                    }
                });
            } else {
                $verifier = $this->verifier;
                $maxBytes = $this->options->getMaxOutputBytes();
                $verified = $output;
                // The copy that is published is verified again: the backend's file could change after its check.
                $this->publish($converted, $destination, static function (string $copy) use ($verifier, $job, $capabilities, $maxBytes, $verified): void {
                    if ($verifier->verify($job, $copy, $capabilities, $maxBytes)->getByteSize() !== $verified->getByteSize()) {
                        throw new OutputVerificationException('The output changed after it was verified.');
                    }
                });
            }

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
     * Makes the detector probe again when its result is at least ISOLATION_RECHECK_SECONDS old (see the class
     * description). Never throws: the detector reports a level it cannot probe as unavailable.
     */
    private function recheckIsolation(): void
    {
        if ($this->detector === null) {
            return;
        }
        if (time() - $this->detector->detect()->getDetectedAt() >= self::ISOLATION_RECHECK_SECONDS) {
            $this->detector->refresh();
        }
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
     * @throws ExceptionInterface when no job can be performed: what the resolver throws for the last job, or for an
     *                            earlier one when the last fails with ConversionFailedException and it does not
     *
     * @return array{0: BackendSelection, 1: list<string>}
     */
    private function select(array $jobs, ImageInfo $info): array
    {
        $failure = null;
        foreach ($jobs as $index => $job) {
            try {
                $selection = $this->resolver->resolve($job);
            } catch (ExceptionInterface $exception) {
                // A later job (the pixels as stored) tells more about the input than the upright one, which fails for
                // the orientation alone, unless it fails with the generic conversion_failed only.
                if ($failure === null || !$exception instanceof ConversionFailedException || $failure instanceof ConversionFailedException) {
                    $failure = $exception;
                }
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

        throw $failure;
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
     * Copies the chosen file next to the destination, checks the copy and renames it over the destination.
     *
     * @param callable(string): void $verify called with the path of the copy; throws to refuse it
     *
     * @throws ConversionFailedException   when it cannot be written
     * @throws OutputVerificationException when the copy is refused
     */
    private function publish(string $file, string $destination, callable $verify): void
    {
        if (is_dir($destination)) {
            throw new InvalidArgumentException('The output path is a directory.');
        }
        OutputFile::copy($file, $destination, PHP_INT_MAX, $verify);
    }

    /**
     * SHA-256 of a file.
     *
     * @throws ConversionFailedException when it cannot be read
     */
    private static function digest(string $path): string
    {
        $digest = self::attempt(static function () use ($path) {
            return @hash_file('sha256', $path);
        });
        if (!is_string($digest)) {
            throw new ConversionFailedException('The private copy of the input could not be read.');
        }

        return $digest;
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
