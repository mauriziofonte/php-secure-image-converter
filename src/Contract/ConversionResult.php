<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * Outcome of a successful conversion: where the output is and what it verifiably contains.
 *
 * Immutable value object. Format, dimensions and frame count describe the output file as verified after encoding
 * (sniffed from its content), not as requested. A passthrough result means that the output file holds the input
 * bytes unchanged, which a converter does only when the input already satisfies the request (see
 * ConverterInterface::convert()). An unserialized instance is validated again, see __wakeup().
 *
 * toArray() is safe for untrusted clients; toInternalArray() adds the output path and is meant for server logs.
 */
final class ConversionResult
{
    /**
     * Backend name of a passthrough for which no backend ran. Reserved: no backend may use this name.
     */
    public const BACKEND_NONE = 'none';

    /**
     * Maximum length of one warning, in characters.
     */
    public const WARNING_MAX_LENGTH = 256;

    /**
     * @var string
     */
    private $outputPath;

    /**
     * @var string
     */
    private $outputFormat;

    /**
     * @var int
     */
    private $width;

    /**
     * @var int
     */
    private $height;

    /**
     * @var int
     */
    private $byteSize;

    /**
     * @var int
     */
    private $frameCount;

    /**
     * @var string
     */
    private $backend;

    /**
     * @var int
     */
    private $durationMs;

    /**
     * @var string
     */
    private $isolationLevel;

    /**
     * @var bool
     */
    private $passthrough;

    /**
     * @var list<string>
     */
    private $warnings;

    /**
     * @param string       $outputPath     path of the output file, as passed to ConverterInterface::convert()
     * @param string       $outputFormat   ImageFormat value sniffed from the output content
     * @param int          $width          output width in pixels, at least 1
     * @param int          $height         output height in pixels, at least 1
     * @param int          $byteSize       output size in bytes, at least 1
     * @param int          $frameCount     number of frames in the output: 1 for a still image, more only for a
     *                                     format of ImageFormat::animatable()
     * @param string       $backend        name of the backend that produced the output (see
     *                                     Capabilities::isValidBackendName()), or BACKEND_NONE for a passthrough for
     *                                     which no backend ran
     * @param int          $durationMs     wall-clock duration of the whole conversion in milliseconds, at least 0
     * @param string       $isolationLevel IsolationLevel value the conversion actually ran under
     * @param bool         $passthrough    whether the output holds the input bytes unchanged
     * @param list<string> $warnings       non-fatal events worth reporting, e.g. a fallback to another format or
     *                                     a weaker isolation level under a best_effort policy: each 1 to
     *                                     WARNING_MAX_LENGTH printable ASCII characters (no control characters,
     *                                     so a warning is safe in a log line or a header), never a path, a secret or
     *                                     raw input
     *
     * @throws InvalidArgumentException when a value is outside its documented domain
     */
    public function __construct(
        string $outputPath,
        string $outputFormat,
        int $width,
        int $height,
        int $byteSize,
        int $frameCount,
        string $backend,
        int $durationMs,
        string $isolationLevel,
        bool $passthrough,
        array $warnings = []
    ) {
        if ($outputPath === '' || strpos($outputPath, "\0") !== false) {
            throw new InvalidArgumentException('The output path must be a non-empty string without NUL bytes.');
        }
        if (!ImageFormat::isValid($outputFormat)) {
            throw new InvalidArgumentException(sprintf(
                'The output format must be one of: %s.',
                implode(', ', ImageFormat::all())
            ));
        }
        self::assertAtLeast('width', $width, 1);
        self::assertAtLeast('height', $height, 1);
        self::assertAtLeast('byte size', $byteSize, 1);
        self::assertAtLeast('frame count', $frameCount, 1);
        if ($frameCount > 1 && !ImageFormat::supportsAnimation($outputFormat)) {
            throw new InvalidArgumentException(sprintf(
                'Only animatable formats (%s) can have more than one frame.',
                implode(', ', ImageFormat::animatable())
            ));
        }
        self::assertAtLeast('duration', $durationMs, 0);
        if ($backend === self::BACKEND_NONE) {
            if (!$passthrough) {
                throw new InvalidArgumentException('The backend "none" is reserved for a passthrough.');
            }
        } elseif (!Capabilities::isValidBackendName($backend)) {
            throw new InvalidArgumentException(
                'The backend name must be 1 to 32 lowercase letters, digits, "_" or "-", starting with a letter or digit.'
            );
        }
        if (!IsolationLevel::isValid($isolationLevel)) {
            throw new InvalidArgumentException(sprintf(
                'The isolation level must be one of: %s.',
                implode(', ', IsolationLevel::all())
            ));
        }

        $this->outputPath = $outputPath;
        $this->outputFormat = $outputFormat;
        $this->width = $width;
        $this->height = $height;
        $this->byteSize = $byteSize;
        $this->frameCount = $frameCount;
        $this->backend = $backend;
        $this->durationMs = $durationMs;
        $this->isolationLevel = $isolationLevel;
        $this->passthrough = $passthrough;
        $this->warnings = self::checkWarnings($warnings);
    }

    /**
     * Path of the output file. Never expose it to untrusted clients: it reveals the server's file layout.
     */
    public function getOutputPath(): string
    {
        return $this->outputPath;
    }

    /**
     * ImageFormat value sniffed from the output content. It can differ from the requested format after a fallback.
     */
    public function getOutputFormat(): string
    {
        return $this->outputFormat;
    }

    /**
     * Canonical MIME type of the output format, suitable for a Content-Type header.
     */
    public function getMimeType(): string
    {
        return ImageFormat::mimeType($this->outputFormat);
    }

    /**
     * Output width in pixels.
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * Output height in pixels.
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Output file size in bytes.
     */
    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    /**
     * Number of frames in the output: 1 for a still image.
     */
    public function getFrameCount(): int
    {
        return $this->frameCount;
    }

    /**
     * Whether the output has more than one frame.
     */
    public function isAnimated(): bool
    {
        return $this->frameCount > 1;
    }

    /**
     * Name of the backend that produced the output, or BACKEND_NONE when no backend ran. For a passthrough caused by
     * the "keep the smaller file" rule it names the backend whose output was discarded.
     */
    public function getBackend(): string
    {
        return $this->backend;
    }

    /**
     * Wall-clock duration of the whole conversion (validation, isolation, encoding, verification) in milliseconds.
     */
    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    /**
     * IsolationLevel value the conversion actually ran under.
     */
    public function getIsolationLevel(): string
    {
        return $this->isolationLevel;
    }

    /**
     * Whether the output file holds the input bytes unchanged.
     */
    public function isPassthrough(): bool
    {
        return $this->passthrough;
    }

    /**
     * Non-fatal events worth reporting, in the order they happened.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Every value except the output path, with snake_case keys: safe to return to untrusted clients, e.g. as JSON.
     *
     * @return array{output_format: string, mime_type: string, width: int, height: int, byte_size: int, frame_count: int, backend: string, duration_ms: int, isolation_level: string, passthrough: bool, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'output_format' => $this->outputFormat,
            'mime_type' => $this->getMimeType(),
            'width' => $this->width,
            'height' => $this->height,
            'byte_size' => $this->byteSize,
            'frame_count' => $this->frameCount,
            'backend' => $this->backend,
            'duration_ms' => $this->durationMs,
            'isolation_level' => $this->isolationLevel,
            'passthrough' => $this->passthrough,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * toArray() plus output_path, for server-side logs only: the path reveals the server's file layout and must never
     * reach untrusted clients.
     *
     * @return array{output_path: string, output_format: string, mime_type: string, width: int, height: int, byte_size: int, frame_count: int, backend: string, duration_ms: int, isolation_level: string, passthrough: bool, warnings: list<string>}
     */
    public function toInternalArray(): array
    {
        return ['output_path' => $this->outputPath] + $this->toArray();
    }

    /**
     * Validates an unserialized instance as the constructor would, so that a tampered or outdated serialized string
     * cannot produce a result outside the documented domain.
     *
     * @throws InvalidArgumentException when the unserialized state is not valid
     */
    public function __wakeup(): void
    {
        $state = get_object_vars($this);

        try {
            // strict_types makes a property of the wrong type a TypeError instead of a silent conversion.
            $copy = new self(
                $this->outputPath,
                $this->outputFormat,
                $this->width,
                $this->height,
                $this->byteSize,
                $this->frameCount,
                $this->backend,
                $this->durationMs,
                $this->isolationLevel,
                $this->passthrough,
                $this->warnings
            );
            $valid = get_object_vars($copy) === $state;
        } catch (\TypeError $exception) {
            $valid = false;
        } catch (InvalidArgumentException $exception) {
            $valid = false;
        }
        if (!$valid) {
            throw new InvalidArgumentException('The serialized ConversionResult is not valid.');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertAtLeast(string $name, int $value, int $min): void
    {
        if ($value < $min) {
            throw new InvalidArgumentException(sprintf('The %s must be at least %d, got %d.', $name, $min, $value));
        }
    }

    /**
     * @param array<mixed> $warnings
     *
     * @throws InvalidArgumentException
     *
     * @return list<string>
     */
    private static function checkWarnings(array $warnings): array
    {
        $checked = [];
        $expectedKey = 0;
        foreach ($warnings as $key => $warning) {
            if ($key !== $expectedKey || !is_string($warning) || preg_match('/\A[\x20-\x7E]{1,' . self::WARNING_MAX_LENGTH . '}\z/', $warning) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'The warnings must be a list of strings of 1 to %d printable ASCII characters.',
                    self::WARNING_MAX_LENGTH
                ));
            }
            $checked[] = $warning;
            ++$expectedKey;
        }

        return $checked;
    }
}
