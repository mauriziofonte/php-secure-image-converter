<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Internal;

use Mfonte\SecureImageConverter\Backend\BackendCapabilities;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Isolation\PrivateCacheDirectory;

/**
 * Keeps the probed capabilities of each backend, with the facts its command lines depend on, in a private cache
 * directory shared by the PHP processes of the user (see PrivateCacheDirectory for why a planted file is ignored).
 *
 * An entry is used only when it was written for the same backend and fingerprint (the backend's programs with their
 * size, modification time and inode, the isolation level and the sandbox options) less than the TTL ago, and every
 * value in it passes the same validation as a fresh probe. Anything else is ignored and probed again.
 *
 * @internal
 */
final class CapabilityCache
{
    private const SCHEMA = 1;

    private const MAX_BYTES = 65536;

    /**
     * @var PrivateCacheDirectory|null
     */
    private $directory;

    /**
     * @var int
     */
    private $ttlSeconds;

    /**
     * @param PrivateCacheDirectory|null $directory  null disables the cache
     * @param int                        $ttlSeconds age after which an entry is ignored
     */
    public function __construct(?PrivateCacheDirectory $directory, int $ttlSeconds)
    {
        $this->directory = $directory;
        $this->ttlSeconds = $ttlSeconds;
    }

    /**
     * The cached capabilities and facts, or null when there is no valid entry.
     *
     * @return array{0: BackendCapabilities, 1: array<string, string>}|null
     */
    public function load(string $backend, string $fingerprint): ?array
    {
        $path = $this->path($backend, $fingerprint);
        $json = $path === null || $this->directory === null ? null : $this->directory->read($path, self::MAX_BYTES);
        $data = $json === null ? null : json_decode($json, true);
        if (
            !is_array($data)
            || ($data['schema'] ?? null) !== self::SCHEMA
            || ($data['backend'] ?? null) !== $backend
            || ($data['fingerprint'] ?? null) !== $fingerprint
            || !is_int($data['created'] ?? null)
            || $data['created'] > time()
            || time() - $data['created'] >= $this->ttlSeconds
            || !is_array($data['capabilities'] ?? null)
            || !is_array($data['facts'] ?? null)
        ) {
            return null;
        }

        try {
            $capabilities = BackendCapabilities::fromArray($data['capabilities']);
            $facts = Facts::check($data['facts']);
        } catch (InvalidArgumentException $exception) {
            return null;
        }
        if ($capabilities->getBackend() !== $backend) {
            return null;
        }

        return [$capabilities, $facts];
    }

    /**
     * Stores capabilities and facts; a failure only means that the next process probes again.
     *
     * @param array<string, string> $facts
     */
    public function store(string $backend, string $fingerprint, BackendCapabilities $capabilities, array $facts): bool
    {
        $path = $this->path($backend, $fingerprint);
        $json = json_encode([
            'schema' => self::SCHEMA,
            'backend' => $backend,
            'fingerprint' => $fingerprint,
            'created' => time(),
            'capabilities' => $capabilities->toArray(),
            'facts' => $facts,
        ]);

        return $path !== null && $this->directory !== null && is_string($json) && $this->directory->write($path, $json);
    }

    private function path(string $backend, string $fingerprint): ?string
    {
        $directory = $this->directory === null ? null : $this->directory->resolve();
        if ($directory === null || preg_match('/\A[a-z0-9_-]{1,32}\z/', $backend) !== 1) {
            return null;
        }

        return $directory . '/backend-' . $backend . '-' . substr(hash('sha256', $fingerprint), 0, 32) . '.json';
    }
}
