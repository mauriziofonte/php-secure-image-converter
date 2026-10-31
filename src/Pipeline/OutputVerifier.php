<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Pipeline;

use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Contract\Exception\OutputVerificationException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\ImageInspector;

/**
 * Checks what a backend produced before the converter returns it (C-11, library side): the backend is a separate,
 * untrusted process, so its file is inspected from its content, never trusted for being there.
 *
 * - It must parse as an image (ImageInspector::forOutput(): the header parser of its sniffed format, walks bounded by
 *   the allowed output size instead of the constants that bound untrusted input, so that every output the backends
 *   legitimately write within the limits is accepted) and be at most the allowed output size.
 * - Its sniffed format must be the format of the job performed: the requested one or the fallback the result reports.
 * - Its size must be the job's exact size, which the job computed from the upright input when it applies the
 *   orientation; an output of such a job must not carry an orientation of its own. When the job keeps the pixels as
 *   stored but the input declares an orientation of 5 to 8, the swapped size is accepted too: some decoders (libheif
 *   for AVIF) apply the orientation whatever they are asked.
 * - Its frame count must be 1 for a still job, and from 1 to the job's frame count for an animation (an encoder may
 *   merge identical frames).
 * - It must fit the dimension, pixel and frame limits of the converter (Capabilities::assertOutputWithinLimits()).
 *
 * Any mismatch is an OutputVerificationException (output_verification_failed): the output is discarded.
 */
final class OutputVerifier
{
    /**
     * Verifies the file at $path as the output of $job.
     *
     * @param int $maxBytes largest accepted output size
     *
     * @throws OutputVerificationException when the output does not match
     */
    public function verify(BackendJob $job, string $path, Capabilities $capabilities, int $maxBytes): ImageInfo
    {
        $formats = array_values(array_diff(ImageFormat::all(), [ImageFormat::SVG]));

        try {
            $info = ImageInspector::forOutput($formats, $maxBytes)->inspect($path);
        } catch (LimitExceededException $exception) {
            throw new OutputVerificationException('The output is larger than the allowed output size.');
        } catch (ExceptionInterface $exception) {
            throw new OutputVerificationException('The output is not a valid image.');
        }
        if ($info->getFormat() !== $job->getOutputFormat()) {
            throw new OutputVerificationException(sprintf(
                'The output is %s instead of %s.',
                $info->getFormat(),
                $job->getOutputFormat()
            ));
        }
        $size = [$info->getWidth(), $info->getHeight()];
        $expected = [[$job->getWidth(), $job->getHeight()]];
        if (!$job->needsOrientation() && $job->getInput()->getOrientation() >= BackendJob::FIRST_TRANSPOSING_ORIENTATION) {
            $expected[] = [$job->getHeight(), $job->getWidth()];
        }
        if (!in_array($size, $expected, true)) {
            throw new OutputVerificationException(sprintf(
                'The output is %dx%d instead of %dx%d.',
                $size[0],
                $size[1],
                $job->getWidth(),
                $job->getHeight()
            ));
        }
        $frames = $info->getFrameCount();
        if ($job->isAnimated() ? $frames > $job->getFrameCount() : $frames !== 1) {
            throw new OutputVerificationException(sprintf(
                'The output has %d frames instead of %d.',
                $frames,
                $job->getFrameCount()
            ));
        }
        if ($job->needsOrientation() && $info->getOrientation() !== ImageInfo::ORIENTATION_NORMAL) {
            throw new OutputVerificationException('The output was turned upright but still declares an orientation.');
        }

        try {
            $capabilities->assertOutputWithinLimits($size[0], $size[1], $frames);
        } catch (LimitExceededException $exception) {
            throw new OutputVerificationException('The output exceeds the limits of the converter.');
        }

        return $info;
    }
}
