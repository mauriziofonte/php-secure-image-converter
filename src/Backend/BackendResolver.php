<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ResourceLimits;

/**
 * Chooses the backend for each job from the declared capabilities.
 *
 * Output formats are tried in fallback order: the requested one, then WEBP when AVIF was requested, then the input
 * format (fallbackFormats()). For each format, an animated job is tried first with every frame and then with the
 * first frame only (ANIMATION_PRESERVE keeps the animation only where the output format can carry it). For each of
 * these variants the backends are tried in their configured order (BackendFactory::NAMES puts vips first), and the
 * first backend that can perform it is chosen:
 *
 * - its capabilities cover the input, the output, the fit and the lossless mode (BackendCapabilities::explainUnsupported());
 * - its memory estimate for the job is not above the memory ceiling;
 * - it keeps the requested metadata; when every capable backend of the variant drops some, the first of them is
 *   chosen and the selection carries a warning (metadata is a preference, not a requirement).
 *
 * The selection lists why every backend and variant tried before was not chosen. When nothing can perform the job,
 * the exception says why for every candidate: ResourceLimitException when only the memory ceiling stood in the way,
 * ConversionFailedException otherwise.
 */
final class BackendResolver
{
    /**
     * Longest exception message, in characters; the reasons beyond it are summarised.
     */
    private const MESSAGE_MAX_LENGTH = 2000;

    /**
     * @var list<BackendInterface>
     */
    private $backends = [];

    /**
     * @var int
     */
    private $memoryCeilingBytes;

    /**
     * @param list<BackendInterface> $backends           in order of preference, with distinct names
     * @param int                    $memoryCeilingBytes largest memory estimate a backend may have for a job
     *
     * @throws InvalidArgumentException when two backends share a name or the ceiling is out of range
     */
    public function __construct(array $backends, int $memoryCeilingBytes = BackendOptions::DEFAULT_MEMORY_CEILING_BYTES)
    {
        $names = [];
        foreach ($backends as $backend) {
            if (isset($names[$backend->getName()])) {
                throw new InvalidArgumentException(sprintf('BackendResolver: the backend "%s" is listed twice.', $backend->getName()));
            }
            $names[$backend->getName()] = true;
            $this->backends[] = $backend;
        }
        if ($memoryCeilingBytes < ResourceLimits::MIN_ADDRESS_SPACE_BYTES || $memoryCeilingBytes > ResourceLimits::MAX_BYTES) {
            throw new InvalidArgumentException('BackendResolver: the memory ceiling is out of range.');
        }
        $this->memoryCeilingBytes = $memoryCeilingBytes;
    }

    /**
     * The backends in order of preference.
     *
     * @return list<BackendInterface>
     */
    public function getBackends(): array
    {
        return $this->backends;
    }

    /**
     * The capabilities of every backend, keyed by backend name, in order of preference. Probes the backends that
     * were not probed yet.
     *
     * @return array<string, BackendCapabilities>
     */
    public function getCapabilities(): array
    {
        $capabilities = [];
        foreach ($this->backends as $backend) {
            $capabilities[$backend->getName()] = $backend->getCapabilities();
        }

        return $capabilities;
    }

    public function getMemoryCeilingBytes(): int
    {
        return $this->memoryCeilingBytes;
    }

    /**
     * Output formats in the order they are tried: the requested format, WEBP after AVIF, then the input format.
     *
     * @param string $outputFormat ImageFormat value requested
     * @param string $inputFormat  ImageFormat value of the input
     *
     * @return list<string>
     */
    public static function fallbackFormats(string $outputFormat, string $inputFormat): array
    {
        $formats = [$outputFormat];
        if ($outputFormat === ImageFormat::AVIF) {
            $formats[] = ImageFormat::WEBP;
        }
        if ($inputFormat !== ImageFormat::SVG) {
            $formats[] = $inputFormat;
        }

        return array_values(array_unique($formats));
    }

    /**
     * Chooses the backend and the job it performs.
     *
     * @throws ResourceLimitException    when capable backends exist but every one needs more memory than the ceiling
     * @throws ConversionFailedException when no backend can perform the job in any fallback format
     */
    public function resolve(BackendJob $job): BackendSelection
    {
        $reasons = [];
        $limitedOnly = true;
        $capable = false;
        foreach (self::fallbackFormats($job->getOutputFormat(), $job->getInputFormat()) as $format) {
            $variants = [$job->withOutputFormat($format)];
            if ($job->isAnimated()) {
                $variants[] = $variants[0]->withAnimation(false);
            }
            foreach ($variants as $variant) {
                $label = $format . ($job->getInput()->isAnimated() ? ($variant->isAnimated() ? ' (animated)' : ' (first frame)') : '');
                $fallback = null;
                foreach ($this->backends as $backend) {
                    $capabilities = $backend->getCapabilities();
                    $unsupported = $capabilities->explainUnsupported($variant);
                    if ($unsupported !== null) {
                        $reasons[] = sprintf('%s: %s: %s', $label, $backend->getName(), $unsupported);
                        continue;
                    }
                    $capable = true;
                    $needed = $backend->estimateMemoryBytes($variant);
                    if ($needed > $this->memoryCeilingBytes) {
                        $reasons[] = sprintf(
                            '%s: %s: it needs about %d MiB, above the memory ceiling of %d MiB',
                            $label,
                            $backend->getName(),
                            (int) ceil($needed / 1048576),
                            intdiv($this->memoryCeilingBytes, 1048576)
                        );
                        continue;
                    }
                    $limitedOnly = false;
                    $gaps = $capabilities->getMetadataGaps($variant);
                    if ($gaps === []) {
                        return new BackendSelection($backend, $variant, $job, $reasons);
                    }
                    if ($fallback === null) {
                        $fallback = [$backend, $gaps];
                    }
                    $reasons[] = sprintf('%s: %s: it drops %s', $label, $backend->getName(), implode(' and ', $gaps));
                }
                if ($fallback !== null) {
                    return new BackendSelection(
                        $fallback[0],
                        $variant,
                        $job,
                        $reasons,
                        [sprintf('%s drops %s', $fallback[0]->getName(), implode(' and ', $fallback[1]))]
                    );
                }
            }
        }
        $message = self::message($reasons);
        if ($capable && $limitedOnly) {
            throw new ResourceLimitException('Every capable backend needs more memory than the ceiling allows: ' . $message);
        }

        throw new ConversionFailedException('No backend can convert this image: ' . $message);
    }

    /**
     * @param list<string> $reasons
     */
    private static function message(array $reasons): string
    {
        if ($reasons === []) {
            return 'no backend is configured.';
        }
        $message = '';
        foreach ($reasons as $index => $reason) {
            $next = ($message === '' ? '' : $message . '; ') . $reason;
            if (strlen($next) > self::MESSAGE_MAX_LENGTH) {
                return sprintf('%s; and %d more.', $message, count($reasons) - $index);
            }
            $message = $next;
        }

        return $message . '.';
    }
}
