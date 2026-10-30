<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract;

use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * Serialization of the contract value objects: an unserialized instance is validated again and rebuilt by value.
 *
 * serialize() writes the private properties under their mangled names ("\0" . class . "\0" . name), in declaration
 * order, which is what PHP writes natively: the serialized form is the same on every supported PHP version, so a
 * string written by one version is read by any other. unserialize() accepts every declared property exactly once,
 * in any order, under its mangled or its bare name, as PHP itself does, and nothing else; on PHP 7.2 and 7.3, which
 * restore natively, a property missing from the string keeps its default instead (see below: defaults are valid).
 *
 * The values are then checked by rebuilding the instance through its public validation (validatedCopy() of the
 * using class) and comparing: a tampered, truncated or outdated string throws InvalidArgumentException instead of
 * producing an instance outside the documented domain. Finally every property is replaced by the value of the
 * rebuilt copy. A crafted string can make two properties, or a property and an array element, share one PHP
 * reference ("R:" back-references), which survives a plain check: a with*() method writing to one property of a
 * clone, or a caller writing to an array returned by a getter, would then also change the other property, in the
 * original object too. The rebuilt copy holds plain values and freshly built arrays, and each property is unset
 * before it is assigned, so that the assignment cannot write through a reference.
 *
 * PHP 7.4 and later call __serialize() and __unserialize(); PHP 7.2 and 7.3 serialize natively, in the same format,
 * and call __wakeup(). The using class implements validatedCopy(): self, which builds a new instance from its current
 * property values through its public constructor or factory, and throws an ExceptionInterface when a value is not
 * valid, whatever its type.
 *
 * Two ways of creating an instance bypass every method of this trait and of the constructor: unserialize() of the
 * "C:" format, meant for classes implementing \Serializable, which PHP 7.2 to 8.5 answer for any other class with a
 * mere warning ("has no unserializer") and an instance holding the property defaults, and
 * ReflectionClass::newInstanceWithoutConstructor(). The using class therefore gives every property a default, and
 * the defaults together form a valid instance.
 *
 * @internal
 */
trait ValidatedSerializationTrait
{
    /**
     * Properties under their mangled names, in declaration order (PHP 7.4 and later; older versions write the same).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = [];
        foreach (get_object_vars($this) as $name => $value) {
            $data["\0" . self::class . "\0" . $name] = $value;
        }

        return $data;
    }

    /**
     * Restores an instance from serialized properties (PHP 7.4 and later).
     *
     * @param array<mixed> $data property name, mangled or bare => value
     *
     * @throws InvalidArgumentException when the properties are not exactly the declared ones or do not form a valid
     *                                  instance
     */
    public function __unserialize(array $data): void
    {
        $declared = array_keys(get_object_vars($this));
        $prefix = "\0" . self::class . "\0";
        $restored = [];
        foreach ($data as $key => $value) {
            $name = (string) $key;
            if (strpos($name, $prefix) === 0) {
                $name = substr($name, strlen($prefix));
            }
            if (!in_array($name, $declared, true) || in_array($name, $restored, true)) {
                throw self::invalidSerializedState();
            }
            $this->{$name} = $value;
            $restored[] = $name;
        }
        if (count($restored) !== count($declared)) {
            throw self::invalidSerializedState();
        }

        $this->rebuildByValue();
    }

    /**
     * Validates and rebuilds an instance that PHP 7.2 or 7.3 unserialized natively (later versions call
     * __unserialize() instead).
     *
     * @throws InvalidArgumentException when the properties do not form a valid instance
     */
    public function __wakeup(): void
    {
        $this->rebuildByValue();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function rebuildByValue(): void
    {
        $state = get_object_vars($this);

        try {
            $copy = $this->validatedCopy();
        } catch (ExceptionInterface $exception) {
            throw self::invalidSerializedState();
        }

        $values = get_object_vars($copy);
        if ($values !== $state) {
            throw self::invalidSerializedState();
        }

        foreach ($values as $name => $value) {
            unset($this->{$name});
            $this->{$name} = $value;
        }
    }

    private static function invalidSerializedState(): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('The serialized %s is not valid.', self::class));
    }
}
