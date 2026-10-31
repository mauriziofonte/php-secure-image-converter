<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * How backends run their jobs and keep their capabilities. Immutable value object; every with*() method returns a
 * modified copy.
 *
 * | Option                     | Default                     | Meaning                                         |
 * | -------------------------- | --------------------------- | ----------------------------------------------- |
 * | memory ceiling             | 2 GiB                       | largest per-job address space limit             |
 * | working directory parent   | sys_get_temp_dir()          | where each job's private directory is created   |
 * | PHP binary                 | the running CLI, else "php" | PHP CLI that runs the GD and Imagick workers     |
 * | PHP extension directory    | the binary's built-in one   | where the workers load gd and imagick from      |
 * | capability cache directory | PrivateCacheDirectory       | where probed capabilities are kept              |
 * | capability cache TTL       | 1 day                       | age after which capabilities are probed again   |
 * | logger                     | NullLogger                  | receives the warnings of the backends           |
 *
 * Capability cache: probing every backend takes seconds and dozens of sandboxed runs, so the capabilities are kept
 * in a directory private to the effective user, by default sys_get_temp_dir()/secure-image-converter-<euid>. On a
 * host where other users share that temporary directory (shared hosting, several PHP-FPM pools), another user can
 * create the default directory first: it is then not private and is ignored, every process probes again, and a
 * warning is logged (once per BackendFactory call, and once per backend created directly). Pass a directory that only
 * the PHP user can reach, such as one in the application's own cache directory: withCapabilityCache(true,
 * '/srv/app/var/cache/image-converter'); it is created with mode 0700 when missing.
 *
 * Memory: every backend estimates the address space a job needs (BackendInterface::estimateMemoryBytes()) from the
 * pixels it decodes (every frame of a kept animation) and encodes, the output format, the effort, the lossless mode,
 * the bits per channel and the input format, and runs the process with exactly that limit instead of the fixed
 * address space limit of the runner's ResourceLimits. The estimates are peaks measured on noise, the costliest
 * content, plus a margin (AbstractBackend::estimate()), so that any real image fits. A job whose estimate is above
 * the ceiling is not run by that backend: BackendResolver picks another, or reports resource_limit. The largest still
 * image, in megapixels, that each backend takes below the default ceiling, with 8 bits per channel (16 bits in
 * parentheses where fewer); the default Limits allow 40 megapixels per frame, and the default effort is 4:
 *
 * | Output               | Effort | vips    | cwebp | GD  | Imagick | avifenc |
 * | -------------------- | ------ | ------- | ----- | --- | ------- | ------- |
 * | JPEG, PNG, TIFF, BMP | any    | 254     |       | 204 | 104     |         |
 * | GIF                  | any    | 29      |       | 204 | 59      |         |
 * | WEBP                 | 0 to 8 | 37      | 54    | 42  | 36      |         |
 * | WEBP                 | 9      | 25 (21) | 35    | 42  | 27      |         |
 * | WEBP lossless        | 0 to 7 | 37      | 51    | 45  | 39      |         |
 * | WEBP lossless        | 8, 9   | 37      | 32    | 45  | 39      |         |
 * | AVIF                 | 0 to 2 | 42 (40) |       |     | 46 (36) | 76      |
 * | AVIF                 | 3, 4   | 30      |       |     | 33 (27) | 76      |
 * | AVIF                 | 5 to 9 | 30      |       |     | 33 (27) | 45      |
 * | AVIF lossless        | 0 to 4 | 30      |       |     |         | 39      |
 * | AVIF lossless        | 5 to 9 | 30      |       |     |         | 28      |
 *
 * The largest animations fit only in gif2webp, which keeps one frame in memory (vips decodes every frame). The CPU
 * time, file size and open file limits of the runner apply unchanged.
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
     * @var string|null
     */
    private $phpExtensionDirectory;

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
     * @var LoggerInterface|null
     */
    private $logger;

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
     * Directory the workers load their extension (gd.so, imagick.so) from when the PHP build does not include it, or
     * null for the extension directory built into the binary. The workers run "php -n", which reads no php.ini: an
     * extension_dir set there does not apply, so a host whose extensions live elsewhere (such as /opt/php/ext) sets
     * it here. Each run mounts the directory in the sandbox (AbstractBackend::getSandboxResourcePaths()).
     */
    public function getPhpExtensionDirectory(): ?string
    {
        return $this->phpExtensionDirectory;
    }

    /**
     * @param string|null $directory absolute, normalised path of a directory, or null for the built-in one
     *
     * @throws InvalidArgumentException when the path is not absolute and normalised
     */
    public function withPhpExtensionDirectory(?string $directory): self
    {
        $clone = clone $this;
        $clone->phpExtensionDirectory = self::checkPath('PHP extension directory', $directory);

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
     * @param string|null $directory absolute, normalised path of a directory only the effective user can write
     *                               (created with mode 0700 when missing), or null for the default, which another
     *                               user of the same temporary directory can make unusable (see the class
     *                               description)
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
     * The logger that receives the warnings of the backends: a capability cache that cannot be used.
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger !== null ? $this->logger : new NullLogger();
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $clone = clone $this;
        $clone->logger = $logger;

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
