<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract;

use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Contract\Exception\OutputVerificationException;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;

/**
 * Converts an untrusted image file into a web-optimised image.
 *
 * Implemented by the library's converter and by the proxy's client of the conversion API, so that callers depend on
 * this interface only. Implementations know nothing about HTTP, caching or origins.
 */
interface ConverterInterface
{
    /**
     * Converts the image at $inputPath as described by $request and writes the result to $outputPath.
     *
     * - The input is untrusted. Its format is detected from its content, never from its name. Before any decoder runs
     *   it is checked against the enabled input formats and, on its file size and header data, against the limits
     *   of getCapabilities() (Capabilities::assertFileSizeWithinLimits() and assertInputWithinLimits()).
     * - The output is resolved from the request before any encoder runs: its format with
     *   $request->resolveOutputFormat(), its size with resolveOutputSize() and its frame count with
     *   resolveOutputFrameCount(). The resolved output is checked against the same dimension, pixel and frame limits
     *   (Capabilities::assertOutputWithinLimits()), so a request that would enlarge a small input to a huge output is
     *   refused with LimitExceededException (image_too_large, 422) and never reaches an encoder. That check also caps
     *   the output width and height at Capabilities::OUTPUT_DIMENSION_MAX (16383 px, the WEBP maximum and the
     *   smallest among the output formats), so a size that no format could hold is refused as image_too_large too
     *   and never fails an encoder.
     * - When the resolved format cannot be produced, the converter falls back from AVIF to WEBP to the input format
     *   and reports the fallback in the result's warnings; that is never a ConversionFailedException while a
     *   fallback format remains.
     * - The output is verified before it is returned: its sniffed format, dimensions and frame count must match the
     *   resolved ones.
     * - Passthrough (returning the input bytes unchanged) is allowed only when the input already satisfies the whole
     *   request: its size and frame count equal the resolved ones (no resize, and no animation to drop under
     *   ANIMATION_FIRST_FRAME), and, when $request->shouldStripMetadata() is true, it carries no metadata other than
     *   an ICC profile kept by shouldKeepIccProfile(). Such an input is passed through when the converted output is
     *   not smaller ("keep the smaller file"). An input that does not satisfy the request is never passed through,
     *   even when the converted output is larger: the converter returns the converted output, which satisfies the
     *   request. The library's converter always re-encodes; it never strips metadata from the input bytes alone.
     * - On success the output is at $outputPath, passthrough included, and any previous file there is replaced. On
     *   failure an exception implementing Exception\ExceptionInterface is thrown and no partial output is left at
     *   $outputPath.
     *
     * Every exception implements Exception\ExceptionInterface; the error code returned by its getErrorCode() is
     * listed in brackets below, with the HTTP status the conversion API answers with (see Exception\ErrorCode).
     *
     * @param string $inputPath  readable local file holding the untrusted input
     * @param string $outputPath local path to write the output to, in an existing writable directory; must differ
     *                           from $inputPath
     *
     * @throws UnsupportedFormatException    the input content matches no identifiable format (e.g. HEIC), its
     *                                       format is not enabled for input or is SVG, or it is a variant of its
     *                                       format that the library does not accept (see
     *                                       UnsupportedFormatException) [unsupported_format, 415]
     * @throws InvalidImageException         the input is malformed, truncated or spoofed [invalid_image, 422], or
     *                                       animated while the animation policy is reject [animation_rejected, 422]
     * @throws LimitExceededException        the input exceeds max_bytes [file_too_large, 413], or the input or the
     *                                       resolved output exceeds a dimension, pixel or frame limit
     *                                       [image_too_large, 422]
     * @throws IsolationUnavailableException the isolation level required by the policy is not available
     *                                       [isolation_unavailable, 503]
     * @throws ConcurrencyLimitException     no conversion slot became free in time [concurrency_limit, 503]
     * @throws ConversionTimeoutException    the conversion exceeded its wall-clock timeout, or its estimated
     *                                       processing time exceeds the processing budget, so that it was refused
     *                                       before it ran [conversion_timeout, 422]
     * @throws ResourceLimitException        a conversion process ran out of its memory, CPU time or file size
     *                                       budget, or every backend able to convert the input would need more
     *                                       memory than the configured ceiling [resource_limit, 422]
     * @throws ConversionFailedException     no backend could produce the output or a fallback, or a backend failed
     *                                       [conversion_failed, 500]
     * @throws OutputVerificationException   the produced output does not match the expected format, dimensions or
     *                                       frame count [output_verification_failed, 500]
     * @throws InvalidArgumentException      the input path is not a readable file or the output path is not usable
     *                                       [internal_error, 500]
     */
    public function convert(string $inputPath, string $outputPath, ConversionRequest $request): ConversionResult;

    /**
     * Describes what this converter can do on this host. Implementations must not throw: a backend or isolation tool
     * that cannot be detected is reported as unavailable. The result may be cached by the implementation.
     */
    public function getCapabilities(): Capabilities;
}
