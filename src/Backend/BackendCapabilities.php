<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;

/**
 * What one backend can do on this host, as established by probing what is installed (never by reading version
 * numbers: distributions backport fixes and features without changing them, L-9).
 *
 * | Capability              | Meaning                                                                               |
 * | ----------------------- | ------------------------------------------------------------------------------------- |
 * | input formats           | formats whose still images it decodes                                                 |
 * | first frame formats     | formats whose animated images it decodes as their first frame alone                  |
 * | animated input formats  | formats whose animated images it decodes frame by frame                               |
 * | output formats          | formats it encodes as a still image                                                   |
 * | animated output formats | formats it encodes with every frame of an animated input of an animated input format |
 * | lossless formats        | WEBP and AVIF, when it encodes them losslessly                                        |
 * | fits                    | fits (contain, cover, fill) with which it scales a still image to an exact size       |
 * | animated fits           | fits with which it scales every frame of an animation to an exact size                |
 * | ICC profile             | it keeps the ICC profile while it strips the other metadata                           |
 * | metadata                | it keeps every metadata block when stripping is not requested                         |
 *
 * Every backend strips metadata on request: a backend whose output kept metadata after stripping fails its probes
 * and does not list that output format. An unavailable backend lists nothing and says why. Notes are short English
 * sentences on probes that failed, for logs, /healthz and check-env: printable ASCII, never a path or tool output.
 *
 * Immutable value object. toArray() and fromArray() store it in the capability cache, strictly: an array of another
 * schema, with a missing or unknown key or an invalid value, is rejected.
 */
final class BackendCapabilities
{
    /**
     * Version of the array shape of toArray() and fromArray().
     */
    public const SCHEMA_VERSION = 1;

    /**
     * Maximum length of a note or of the reason a backend is unavailable, in characters.
     */
    public const NOTE_MAX_LENGTH = 200;

    /**
     * @var list<string>
     */
    private const KEYS = [
        'schema',
        'backend',
        'available',
        'unavailable_reason',
        'input_formats',
        'first_frame_formats',
        'animated_input_formats',
        'output_formats',
        'animated_output_formats',
        'lossless_formats',
        'fits',
        'animated_fits',
        'icc_profile',
        'metadata',
        'notes',
    ];

    /**
     * @var string
     */
    private $backend;

    /**
     * @var string|null
     */
    private $unavailableReason;

    /**
     * @var list<string>
     */
    private $inputFormats = [];

    /**
     * @var list<string>
     */
    private $firstFrameFormats = [];

    /**
     * @var list<string>
     */
    private $animatedInputFormats = [];

    /**
     * @var list<string>
     */
    private $outputFormats = [];

    /**
     * @var list<string>
     */
    private $animatedOutputFormats = [];

    /**
     * @var list<string>
     */
    private $losslessFormats = [];

    /**
     * @var list<string>
     */
    private $fits = [];

    /**
     * @var list<string>
     */
    private $animatedFits = [];

    /**
     * @var bool
     */
    private $iccProfile = false;

    /**
     * @var bool
     */
    private $metadata = false;

    /**
     * @var list<string>
     */
    private $notes = [];

    /**
     * An available backend that decodes still images of $inputFormats and encodes $outputFormats, and nothing more;
     * see the with*() methods for the other capabilities.
     *
     * @param string       $backend       backend name (Capabilities::isValidBackendName())
     * @param list<string> $inputFormats  raster ImageFormat values
     * @param list<string> $outputFormats raster ImageFormat values
     *
     * @throws InvalidArgumentException when a value is outside its documented domain
     */
    public function __construct(string $backend, array $inputFormats, array $outputFormats)
    {
        $this->backend = self::checkName($backend);
        $this->inputFormats = self::checkFormats('input formats', $inputFormats);
        $this->outputFormats = self::checkFormats('output formats', $outputFormats);
    }

    /**
     * A backend that cannot run at all on this host, and why.
     *
     * @param string $backend backend name
     * @param string $reason  1 to NOTE_MAX_LENGTH printable ASCII characters, such as "vips is not installed"
     *
     * @throws InvalidArgumentException when a value is outside its documented domain
     */
    public static function unavailable(string $backend, string $reason): self
    {
        $capabilities = new self($backend, [], []);
        $capabilities->unavailableReason = self::checkNote($reason);

        return $capabilities;
    }

    /**
     * Rebuilds capabilities from toArray() output.
     *
     * @param array<mixed> $data
     *
     * @throws InvalidArgumentException when the array is not valid capabilities of SCHEMA_VERSION
     */
    public static function fromArray(array $data): self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA_VERSION || count($data) !== count(self::KEYS)) {
            throw new InvalidArgumentException('BackendCapabilities: unsupported schema or unexpected keys.');
        }
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                throw new InvalidArgumentException('BackendCapabilities: a key is missing.');
            }
        }
        if (!is_string($data['backend']) || !is_bool($data['available'])) {
            throw new InvalidArgumentException('BackendCapabilities: the backend must be a name and available a boolean.');
        }
        if ($data['available'] !== ($data['unavailable_reason'] === null)) {
            throw new InvalidArgumentException('BackendCapabilities: exactly an unavailable backend has a reason.');
        }
        if (!$data['available']) {
            if (!is_string($data['unavailable_reason'])) {
                throw new InvalidArgumentException('BackendCapabilities: the reason must be a string.');
            }
            $capabilities = self::unavailable($data['backend'], $data['unavailable_reason']);
            if ($capabilities->toArray() !== $data) {
                throw new InvalidArgumentException('BackendCapabilities: an unavailable backend lists no capability.');
            }

            return $capabilities;
        }
        foreach (['icc_profile', 'metadata'] as $key) {
            if (!is_bool($data[$key])) {
                throw new InvalidArgumentException(sprintf('BackendCapabilities: "%s" must be a boolean.', $key));
            }
        }

        return (new self(
            $data['backend'],
            self::stringList('input formats', $data['input_formats']),
            self::stringList('output formats', $data['output_formats'])
        ))
            ->withFirstFrameFormats(self::stringList('first frame formats', $data['first_frame_formats']))
            ->withAnimatedInputFormats(self::stringList('animated input formats', $data['animated_input_formats']))
            ->withAnimatedOutputFormats(self::stringList('animated output formats', $data['animated_output_formats']))
            ->withLosslessFormats(self::stringList('lossless formats', $data['lossless_formats']))
            ->withFits(self::stringList('fits', $data['fits']))
            ->withAnimatedFits(self::stringList('animated fits', $data['animated_fits']))
            ->withIccProfile($data['icc_profile'])
            ->withMetadata($data['metadata'])
            ->withNotes(self::stringList('notes', $data['notes']));
    }

    /**
     * Name of the backend.
     */
    public function getBackend(): string
    {
        return $this->backend;
    }

    /**
     * Whether the backend can run on this host.
     */
    public function isAvailable(): bool
    {
        return $this->unavailableReason === null;
    }

    /**
     * Why the backend cannot run on this host, or null when it can.
     */
    public function getUnavailableReason(): ?string
    {
        return $this->unavailableReason;
    }

    /**
     * Formats whose still images the backend decodes, in canonical order.
     *
     * @return list<string>
     */
    public function getInputFormats(): array
    {
        return $this->inputFormats;
    }

    /**
     * Formats whose animated images the backend decodes as their first frame alone, in canonical order.
     *
     * @return list<string>
     */
    public function getFirstFrameFormats(): array
    {
        return $this->firstFrameFormats;
    }

    /**
     * @param list<string> $formats animatable formats among the input formats
     *
     * @throws InvalidArgumentException when a format is not an animatable input format
     */
    public function withFirstFrameFormats(array $formats): self
    {
        $clone = $this->availableClone();
        $clone->firstFrameFormats = self::checkSubset(
            'first frame formats',
            $formats,
            array_intersect($this->inputFormats, ImageFormat::animatable())
        );

        return $clone;
    }

    /**
     * Formats whose animated images the backend decodes frame by frame, in canonical order.
     *
     * @return list<string>
     */
    public function getAnimatedInputFormats(): array
    {
        return $this->animatedInputFormats;
    }

    /**
     * @param list<string> $formats animatable formats among the input formats
     *
     * @throws InvalidArgumentException when a format is not an animatable input format
     */
    public function withAnimatedInputFormats(array $formats): self
    {
        $clone = $this->availableClone();
        $clone->animatedInputFormats = self::checkSubset(
            'animated input formats',
            $formats,
            array_intersect($this->inputFormats, ImageFormat::animatable())
        );

        return $clone;
    }

    /**
     * Formats the backend encodes as a still image, in canonical order.
     *
     * @return list<string>
     */
    public function getOutputFormats(): array
    {
        return $this->outputFormats;
    }

    /**
     * Formats the backend encodes with several frames, in canonical order.
     *
     * @return list<string>
     */
    public function getAnimatedOutputFormats(): array
    {
        return $this->animatedOutputFormats;
    }

    /**
     * @param list<string> $formats animatable formats among the output formats
     *
     * @throws InvalidArgumentException when a format is not an animatable output format
     */
    public function withAnimatedOutputFormats(array $formats): self
    {
        $clone = $this->availableClone();
        $clone->animatedOutputFormats = self::checkSubset(
            'animated output formats',
            $formats,
            array_intersect($this->outputFormats, ImageFormat::animatable())
        );

        return $clone;
    }

    /**
     * WEBP and AVIF, when the backend encodes them losslessly, in canonical order.
     *
     * @return list<string>
     */
    public function getLosslessFormats(): array
    {
        return $this->losslessFormats;
    }

    /**
     * @param list<string> $formats webp and avif, among the output formats
     *
     * @throws InvalidArgumentException when a format is not a WEBP or AVIF output format
     */
    public function withLosslessFormats(array $formats): self
    {
        $clone = $this->availableClone();
        $clone->losslessFormats = self::checkSubset(
            'lossless formats',
            $formats,
            array_intersect($this->outputFormats, [ImageFormat::WEBP, ImageFormat::AVIF])
        );

        return $clone;
    }

    /**
     * Fits (ConversionRequest::FIT_*) with which the backend scales a still image to an exact size, in the order of
     * ConversionRequest::fitModes(); empty when it does not resize.
     *
     * @return list<string>
     */
    public function getFits(): array
    {
        return $this->fits;
    }

    /**
     * @param list<string> $fits ConversionRequest::FIT_* values
     *
     * @throws InvalidArgumentException when a fit is unknown or repeated
     */
    public function withFits(array $fits): self
    {
        $clone = $this->availableClone();
        $clone->fits = self::checkFits('fits', $fits, ConversionRequest::fitModes());

        return $clone;
    }

    /**
     * Fits with which the backend scales every frame of an animation to an exact size, in the order of
     * ConversionRequest::fitModes(); empty when it does not resize animations.
     *
     * @return list<string>
     */
    public function getAnimatedFits(): array
    {
        return $this->animatedFits;
    }

    /**
     * @param list<string> $fits fits among getFits()
     *
     * @throws InvalidArgumentException when a fit is not among the still image fits, or is repeated
     */
    public function withAnimatedFits(array $fits): self
    {
        $clone = $this->availableClone();
        $clone->animatedFits = self::checkFits('animated fits', $fits, $this->fits);

        return $clone;
    }

    /**
     * Whether the backend keeps the ICC profile while it strips the other metadata.
     */
    public function keepsIccProfile(): bool
    {
        return $this->iccProfile;
    }

    public function withIccProfile(bool $keeps): self
    {
        $clone = $this->availableClone();
        $clone->iccProfile = $keeps;

        return $clone;
    }

    /**
     * Whether the backend keeps every metadata block when stripping is not requested.
     */
    public function keepsMetadata(): bool
    {
        return $this->metadata;
    }

    public function withMetadata(bool $keeps): self
    {
        $clone = $this->availableClone();
        $clone->metadata = $keeps;

        return $clone;
    }

    /**
     * Probes that failed, as short English sentences.
     *
     * @return list<string>
     */
    public function getNotes(): array
    {
        return $this->notes;
    }

    /**
     * @param list<string> $notes each 1 to NOTE_MAX_LENGTH printable ASCII characters
     *
     * @throws InvalidArgumentException when a note is invalid
     */
    public function withNotes(array $notes): self
    {
        $clone = clone $this;
        $clone->notes = [];
        foreach ($notes as $note) {
            $clone->notes[] = self::checkNote($note);
        }

        return $clone;
    }

    /**
     * Why the backend cannot perform a job, or null when it can. Only the hard requirements are checked: the input
     * (still, first frame or every frame), the output (still or animated), the fit when the size changes, and the
     * lossless mode. The
     * metadata requirements are reported by getMetadataGaps().
     */
    public function explainUnsupported(BackendJob $job): ?string
    {
        if ($this->unavailableReason !== null) {
            return 'it is unavailable: ' . $this->unavailableReason;
        }
        $input = $job->getInputFormat();
        $output = $job->getOutputFormat();
        if ($job->isAnimated()) {
            if (!in_array($input, $this->animatedInputFormats, true)) {
                return sprintf('it does not decode every frame of an animated %s', $input);
            }
            if (!in_array($output, $this->animatedOutputFormats, true)) {
                return sprintf('it does not encode an animated %s', $output);
            }
        } else {
            if ($job->getInput()->isAnimated()) {
                if (!in_array($input, $this->firstFrameFormats, true)) {
                    return sprintf('it does not decode the first frame of an animated %s alone', $input);
                }
            } elseif (!in_array($input, $this->inputFormats, true)) {
                return sprintf('it does not decode %s', $input);
            }
            if (!in_array($output, $this->outputFormats, true)) {
                return sprintf('it does not encode %s', $output);
            }
        }
        if ($job->isResized()) {
            $fits = $job->isAnimated() ? $this->animatedFits : $this->fits;
            if ($fits === []) {
                return $job->isAnimated() ? 'it does not resize animations' : 'it does not resize';
            }
            if (!in_array($job->getFit(), $fits, true)) {
                return sprintf(
                    $job->isAnimated() ? 'it does not resize animations with fit %s' : 'it does not resize with fit %s',
                    $job->getFit()
                );
            }
        }
        if ($job->needsLosslessMode() && !in_array($output, $this->losslessFormats, true)) {
            return sprintf('it does not encode a lossless %s', $output);
        }

        return null;
    }

    /**
     * The metadata a job asks to keep that the backend would drop: "the ICC profile" and "the metadata". The resolver
     * prefers a backend without gaps, and reports them when every capable backend has some.
     *
     * @return list<string>
     */
    public function getMetadataGaps(BackendJob $job): array
    {
        $gaps = [];
        if ($job->needsIccProfile() && !$this->iccProfile) {
            $gaps[] = 'the ICC profile';
        }
        if ($job->needsMetadata() && !$this->metadata) {
            $gaps[] = 'the metadata';
        }

        return $gaps;
    }

    /**
     * Array representation, for the capability cache and for diagnostics (/healthz, check-env).
     *
     * @return array{schema: int, backend: string, available: bool, unavailable_reason: string|null, input_formats: list<string>, first_frame_formats: list<string>, animated_input_formats: list<string>, output_formats: list<string>, animated_output_formats: list<string>, lossless_formats: list<string>, fits: list<string>, animated_fits: list<string>, icc_profile: bool, metadata: bool, notes: list<string>}
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'backend' => $this->backend,
            'available' => $this->unavailableReason === null,
            'unavailable_reason' => $this->unavailableReason,
            'input_formats' => $this->inputFormats,
            'first_frame_formats' => $this->firstFrameFormats,
            'animated_input_formats' => $this->animatedInputFormats,
            'output_formats' => $this->outputFormats,
            'animated_output_formats' => $this->animatedOutputFormats,
            'lossless_formats' => $this->losslessFormats,
            'fits' => $this->fits,
            'animated_fits' => $this->animatedFits,
            'icc_profile' => $this->iccProfile,
            'metadata' => $this->metadata,
            'notes' => $this->notes,
        ];
    }

    /**
     * @throws InvalidArgumentException when the backend is unavailable
     */
    private function availableClone(): self
    {
        if ($this->unavailableReason !== null) {
            throw new InvalidArgumentException('BackendCapabilities: an unavailable backend has no capability.');
        }

        return clone $this;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function checkName(string $name): string
    {
        if (!Capabilities::isValidBackendName($name)) {
            throw new InvalidArgumentException('BackendCapabilities: the backend name is not valid.');
        }

        return $name;
    }

    /**
     * @param array<mixed> $formats
     *
     * @throws InvalidArgumentException
     *
     * @return list<string> in canonical order
     */
    private static function checkFormats(string $what, array $formats): array
    {
        $formats = self::stringList($what, $formats);
        foreach ($formats as $format) {
            if (!ImageFormat::isValid($format) || $format === ImageFormat::SVG) {
                throw new InvalidArgumentException(sprintf('BackendCapabilities: the %s must be raster formats.', $what));
            }
        }
        if (count(array_unique($formats)) !== count($formats)) {
            throw new InvalidArgumentException(sprintf('BackendCapabilities: the %s contain duplicates.', $what));
        }

        return array_values(array_intersect(ImageFormat::all(), $formats));
    }

    /**
     * @param array<mixed>  $formats
     * @param array<string> $allowed
     *
     * @throws InvalidArgumentException
     *
     * @return list<string> in canonical order
     */
    private static function checkSubset(string $what, array $formats, array $allowed): array
    {
        $formats = self::checkFormats($what, $formats);
        if (array_diff($formats, $allowed) !== []) {
            throw new InvalidArgumentException(sprintf(
                'BackendCapabilities: the %s must be among: %s.',
                $what,
                implode(', ', $allowed)
            ));
        }

        return $formats;
    }

    /**
     * @param array<mixed>  $fits
     * @param array<string> $allowed
     *
     * @throws InvalidArgumentException
     *
     * @return list<string> in the order of ConversionRequest::fitModes()
     */
    private static function checkFits(string $what, array $fits, array $allowed): array
    {
        $fits = self::stringList($what, $fits);
        if (count(array_unique($fits)) !== count($fits) || array_diff($fits, $allowed) !== []) {
            throw new InvalidArgumentException(sprintf(
                'BackendCapabilities: the %s must be distinct values among: %s.',
                $what,
                implode(', ', $allowed)
            ));
        }

        return array_values(array_intersect(ConversionRequest::fitModes(), $fits));
    }

    /**
     * @param mixed $note
     *
     * @throws InvalidArgumentException
     */
    private static function checkNote($note): string
    {
        if (!is_string($note) || preg_match('/\A[\x20-\x7E]{1,' . self::NOTE_MAX_LENGTH . '}\z/', $note) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'BackendCapabilities: notes and reasons must be 1 to %d printable ASCII characters.',
                self::NOTE_MAX_LENGTH
            ));
        }

        return $note;
    }

    /**
     * @param mixed $value
     *
     * @throws InvalidArgumentException
     *
     * @return list<string>
     */
    private static function stringList(string $what, $value): array
    {
        if (!is_array($value) || array_values($value) !== $value) {
            throw new InvalidArgumentException(sprintf('BackendCapabilities: the %s must be a list of strings.', $what));
        }
        $list = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException(sprintf('BackendCapabilities: the %s must be a list of strings.', $what));
            }
            $list[] = $item;
        }

        return $list;
    }
}
