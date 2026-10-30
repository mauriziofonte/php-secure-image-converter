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
     * - The input is untrusted. Its format is detected from its content, never from its name; it is checked against
     *   the enabled input formats and the limits of getCapabilities() before any decoder runs.
     * - The output format is $request->resolveOutputFormat() of the input format; when that format cannot be produced
     *   the converter falls back (AVIF to WEBP to the input format) and reports it in the result's warnings.
     * - The output is verified before it is returned: its sniffed format, dimensions and frame count must match what
     *   was produced. When it is not smaller than the input and the request does not resize the image, the input
     *   bytes are kept instead (passthrough).
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
     * @throws UnsupportedFormatException    the input content matches no identifiable format (e.g. HEIC) or its
     *                                       format is not enabled for input [unsupported_format, 415]
     * @throws InvalidImageException         the input is malformed, truncated or spoofed [invalid_image, 422], or
     *                                       animated while the animation policy is reject [animation_rejected, 422]
     * @throws LimitExceededException        the input exceeds max_bytes [file_too_large, 413], or the input or the
     *                                       resolved output exceeds a dimension, pixel or frame limit
     *                                       [image_too_large, 422]
     * @throws IsolationUnavailableException the isolation level required by the policy is not available
     *                                       [isolation_unavailable, 503]
     * @throws ConcurrencyLimitException     no conversion slot became free in time [concurrency_limit, 503]
     * @throws ConversionTimeoutException    the conversion exceeded its wall-clock timeout [conversion_timeout, 422]
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
