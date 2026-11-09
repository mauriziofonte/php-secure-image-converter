<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend\Internal;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * Facts a backend learned while probing that its command lines depend on, such as whether vips knows the keep
 * option: a small map of lower-case names to short printable values.
 *
 * @internal
 */
final class Facts
{
    /**
     * @param array<mixed> $facts
     *
     * @throws InvalidArgumentException when a name or value is not valid
     *
     * @return array<string, string>
     */
    public static function check(array $facts): array
    {
        if (count($facts) > 32) {
            throw new InvalidArgumentException('Backend facts: too many facts.');
        }
        $checked = [];
        foreach ($facts as $name => $value) {
            if (
                !is_string($name)
                || preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $name) !== 1
                || !is_string($value)
                || preg_match('/\A[\x20-\x7E]{0,200}\z/', $value) !== 1
            ) {
                throw new InvalidArgumentException('Backend facts: invalid name or value.');
            }
            $checked[$name] = $value;
        }
        ksort($checked);

        return $checked;
    }
}
