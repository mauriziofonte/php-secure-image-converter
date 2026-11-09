<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

/**
 * The backend BackendResolver chose for a job, the job that backend will perform, and why.
 *
 * The chosen job differs from the requested one when the resolver fell back: to another output format (AVIF to WEBP
 * to the input format), or to the first frame of an animation when no capable backend keeps every frame in that
 * format. The reasons say, for every backend and variant tried before the chosen one, why it was not chosen; the
 * warnings say which requested metadata the chosen backend drops (when every capable backend drops some). Reasons and
 * warnings are English sentences without paths, for logs and diagnostics. Immutable value object.
 */
final class BackendSelection
{
    /**
     * @var BackendInterface
     */
    private $backend;

    /**
     * @var BackendJob
     */
    private $job;

    /**
     * @var BackendJob
     */
    private $requestedJob;

    /**
     * @var list<string>
     */
    private $reasons;

    /**
     * @var list<string>
     */
    private $warnings;

    /**
     * @param BackendInterface $backend      the chosen backend
     * @param BackendJob       $job          the job it will perform
     * @param BackendJob       $requestedJob the job the resolver was asked for
     * @param list<string>     $reasons      why the backends and variants tried before were not chosen
     * @param list<string>     $warnings     what the chosen backend drops of the requested metadata
     */
    public function __construct(
        BackendInterface $backend,
        BackendJob $job,
        BackendJob $requestedJob,
        array $reasons = [],
        array $warnings = []
    ) {
        $this->backend = $backend;
        $this->job = $job;
        $this->requestedJob = $requestedJob;
        $this->reasons = $reasons;
        $this->warnings = $warnings;
    }

    public function getBackend(): BackendInterface
    {
        return $this->backend;
    }

    /**
     * The job the chosen backend performs.
     */
    public function getJob(): BackendJob
    {
        return $this->job;
    }

    /**
     * The job the resolver was asked for.
     */
    public function getRequestedJob(): BackendJob
    {
        return $this->requestedJob;
    }

    /**
     * Whether the output format or the animation differs from the requested job.
     */
    public function isFallback(): bool
    {
        return $this->job->getOutputFormat() !== $this->requestedJob->getOutputFormat()
            || $this->job->getFrameCount() !== $this->requestedJob->getFrameCount();
    }

    /**
     * Why the backends and variants tried before the chosen one were not chosen, such as
     * "avif: avifenc: it does not resize".
     *
     * @return list<string>
     */
    public function getReasons(): array
    {
        return $this->reasons;
    }

    /**
     * What the chosen backend drops of the requested metadata, such as "gd drops the ICC profile".
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
