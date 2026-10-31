<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;

/**
 * An encoder: one program that converts a BackendJob, always in a separate process started through a
 * ProcessRunnerInterface, never in the calling process.
 *
 * A backend declares what it can do on this host in getCapabilities(), established by probing (it converts small
 * images shipped with the library and checks the outputs), and estimates the memory one job needs. It does not choose
 * formats, sizes or animation: BackendResolver checks a job against the capabilities before convert() is called, and
 * convert() does exactly what the job says, or refuses a job its capabilities do not cover. The output is checked
 * only for being a regular file the process created; verifying its content is the caller's task.
 */
interface BackendInterface
{
    /**
     * Backend name (Capabilities::isValidBackendName()), such as "vips".
     */
    public function getName(): string;

    /**
     * What the backend can do on this host. Probes on first use and caches the result; a backend whose program is
     * missing or fails its probes is reported as unavailable, never as an exception.
     */
    public function getCapabilities(): BackendCapabilities;

    /**
     * Estimate, in bytes, of the address space that converting this job takes, for admission only: a job whose
     * estimate is above the memory ceiling is refused before anything runs, so that BackendResolver can pick another
     * backend. The process itself always runs with the memory ceiling as its address space limit. The estimate grows
     * with the decoded pixels (every frame for an animation) and the encoded pixels, plus the fixed overhead of the
     * program; it is set above the peaks measured for the backend on several kinds of content, which are not every
     * image (see AbstractBackend::estimate()).
     */
    public function estimateMemoryBytes(BackendJob $job): int;

    /**
     * Converts the job and writes the output to its output path, replacing any file there.
     *
     * @throws ResourceLimitException        when the estimate is above the memory ceiling (nothing runs), or the
     *                                       process ran out of its address space, CPU time or file size limit
     * @throws ConversionTimeoutException    when the process exceeded its wall-clock timeout
     * @throws IsolationUnavailableException when the isolation level of the runner is not available on the host
     * @throws ConversionFailedException     when the backend is unavailable, its capabilities do not cover the job,
     *                                       or the program fails or leaves no regular output file
     */
    public function convert(BackendJob $job): BackendResult;
}
