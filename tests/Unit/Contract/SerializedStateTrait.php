<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Contract;

/**
 * Builds serialized strings of contract value objects with a chosen private state, as an attacker or an outdated
 * cache entry could, to prove that unserialize() validates them again and rebuilds them by value.
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
     * Serialized string of an instance of $class whose private properties hold $state, written as PHP writes them:
     * each under its mangled name ("\0" . class . "\0" . name), or under its bare name when $bareNames is true.
     *
     * $raw maps a property name to the serialized text written for its value instead of serialize($value), for
     * example "R:3;" to make the property a PHP reference to the value numbered 3 (see referenceNumberOf()).
     *
     * @param class-string          $class
     * @param array<string, mixed>  $state     property name => value
     * @param array<string, string> $raw       property name => serialized value
     * @param bool                  $bareNames whether to write bare property names
     */
    private static function serializedWithState(string $class, array $state, array $raw = [], bool $bareNames = false): string
    {
        $body = '';
        foreach ($state as $name => $value) {
            $body .= serialize($bareNames ? $name : "\0" . $class . "\0" . $name);
            $body .= isset($raw[$name]) ? $raw[$name] : serialize($value);
        }

        return sprintf('O:%d:"%s":%d:{%s}', strlen($class), $class, count($state), $body);
    }

    /**
     * Number that an "R:<number>;" back-reference uses for the value of $property in serializedWithState(): values
     * are numbered from 1 in serialization order, the object itself first, and an array counts as one value followed
     * by each of its elements.
     *
     * @param array<string, mixed> $state
     */
    private static function referenceNumberOf(array $state, string $property): int
    {
        $number = 2;
        foreach ($state as $name => $value) {
            if ($name === $property) {
                return $number;
            }
            $number += self::countSerializedValues($value);
        }

        self::fail(sprintf('No property "%s" in the state.', $property));
    }

    /**
     * @param mixed $value
     */
    private static function countSerializedValues($value): int
    {
        if (!is_array($value)) {
            return 1;
        }

        $count = 1;
        foreach ($value as $element) {
            $count += self::countSerializedValues($element);
        }

        return $count;
    }
}
