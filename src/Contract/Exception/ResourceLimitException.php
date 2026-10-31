<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * A conversion process exceeded the resources it was allowed, other than its wall-clock time: it ran out of memory
 * under its address space limit (ENOMEM), was stopped by its CPU time limit (SIGXCPU, or SIGKILL at the hard limit)
 * or by its file size limit (SIGXFSZ), or was killed with SIGKILL without a timeout (a cgroup memory limit); or every
 * backend able to convert the input would need more memory than the configured ceiling, so that none was started.
 *
 * Like a timeout, this is caused by the input: it needs more than the processing budget of this converter, and a retry
 * of the same request will not help.
 *
 * Error code ErrorCode::RESOURCE_LIMIT; expected HTTP status in the API: 422 Unprocessable Content.
 */
final class ResourceLimitException extends \RuntimeException implements ExceptionInterface
{
    public function getErrorCode(): string
    {
        return ErrorCode::RESOURCE_LIMIT;
    }
}
