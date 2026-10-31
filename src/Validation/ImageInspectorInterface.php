<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Validation;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;

/**
 * Identifies an untrusted image from its content and reads its header against the limits, without decoding it.
 * ImageInspector is the implementation; the converter depends on this interface only, so that its branches can be
 * tested with a fake inspector.
 *
 * See ImageInspector for what is checked, and for the rule every consumer follows: decode the very bytes that were
 * inspected (a private copy no other process can write), never the path again.
 */
interface ImageInspectorInterface
{
    /**
     * The limits this inspector enforces.
     */
    public function getLimits(): Limits;

    /**
     * Formats inspect() can accept, as ImageFormat values in canonical order (never SVG).
     *
     * @return list<string>
     */
    public function getInputFormats(): array;

    /**
     * Inspects the image file at a local path, which must name a regular file itself (not a symbolic link).
     *
     * @throws InvalidArgumentException   when the path is not a readable regular local file, or changed while opened
     * @throws LimitExceededException     when the file or the image exceeds a limit
     * @throws UnsupportedFormatException when the format is unidentified, not enabled, SVG or an unsupported variant
     * @throws InvalidImageException      when the header is malformed, truncated, inconsistent or spoofed
     */
    public function inspect(string $path): ImageInfo;

    /**
     * Inspects the image held by a seekable stream of known size.
     *
     * @param resource $stream
     *
     * @throws InvalidArgumentException   when the argument is not a seekable stream of known size
     * @throws LimitExceededException     when the stream or the image exceeds a limit
     * @throws UnsupportedFormatException when the format is unidentified, not enabled, SVG or an unsupported variant
     * @throws InvalidImageException      when the header is malformed, truncated, inconsistent or spoofed
     */
    public function inspectStream($stream): ImageInfo;
}
