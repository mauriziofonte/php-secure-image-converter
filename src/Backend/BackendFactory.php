<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Isolation\ProcessRunnerInterface;

/**
 * Creates the backends by name, in the order of preference BackendResolver tries them.
 *
 * | Order | Backend  | Program                          | Why there                                         |
 * | ----- | -------- | -------------------------------- | ------------------------------------------------- |
 * | 1     | vips     | vips                             | every common format, resize, animation, fastest   |
 * | 2     | cwebp    | cwebp                            | still WEBP from PNG, JPEG, TIFF and WEBP          |
 * | 3     | gif2webp | gif2webp                         | animated WEBP from GIF, in little memory          |
 * | 4     | avifenc  | avifenc                          | AVIF from PNG and JPEG                            |
 * | 5     | imagick  | PHP worker with ext-imagick      | shell-free fallback, BMP and TIFF                 |
 * | 6     | gd       | PHP worker with ext-gd           | shell-free fallback for the common still formats  |
 */
final class BackendFactory
{
    /**
     * Every backend name, in order of preference.
     */
    public const NAMES = [
        VipsBackend::NAME,
        CwebpBackend::NAME,
        Gif2webpBackend::NAME,
        AvifencBackend::NAME,
        ImagickBackend::NAME,
        GdBackend::NAME,
    ];

    /**
     * Creates one backend.
     *
     * @throws InvalidArgumentException when the name is unknown
     */
    public static function create(string $name, ProcessRunnerInterface $runner, ?BackendOptions $options = null): BackendInterface
    {
        switch ($name) {
            case VipsBackend::NAME:
                return new VipsBackend($runner, $options);
            case CwebpBackend::NAME:
                return new CwebpBackend($runner, $options);
            case Gif2webpBackend::NAME:
                return new Gif2webpBackend($runner, $options);
            case AvifencBackend::NAME:
                return new AvifencBackend($runner, $options);
            case ImagickBackend::NAME:
                return new ImagickBackend($runner, $options);
            case GdBackend::NAME:
                return new GdBackend($runner, $options);
            default:
                throw new InvalidArgumentException(sprintf(
                    'Unknown backend; the backends are: %s.',
                    implode(', ', self::NAMES)
                ));
        }
    }

    /**
     * Creates backends, in order of preference.
     *
     * @param list<string>|null $names backends to enable (any order, see parseNames()), or null for all
     *
     * @throws InvalidArgumentException when a name is unknown
     *
     * @return list<BackendInterface>
     */
    public static function createAll(ProcessRunnerInterface $runner, ?BackendOptions $options = null, ?array $names = null): array
    {
        $backends = [];
        foreach (self::sort($names === null ? self::NAMES : $names) as $name) {
            $backends[] = self::create($name, $runner, $options);
        }

        return $backends;
    }

    /**
     * Creates a resolver over the enabled backends with the memory ceiling of the options.
     *
     * @param list<string>|null $names backends to enable, or null for all
     *
     * @throws InvalidArgumentException when a name is unknown
     */
    public static function createResolver(ProcessRunnerInterface $runner, ?BackendOptions $options = null, ?array $names = null): BackendResolver
    {
        $options = $options !== null ? $options : new BackendOptions();

        return new BackendResolver(self::createAll($runner, $options, $names), $options->getMemoryCeilingBytes());
    }

    /**
     * Parses a comma-separated list of backend names, such as the value of a configuration variable ("vips,gd").
     * Spaces around names are ignored; the result is in order of preference, without duplicates.
     *
     * @throws InvalidArgumentException when the list is empty or a name is unknown
     *
     * @return list<string>
     */
    public static function parseNames(string $list): array
    {
        $names = [];
        foreach (explode(',', $list) as $name) {
            $name = strtolower(trim($name));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        if ($names === []) {
            throw new InvalidArgumentException('The list of backends is empty.');
        }

        return self::sort($names);
    }

    /**
     * @param array<mixed> $names
     *
     * @throws InvalidArgumentException
     *
     * @return list<string>
     */
    private static function sort(array $names): array
    {
        foreach ($names as $name) {
            if (!is_string($name) || !in_array($name, self::NAMES, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown backend; the backends are: %s.',
                    implode(', ', self::NAMES)
                ));
            }
        }

        return array_values(array_intersect(self::NAMES, $names));
    }
}
