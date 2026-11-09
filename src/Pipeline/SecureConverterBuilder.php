<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Pipeline;

use Mfonte\SecureImageConverter\Backend\BackendFactory;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Concurrency\SemaphoreInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Isolation\IsolationDetector;
use Mfonte\SecureImageConverter\Isolation\IsolationDetectorInterface;
use Mfonte\SecureImageConverter\Isolation\IsolationPolicy;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessRunner;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use Mfonte\SecureImageConverter\Validation\Limits;

/**
 * Creates a SecureConverter with sane defaults. Immutable: every with*() method returns a modified copy, and build()
 * can be called any number of times.
 *
 * | Setting          | Default                                                                     |
 * | ---------------- | --------------------------------------------------------------------------- |
 * | limits           | Limits defaults (20 MiB, 8192 x 8192, 40 megapixels, 300 frames)            |
 * | input formats    | ImageInspector::DEFAULT_INPUT_FORMATS (JPEG, PNG, GIF, BMP)                 |
 * | isolation policy | IsolationPolicy::bestEffort(sandbox): the strongest level, warned if lower  |
 * | process options  | ProcessOptions defaults; the timeout and CPU time follow ProcessingBudget   |
 * | backend options  | BackendOptions defaults (2 GiB memory ceiling, capability cache)           |
 * | backends         | every backend of BackendFactory::NAMES, in that order                       |
 * | semaphore        | none: bound concurrent conversions with Concurrency\Semaphore               |
 * | converter options| ConverterOptions defaults                                                   |
 *
 * build() detects the isolation level (cached by IsolationDetector), applies the policy, and creates the runner, the
 * backends and the inspector; the converter is given the detector, so that a failed conversion can make it probe the
 * host again (see SecureConverter). The working directory parent of the converter options is given to the backends too,
 * unless their options name one, so that every private directory of a conversion lives under the caller's parent.
 */
final class SecureConverterBuilder
{
    /**
     * @var Limits|null
     */
    private $limits;

    /**
     * @var list<string>|null
     */
    private $inputFormats;

    /**
     * @var IsolationPolicy|null
     */
    private $policy;

    /**
     * @var IsolationDetectorInterface|null
     */
    private $detector;

    /**
     * @var ProcessOptions|null
     */
    private $processOptions;

    /**
     * @var BackendOptions|null
     */
    private $backendOptions;

    /**
     * @var list<string>|null
     */
    private $backends;

    /**
     * @var SemaphoreInterface|null
     */
    private $semaphore;

    /**
     * @var ConverterOptions|null
     */
    private $options;

    public function withLimits(Limits $limits): self
    {
        $clone = clone $this;
        $clone->limits = $limits;

        return $clone;
    }

    /**
     * @param list<string> $formats ImageFormat values accepted as input (see ImageInspector)
     */
    public function withInputFormats(array $formats): self
    {
        $clone = clone $this;
        $clone->inputFormats = $formats;

        return $clone;
    }

    public function withIsolationPolicy(IsolationPolicy $policy): self
    {
        $clone = clone $this;
        $clone->policy = $policy;

        return $clone;
    }

    /**
     * The detector of the isolation level; an IsolationDetector with the process options when not given.
     */
    public function withIsolationDetector(IsolationDetectorInterface $detector): self
    {
        $clone = clone $this;
        $clone->detector = $detector;

        return $clone;
    }

    public function withProcessOptions(ProcessOptions $options): self
    {
        $clone = clone $this;
        $clone->processOptions = $options;

        return $clone;
    }

    public function withBackendOptions(BackendOptions $options): self
    {
        $clone = clone $this;
        $clone->backendOptions = $options;

        return $clone;
    }

    /**
     * @param list<string> $names backends to enable (BackendFactory::parseNames() reads a configuration value)
     */
    public function withBackends(array $names): self
    {
        $clone = clone $this;
        $clone->backends = $names;

        return $clone;
    }

    public function withSemaphore(?SemaphoreInterface $semaphore): self
    {
        $clone = clone $this;
        $clone->semaphore = $semaphore;

        return $clone;
    }

    public function withConverterOptions(ConverterOptions $options): self
    {
        $clone = clone $this;
        $clone->options = $options;

        return $clone;
    }

    /**
     * Creates the converter.
     *
     * @throws IsolationUnavailableException when a strict policy requires a level the host does not provide
     * @throws InvalidArgumentException      when a setting is not valid (an unknown backend or input format)
     */
    public function build(): SecureConverter
    {
        $processOptions = $this->processOptions !== null ? $this->processOptions : new ProcessOptions();
        $detector = $this->detector !== null ? $this->detector : new IsolationDetector($processOptions);
        $policy = $this->policy !== null ? $this->policy : IsolationPolicy::bestEffort();
        $decision = $policy->resolve($detector->detect());
        $options = ($this->options !== null ? $this->options : new ConverterOptions())
            ->withIsolation($decision->getLevel(), $decision->getWarnings());
        $backendOptions = $this->backendOptions !== null ? $this->backendOptions : new BackendOptions();
        if ($backendOptions->getWorkingDirectoryParent() === null && $options->getWorkingDirectoryParent() !== null) {
            $backendOptions = $backendOptions->withWorkingDirectoryParent($options->getWorkingDirectoryParent());
        }
        $runner = new ProcessRunner($decision->getLevel(), $processOptions);

        return new SecureConverter(
            new ImageInspector($this->limits, $this->inputFormats),
            BackendFactory::createResolver($runner, $backendOptions, $this->backends),
            $options,
            $this->semaphore,
            $detector
        );
    }
}
