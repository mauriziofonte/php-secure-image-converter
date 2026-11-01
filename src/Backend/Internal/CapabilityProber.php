<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Internal;

use Mfonte\SecureImageConverter\Backend\BackendCapabilities;
use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Validation\ImageInfo;

/**
 * Establishes what a backend can do by converting the probe images and checking every output with ImageInspector
 * (L-9: capability probes, never version checks). The backend names its candidates (what its program claims to
 * support); only what a probe proves is declared.
 *
 * | Capability       | Proved by                                                                              |
 * | ---------------- | -------------------------------------------------------------------------------------- |
 * | output format    | the probe source converted with metadata stripped: the format and size are right, and  |
 * |                  | neither the ICC profile nor EXIF of the JPEG source survives                            |
 * | input format     | its still image converted: the size is right (page 0 of the TIFF, not its larger page) |
 * | input variant    | the variant's still image (such as a CMYK JPEG) converted: the size is right           |
 * | fit              | the source scaled to an exact size with that fit                                       |
 * | first frame      | an animation converted to a still image of the right size                              |
 * | animated in/out  | an animation converted with every frame kept                                           |
 * | animated fit     | an animation scaled with that fit, every frame kept                                    |
 * | lossless         | a lossless conversion succeeds (a WEBP must use the lossless VP8L bitstream)           |
 * | ICC profile      | the JPEG source, stripped with the ICC profile kept: the profile is there, EXIF is not  |
 * | metadata         | the JPEG source, not stripped: EXIF is there                                           |
 * | unstripped       | a format that is both an input and an output, whose image with metadata               |
 * |                  | (ProbeImages::metadata()) re-encoded as itself with metadata stripped was wrong, kept |
 * |                  | metadata or failed; a format without such an image (AVIF, BMP) is not probed           |
 * | oriented formats | the oriented JPEG (16 x 12 stored, EXIF orientation 6) converted with its orientation |
 * |                  | applied: the output is 12 x 16 and carries no orientation; then every candidate input  |
 * |                  | format is declared, the backend applying the orientation the same way to all of them  |
 *
 * @internal
 */
final class CapabilityProber
{
    /**
     * Sources tried for the output probes, in order: the JPEG first because it carries the metadata that must be
     * stripped.
     */
    private const SOURCE_ORDER = [
        ImageFormat::JPEG,
        ImageFormat::PNG,
        ImageFormat::GIF,
        ImageFormat::WEBP,
        ImageFormat::TIFF,
        ImageFormat::BMP,
        ImageFormat::AVIF,
    ];

    /**
     * Preferred output of the input probes: lossless and quick first.
     */
    private const REFERENCE_ORDER = [
        ImageFormat::PNG,
        ImageFormat::WEBP,
        ImageFormat::TIFF,
        ImageFormat::BMP,
        ImageFormat::JPEG,
        ImageFormat::GIF,
        ImageFormat::AVIF,
    ];

    /**
     * Outputs that can carry an ICC profile and EXIF, in order of preference for the metadata probes.
     */
    private const METADATA_ORDER = [
        ImageFormat::WEBP,
        ImageFormat::JPEG,
        ImageFormat::PNG,
        ImageFormat::AVIF,
        ImageFormat::TIFF,
    ];

    /**
     * Exact size and fit of each resize probe of a 16 x 12 (or 12 x 6) image.
     */
    private const FIT_PROBES = [
        ConversionRequest::FIT_CONTAIN => [8, 6],
        ConversionRequest::FIT_COVER => [6, 6],
        ConversionRequest::FIT_FILL => [10, 4],
    ];

    private const MAX_NOTES = 16;

    /**
     * @var string
     */
    private $backend;

    /**
     * @var callable(BackendJob): void
     */
    private $convert;

    /**
     * @var ProbeImages
     */
    private $images;

    /**
     * @var WorkingDirectory
     */
    private $directory;

    /**
     * @var int
     */
    private $runs = 0;

    /**
     * @var list<string>
     */
    private $notes = [];

    /**
     * First bytes of the last output inspected.
     *
     * @var string
     */
    private $lastHeader = '';

    /**
     * @param string                     $backend   backend name
     * @param callable(BackendJob): void $convert   converts a job, throwing an ExceptionInterface on failure
     * @param ProbeImages                $images    the probe images
     * @param WorkingDirectory           $directory where the outputs are written
     */
    public function __construct(string $backend, callable $convert, ProbeImages $images, WorkingDirectory $directory)
    {
        $this->backend = $backend;
        $this->convert = $convert;
        $this->images = $images;
        $this->directory = $directory;
    }

    /**
     * Probes the candidates.
     *
     * @param array{inputs: list<string>, outputs: list<string>, first_frame: list<string>, animated_inputs: list<string>, animated_outputs: list<string>, fits: list<string>, animated_fits: list<string>, lossless: list<string>, oriented: list<string>, icc_profile: bool, metadata: bool} $candidates
     */
    public function probe(array $candidates): BackendCapabilities
    {
        list($source, $outputs) = $this->probeOutputs($candidates['inputs'], $candidates['outputs']);
        if ($source === null) {
            return BackendCapabilities::unavailable(
                $this->backend,
                $this->notes === [] ? 'it has nothing to convert' : 'it converted none of the probe images'
            );
        }
        $reference = self::first(self::REFERENCE_ORDER, $outputs) ?? $outputs[0];
        $inputs = [$source];
        foreach ($candidates['inputs'] as $format) {
            if ($format !== $source && $this->convertsStill($format, $reference)) {
                $inputs[] = $format;
            }
        }
        $variants = [];
        foreach (ImageInfo::VARIANTS as $variant => $format) {
            if (in_array($format, $inputs, true) && $this->convertsVariant($variant, $reference)) {
                $variants[] = $variant;
            }
        }
        $capabilities = (new BackendCapabilities($this->backend, $inputs, $outputs))->withInputVariants($variants);
        $fits = [];
        foreach ($candidates['fits'] as $fit) {
            if (isset(self::FIT_PROBES[$fit]) && $this->resizes($source, $reference, $fit, false)) {
                $fits[] = $fit;
            }
        }
        $capabilities = $capabilities->withFits($fits);

        $firstFrame = [];
        foreach (array_intersect($candidates['first_frame'], $inputs) as $format) {
            if ($this->convertsFirstFrame($format, $reference)) {
                $firstFrame[] = $format;
            }
        }
        $capabilities = $capabilities->withFirstFrameFormats($firstFrame);
        list($animatedInputs, $animatedOutputs) = $this->probeAnimations(
            array_values(array_intersect($candidates['animated_inputs'], $inputs)),
            array_values(array_intersect($candidates['animated_outputs'], $outputs))
        );
        $capabilities = $capabilities->withAnimatedInputFormats($animatedInputs)
            ->withAnimatedOutputFormats($animatedOutputs);
        if ($animatedInputs !== [] && $animatedOutputs !== []) {
            $animatedFits = [];
            foreach (array_intersect($candidates['animated_fits'], $fits) as $fit) {
                if ($this->resizes($animatedInputs[0], $animatedOutputs[0], $fit, true)) {
                    $animatedFits[] = $fit;
                }
            }
            $capabilities = $capabilities->withAnimatedFits($animatedFits);
        }

        $lossless = [];
        foreach (array_intersect($candidates['lossless'], $outputs) as $format) {
            if ($this->encodesLossless($source, $format)) {
                $lossless[] = $format;
            }
        }
        $capabilities = $capabilities->withLosslessFormats($lossless);
        $oriented = array_values(array_intersect($candidates['oriented'], $inputs));
        if ($oriented !== [] && in_array(ImageFormat::JPEG, $inputs, true) && $this->appliesOrientation($reference)) {
            $capabilities = $capabilities->withOrientedFormats($oriented);
        }
        $unstripped = [];
        foreach (array_intersect($inputs, $outputs) as $format) {
            if (!$this->stripsReencode($format)) {
                $unstripped[] = $format;
            }
        }
        $capabilities = $capabilities->withUnstrippedFormats($unstripped);
        $metadataOutput = in_array(ImageFormat::JPEG, $inputs, true) ? self::first(self::METADATA_ORDER, $outputs) : null;
        if ($metadataOutput !== null) {
            $capabilities = $capabilities
                ->withIccProfile($candidates['icc_profile'] && $this->keepsIccProfile($metadataOutput))
                ->withMetadata($candidates['metadata'] && $this->keepsMetadata($metadataOutput));
        }

        return $capabilities->withNotes($this->notes);
    }

    /**
     * Number of conversions run so far.
     */
    public function getRuns(): int
    {
        return $this->runs;
    }

    /**
     * @param list<string> $inputs
     * @param list<string> $outputs
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private function probeOutputs(array $inputs, array $outputs): array
    {
        $tries = 0;
        foreach (self::SOURCE_ORDER as $source) {
            if (!in_array($source, $inputs, true) || $this->images->still($source) === null || ++$tries > 3) {
                continue;
            }
            $verified = [];
            foreach ($outputs as $output) {
                $info = $this->run($this->stillJob($source, $output), 'a ' . $output . ' output');
                if (
                    $info !== null
                    && self::matches($info, $output, 16, 12, 1)
                    && !$info->hasIccProfile()
                    && !$info->hasExif()
                ) {
                    $verified[] = $output;
                } elseif ($info !== null) {
                    $this->note(sprintf('its %s output from %s was wrong or kept metadata', $output, $source));
                }
            }
            if ($verified !== []) {
                return [$source, $verified];
            }
        }

        return [null, []];
    }

    private function convertsStill(string $format, string $output): bool
    {
        $image = $this->images->still($format);
        if ($image === null) {
            return false;
        }
        $info = $this->run($this->job($image, $output, $image[1]->getWidth(), $image[1]->getHeight()), 'a ' . $format . ' input');

        return $info !== null && $this->check(
            self::matches($info, $output, $image[1]->getWidth(), $image[1]->getHeight(), 1),
            sprintf('its output from %s had the wrong size or page', $format)
        );
    }

    private function convertsVariant(string $variant, string $output): bool
    {
        $image = $this->images->variant($variant);
        if ($image === null) {
            return false;
        }
        $info = $this->run($this->job($image, $output, $image[1]->getWidth(), $image[1]->getHeight()), 'the ' . $variant . ' variant');

        return $info !== null && $this->check(
            self::matches($info, $output, $image[1]->getWidth(), $image[1]->getHeight(), 1),
            sprintf('its output from the %s variant had the wrong size', $variant)
        );
    }

    private function convertsFirstFrame(string $format, string $output): bool
    {
        $image = $this->images->animation($format);
        if ($image === null) {
            return false;
        }
        $job = $this->job($image, $output, $image[1]->getWidth(), $image[1]->getHeight())->withAnimation(false);
        $info = $this->run($job, 'the first frame of a ' . $format . ' animation');

        return $info !== null && $this->check(
            self::matches($info, $output, $image[1]->getWidth(), $image[1]->getHeight(), 1),
            sprintf('its first frame of a %s animation was wrong', $format)
        );
    }

    /**
     * @param list<string> $inputs
     * @param list<string> $outputs
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function probeAnimations(array $inputs, array $outputs): array
    {
        $animatedOutputs = [];
        $first = null;
        foreach ($inputs as $input) {
            if ($this->images->animation($input) === null) {
                continue;
            }
            foreach ($outputs as $output) {
                if ($this->convertsAnimation($input, $output)) {
                    $animatedOutputs[] = $output;
                }
            }
            if ($animatedOutputs !== []) {
                $first = $input;
                break;
            }
        }
        if ($first === null) {
            return [[], []];
        }
        $animatedInputs = [$first];
        foreach ($inputs as $input) {
            if ($input !== $first && $this->convertsAnimation($input, $animatedOutputs[0])) {
                $animatedInputs[] = $input;
            }
        }

        return [$animatedInputs, $animatedOutputs];
    }

    private function convertsAnimation(string $input, string $output): bool
    {
        $image = $this->images->animation($input);
        if ($image === null) {
            return false;
        }
        $info = $this->run(
            $this->job($image, $output, $image[1]->getWidth(), $image[1]->getHeight()),
            sprintf('a %s animation to %s', $input, $output)
        );

        return $info !== null && $this->check(
            self::matches($info, $output, $image[1]->getWidth(), $image[1]->getHeight(), $image[1]->getFrameCount()),
            sprintf('its animated %s from %s lost frames', $output, $input)
        );
    }

    private function resizes(string $input, string $output, string $fit, bool $animated): bool
    {
        $image = $animated ? $this->images->animation($input) : $this->images->still($input);
        if ($image === null) {
            return false;
        }
        list($width, $height) = self::FIT_PROBES[$fit];
        $info = $this->run(
            $this->job($image, $output, $width, $height)->withFit($fit),
            sprintf('a resize with fit %s%s', $fit, $animated ? ' of an animation' : '')
        );

        return $info !== null && $this->check(
            self::matches($info, $output, $width, $height, $animated ? $image[1]->getFrameCount() : 1),
            sprintf('its resize with fit %s%s had the wrong size', $fit, $animated ? ' of an animation' : '')
        );
    }

    private function encodesLossless(string $source, string $output): bool
    {
        $job = $this->stillJob($source, $output)->withLossless(true);
        $info = $this->run($job, 'a lossless ' . $output);
        if ($info === null || !self::matches($info, $output, 16, 12, 1)) {
            return false;
        }
        if ($output !== ImageFormat::WEBP) {
            return true;
        }

        return $this->check(
            substr($this->lastHeader, 12, 4) === 'VP8L',
            'its lossless webp output was lossy'
        );
    }

    private function appliesOrientation(string $output): bool
    {
        $image = $this->images->oriented();
        if ($image === null) {
            return false;
        }
        $job = $this->job($image, $output, $image[1]->getHeight(), $image[1]->getWidth())
            ->withOrientation($image[1]->getOrientation())
            ->withStripMetadata(false);
        $info = $this->run($job, 'an oriented image');

        return $info !== null && $this->check(
            self::matches($info, $output, $image[1]->getHeight(), $image[1]->getWidth(), 1)
                && $info->getOrientation() === ImageInfo::ORIENTATION_NORMAL,
            'its output of an oriented image was not upright'
        );
    }

    /**
     * Whether the image of a format with metadata, re-encoded as the same format with metadata stripped, comes out
     * right and without metadata; true when there is no such image.
     */
    private function stripsReencode(string $format): bool
    {
        $image = $this->images->metadata($format);
        if ($image === null) {
            return true;
        }
        $info = $this->run($this->job($image, $format, $image[1]->getWidth(), $image[1]->getHeight()), 'a ' . $format . ' with metadata to ' . $format);

        return $info !== null && $this->check(
            self::matches($info, $format, $image[1]->getWidth(), $image[1]->getHeight(), 1)
                && !$info->hasMetadata()
                && !$info->hasIccProfile(),
            sprintf('its %s output from a %s with metadata kept the metadata', $format, $format)
        );
    }

    private function keepsIccProfile(string $output): bool
    {
        $job = $this->stillJob(ImageFormat::JPEG, $output)->withKeepIccProfile(true);
        $info = $this->run($job, 'an ICC profile');

        return $info !== null && $info->hasIccProfile() && !$info->hasExif();
    }

    private function keepsMetadata(string $output): bool
    {
        $job = $this->stillJob(ImageFormat::JPEG, $output)->withStripMetadata(false);
        $info = $this->run($job, 'the metadata');

        return $info !== null && $info->hasExif();
    }

    /**
     * A same-size conversion of a still probe image with every metadata block stripped.
     */
    private function stillJob(string $source, string $output): BackendJob
    {
        $image = $this->images->still($source);
        if ($image === null) {
            throw new InvalidArgumentException('CapabilityProber: the probe source must exist.');
        }

        return $this->job($image, $output, $image[1]->getWidth(), $image[1]->getHeight());
    }

    /**
     * @param array{0: string, 1: ImageInfo} $image
     */
    private function job(array $image, string $output, int $width, int $height): BackendJob
    {
        $path = $this->directory->pathOf(sprintf('probe-%d.%s', ++$this->runs, ImageFormat::extension($output)));

        return (new BackendJob($image[0], $image[1], $path, $output, $width, $height))
            ->withKeepIccProfile(false)
            ->withEffort(0);
    }

    /**
     * Converts a probe job and inspects its output.
     */
    private function run(BackendJob $job, string $what): ?ImageInfo
    {
        $this->lastHeader = '';

        try {
            ($this->convert)($job);
            $info = $this->images->getInspector()->inspect($job->getOutputPath());
            $header = @file_get_contents($job->getOutputPath(), false, null, 0, 16);
            $this->lastHeader = is_string($header) ? $header : '';

            return $info;
        } catch (ExceptionInterface $exception) {
            $this->note(sprintf('it failed to convert %s', $what));

            return null;
        } finally {
            @unlink($job->getOutputPath());
        }
    }

    private function check(bool $passed, string $failure): bool
    {
        if (!$passed) {
            $this->note($failure);
        }

        return $passed;
    }

    private function note(string $note): void
    {
        if (count($this->notes) < self::MAX_NOTES) {
            $this->notes[] = substr($note, 0, BackendCapabilities::NOTE_MAX_LENGTH);
        }
    }

    private static function matches(ImageInfo $info, string $format, int $width, int $height, int $frames): bool
    {
        return $info->getFormat() === $format
            && $info->getWidth() === $width
            && $info->getHeight() === $height
            && $info->getFrameCount() === $frames;
    }

    /**
     * @param list<string> $order
     * @param list<string> $formats
     */
    private static function first(array $order, array $formats): ?string
    {
        foreach ($order as $format) {
            if (in_array($format, $formats, true)) {
                return $format;
            }
        }

        return null;
    }
}
