<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Internal;

use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInspector;

/**
 * Checks the output of a job before it is delivered: a program can exit with status 0 and leave a file that is cut
 * short or holds no image, such as the 222-byte AVIF (a meta box without its image item) that vips writes when the AV1
 * encoder fails to allocate under the address space limit.
 *
 * The output must parse, with the header parser of its format (ImageInspector::forOutput(): every limit at its
 * maximum but the size, which is the runner's file size limit, and walks bounded by that size), as the job's format, size and frame count (at most the job's for an
 * animation: an encoder may merge identical frames), without data that decoders skip and, when the job strips
 * metadata, without metadata (nor an ICC profile unless the job keeps it). The parsers refuse a file cut anywhere, and one without image
 * data: the AVIF boxes must tile the file and the image item hold an AV1 sequence header; a PNG needs IDAT chunks and
 * IEND, a JPEG a scan and EOI, a GIF an image and its trailer, a WebP its bitstream within the RIFF size, a BMP its
 * pixel array within the file. The TIFF parser reads the strips or tiles of the first page only, so a TIFF output is
 * checked further: it must hold that page only (no backend writes more), and every value its directory points to
 * must lie in the file.
 *
 * @internal
 */
final class OutputCheck
{
    /**
     * Size in bytes of the values of each TIFF field type (TIFF 6.0, and 13 for IFD offsets).
     */
    private const TIFF_TYPE_BYTES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8, 13 => 4];

    /**
     * Why the file is not a usable output of the job, or null.
     *
     * @param int $maxBytes largest accepted size
     */
    public static function problem(BackendJob $job, string $path, int $maxBytes): ?string
    {
        try {
            $info = ImageInspector::forOutput([$job->getOutputFormat()], $maxBytes)->inspect($path);
        } catch (ExceptionInterface $exception) {
            return sprintf('it is not a valid %s image', $job->getOutputFormat());
        }
        // A job that keeps the pixels as stored may still get them turned by a decoder that applies the orientation
        // whatever it is asked (libheif for AVIF): the swapped size is accepted then.
        $swapped = !$job->needsOrientation() && $job->getInput()->getOrientation() >= BackendJob::FIRST_TRANSPOSING_ORIENTATION
            && $info->getWidth() === $job->getHeight() && $info->getHeight() === $job->getWidth();
        if (!$swapped && ($info->getWidth() !== $job->getWidth() || $info->getHeight() !== $job->getHeight())) {
            return sprintf('it is %dx%d instead of %dx%d', $info->getWidth(), $info->getHeight(), $job->getWidth(), $job->getHeight());
        }
        $frames = $info->getFrameCount();
        if ($job->isAnimated() ? $frames > $job->getFrameCount() : $frames !== 1) {
            return sprintf('it has %d frames instead of %d', $frames, $job->getFrameCount());
        }
        $problem = $info->getFormat() === ImageFormat::TIFF ? self::tiffProblem($path) : null;
        if ($problem === null && $info->hasTrailingData()) {
            $problem = 'it holds data that decoders skip';
        }
        if ($problem === null && $job->shouldStripMetadata() && $info->hasMetadata()) {
            $problem = 'it carries metadata that was to be stripped';
        }
        if ($problem === null && $job->shouldStripMetadata() && !$job->shouldKeepIccProfile() && $info->hasIccProfile()) {
            $problem = 'it carries an ICC profile that was to be stripped';
        }

        return $problem;
    }

    /**
     * Why a TIFF that the header parser accepted is not a complete single-page output, or null.
     */
    private static function tiffProblem(string $path): ?string
    {
        $size = @filesize($path);
        $file = @fopen($path, 'rb');
        if ($file === false || !is_int($size)) {
            return 'it is not a valid tiff image';
        }

        try {
            $header = (string) fread($file, 8);
            $little = substr($header, 0, 2) === 'II';
            $offset = self::unsigned(substr($header, 4, 4), $little);
            $count = $offset !== null && fseek($file, $offset) === 0 ? self::unsigned((string) fread($file, 2), $little) : null;
            $entries = $count !== null && $count > 0 ? (string) fread($file, 12 * $count) : '';
            $next = self::unsigned((string) fread($file, 4), $little);
        } finally {
            fclose($file);
        }
        if ($count === null || strlen($entries) !== 12 * $count || $next === null) {
            return 'it is not a valid tiff image';
        }
        if ($next !== 0) {
            return 'it has more than one page';
        }
        for ($index = 0; $index < $count; ++$index) {
            $type = self::unsigned(substr($entries, 12 * $index + 2, 2), $little);
            $values = self::unsigned(substr($entries, 12 * $index + 4, 4), $little);
            $bytes = $type !== null && $values !== null ? (self::TIFF_TYPE_BYTES[$type] ?? 1) * $values : 0;
            $at = self::unsigned(substr($entries, 12 * $index + 8, 4), $little);
            if ($bytes > 4 && ($at === null || $at > $size - $bytes)) {
                return 'it is not a valid tiff image';
            }
        }

        return null;
    }

    /**
     * An unsigned integer of 2 or 4 bytes, or null when the bytes are missing.
     */
    private static function unsigned(string $bytes, bool $little): ?int
    {
        $length = strlen($bytes);
        if ($length !== 2 && $length !== 4) {
            return null;
        }
        $value = unpack($length === 2 ? ($little ? 'v' : 'n') : ($little ? 'V' : 'N'), $bytes);

        return is_array($value) && is_int($value[1]) ? $value[1] : null;
    }
}
