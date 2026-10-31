<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;

/**
 * How backends run their jobs and keep their capabilities. Immutable value object; every with*() method returns a
 * modified copy.
 *
 * | Option                     | Default                     | Meaning                                         |
 * | -------------------------- | --------------------------- | ----------------------------------------------- |
 * | memory ceiling             | 2 GiB                       | largest per-job address space limit             |
 * | working directory parent   | sys_get_temp_dir()          | where each job's private directory is created   |
 * | PHP binary                 | the running CLI, else "php" | PHP CLI that runs the GD and Imagick workers     |
 * | capability cache directory | PrivateCacheDirectory       | where probed capabilities are kept              |
 * | capability cache TTL       | 1 day                       | age after which capabilities are probed again   |
 *
 * Memory: every backend estimates the address space a job needs from its pixels (every frame of an animation), the
 * output pixels and the program's own overhead (BackendInterface::estimateMemoryBytes()), and runs the process with
 * exactly that limit instead of the fixed address space limit of the runner's ResourceLimits. A job whose estimate is
 * above the ceiling is not run by that backend: BackendResolver picks another, or reports resource_limit. The
 * estimates were measured on noise, the costliest content, so that any real image fits. With the default Limits (40
 * megapixels per frame, 400 megapixels in all frames), every still image fits below the default ceiling in cwebp
 * and avifenc, and every lossy one in vips and GD; above it are lossless WEBP beyond about 26 megapixels in vips,
 * and WEBP beyond about 38 and AVIF beyond about 18 in Imagick. The largest animations fit only in gif2webp, which
 * keeps one frame in memory (vips decodes every frame). The CPU time, file size and open file limits of the runner
 * apply unchanged.
 */
final class BackendOptions
{
    /**
     * Default memory ceiling: 2 GiB.
     */
    public const DEFAULT_MEMORY_CEILING_BYTES = 2147483648;

    /**
     * Default capability cache TTL: one day.
     */
    public const DEFAULT_CAPABILITY_CACHE_TTL_SECONDS = 86400;

    /**
     * @var int
     */
    private $memoryCeilingBytes = self::DEFAULT_MEMORY_CEILING_BYTES;

    /**
     * @var string|null
     */
    private $workingDirectoryParent;

    /**
     * @var string|null
     */
    private $phpBinary;

    /**
     * @var bool
     */
    private $capabilityCache = true;

    /**
     * @var string|null
     */
    private $capabilityCacheDirectory;

    /**
     * @var int
     */
    private $capabilityCacheTtlSeconds = self::DEFAULT_CAPABILITY_CACHE_TTL_SECONDS;

    /**
     * Largest per-job address space limit, in bytes.
     */
    public function getMemoryCeilingBytes(): int
    {
        return $this->memoryCeilingBytes;
    }

    /**
     * @param int $bytes from ResourceLimits::MIN_ADDRESS_SPACE_BYTES to ResourceLimits::MAX_BYTES
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withMemoryCeilingBytes(int $bytes): self
    {
        if ($bytes < ResourceLimits::MIN_ADDRESS_SPACE_BYTES || $bytes > ResourceLimits::MAX_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'BackendOptions: the memory ceiling must be from %d to %d bytes.',
                ResourceLimits::MIN_ADDRESS_SPACE_BYTES,
                ResourceLimits::MAX_BYTES
            ));
        }
        $clone = clone $this;
        $clone->memoryCeilingBytes = $bytes;

        return $clone;
    }

    /**
     * Directory where each job's private working directory is created, or null for sys_get_temp_dir().
     */
    public function getWorkingDirectoryParent(): ?string
    {
        return $this->workingDirectoryParent;
    }

    /**
     * @param string|null $directory absolute, normalised path of an existing directory, or null for
     *                               sys_get_temp_dir()
     *
     * @throws InvalidArgumentException when the path is not absolute and normalised
     */
    public function withWorkingDirectoryParent(?string $directory): self
    {
        $clone = clone $this;
        $clone->workingDirectoryParent = self::checkPath('working directory parent', $directory);

        return $clone;
    }

    /**
     * Absolute path of the PHP CLI binary that runs the workers, or null to use the running PHP when it is the CLI
     * and "php" from the runner's search path otherwise (PHP_BINARY under PHP-FPM is the FPM binary).
     */
    public function getPhpBinary(): ?string
    {
        return $this->phpBinary;
    }

    /**
     * @param string|null $path absolute, normalised path of a PHP CLI binary, or null for the default
     *
     * @throws InvalidArgumentException when the path is not absolute and normalised
     */
    public function withPhpBinary(?string $path): self
    {
        $clone = clone $this;
        $clone->phpBinary = self::checkPath('PHP binary', $path);

        return $clone;
    }

    /**
     * Whether probed capabilities are kept in a cache file between processes.
     */
    public function isCapabilityCacheEnabled(): bool
    {
        return $this->capabilityCache;
    }

    /**
     * Directory of the capability cache, or null for the default private directory (see PrivateCacheDirectory).
     */
    public function getCapabilityCacheDirectory(): ?string
    {
        return $this->capabilityCacheDirectory;
    }

    /**
     * @param bool        $enabled   false to probe again in every process
     * @param string|null $directory absolute, normalised path of a private directory, or null for the default
     *
     * @throws InvalidArgumentException when the path is not absolute and normalised
     */
    public function withCapabilityCache(bool $enabled, ?string $directory = null): self
    {
        $clone = clone $this;
        $clone->capabilityCache = $enabled;
        $clone->capabilityCacheDirectory = self::checkPath('capability cache directory', $directory);

        return $clone;
    }

    /**
     * Age in seconds after which cached capabilities are probed again. Programs are also probed again when their
     * binary changes (path, size, modification time or inode); the TTL covers libraries updated without them.
     */
    public function getCapabilityCacheTtlSeconds(): int
    {
        return $this->capabilityCacheTtlSeconds;
    }

    /**
     * @param int $seconds from 1 second to 7 days
     *
     * @throws InvalidArgumentException when the value is out of range
     */
    public function withCapabilityCacheTtlSeconds(int $seconds): self
    {
        if ($seconds < 1 || $seconds > 604800) {
            throw new InvalidArgumentException('BackendOptions: the capability cache TTL must be from 1 second to 7 days.');
        }
        $clone = clone $this;
        $clone->capabilityCacheTtlSeconds = $seconds;

        return $clone;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function checkPath(string $what, ?string $path): ?string
    {
        if ($path !== null && !ExecutableFinder::isAbsolutePath($path)) {
            throw new InvalidArgumentException(sprintf('BackendOptions: the %s must be an absolute, normalised path.', $what));
        }

        return $path;
    }
}
