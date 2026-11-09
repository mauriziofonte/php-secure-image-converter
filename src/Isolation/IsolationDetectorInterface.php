<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

/**
 * Tells which isolation level this host provides. IsolationDetector is the implementation, which probes the host with
 * real commands and caches the result; consumers depend on this interface, so that they can be tested with a fixed
 * result. Implementations never throw: a level that cannot be probed is reported as unavailable, with its reason.
 */
interface IsolationDetectorInterface
{
    /**
     * The strongest available level, possibly from a cache.
     */
    public function detect(): DetectionResult;

    /**
     * The strongest available level, probed again whatever is cached.
     */
    public function refresh(): DetectionResult;
}
