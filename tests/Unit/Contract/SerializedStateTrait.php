<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Contract;

/**
 * Builds serialized strings of contract value objects with a chosen private state, as an attacker or an outdated
 * cache entry could, to prove that unserialize() validates them again.
 */
trait SerializedStateTrait
{
    /**
     * Private properties of an object, keyed by property name.
     *
     * @return array<string, mixed>
     */
    private static function stateOf(object $object): array
    {
        $state = [];
        foreach ((array) $object as $key => $value) {
            $key = (string) $key;
            $separator = strrpos($key, "\0");
            $state[$separator === false ? $key : substr($key, $separator + 1)] = $value;
        }

        return $state;
    }

    /**
     * Serialized string of an instance of $class whose private properties hold $state.
     *
     * @param class-string         $class
     * @param array<string, mixed> $state property name => value
     */
    private static function serializedWithState(string $class, array $state): string
    {
        $body = '';
        foreach ($state as $name => $value) {
            $body .= serialize("\0" . $class . "\0" . $name) . serialize($value);
        }

        return sprintf('O:%d:"%s":%d:{%s}', strlen($class), $class, count($state), $body);
    }
}
