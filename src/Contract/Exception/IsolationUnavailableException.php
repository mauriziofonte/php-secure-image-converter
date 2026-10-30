<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * The isolation level required by the configured policy is not available on this host (for example bubblewrap is
 * missing or unprivileged user namespaces are disabled), so the conversion is refused rather than run with weaker
 * isolation.
 *
 * Error code ErrorCode::ISOLATION_UNAVAILABLE; expected HTTP status in the API: 503 Service Unavailable.
 */
final class IsolationUnavailableException extends \RuntimeException implements ExceptionInterface
{
    public function getErrorCode(): string
    {
        return ErrorCode::ISOLATION_UNAVAILABLE;
    }
}
