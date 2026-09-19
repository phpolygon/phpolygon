<?php

declare(strict_types=1);

namespace PHPolygon\Command;

/**
 * One thing a player does to the game, as data.
 *
 * A command names what it acts on by id, never by list index or object, so it
 * can be written down, sent to another machine and applied there. Its data are
 * the constructor's promoted public properties, limited to scalars and arrays
 * of them; that is what {@see toArray()} writes and {@see fromArray()} reads
 * back, type-checked, since data from the network are someone else's bytes.
 *
 * How a command is checked against and applied to the game's state is the
 * game's business: it hands both to the {@see CommandBus}. A game usually adds
 * an abstract layer declaring check() and apply() for its own state class.
 */
abstract class Command
{
    /** Stable name on the wire, e.g. "hr.hire". Never reuse one for another meaning. */
    abstract public static function type(): string;

    /** @return array<string, mixed> */
    final public function toArray(): array
    {
        /** @var array<string, mixed> $data every property of a command is its data */
        $data = get_object_vars($this);
        return $data;
    }

    /**
     * Rebuild a command from {@see toArray()} output, or null when the data do
     * not fit its constructor: a missing value, an unknown one, or a wrong type.
     *
     * @param array<string, mixed> $data
     */
    final public static function fromArray(array $data): ?static
    {
        $class = new \ReflectionClass(static::class);
        $constructor = $class->getConstructor();
        $params = $constructor?->getParameters() ?? [];

        $args = [];
        foreach ($params as $param) {
            $name = $param->getName();
            if (!array_key_exists($name, $data)) {
                if (!$param->isDefaultValueAvailable()) {
                    return null;
                }
                continue;
            }
            $value = $data[$name];
            if (!self::fits($param->getType(), $value)) {
                return null;
            }
            $args[$name] = $value;
            unset($data[$name]);
        }
        if ($data !== []) {
            return null;
        }

        // Named: every command's constructor is its data, whatever its order.
        return $class->newInstanceArgs($args);
    }

    /** Does a value from the wire fit a constructor parameter's declared type? */
    private static function fits(?\ReflectionType $type, mixed $value): bool
    {
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }
        if ($value === null) {
            return $type->allowsNull();
        }
        return match ($type->getName()) {
            'int'    => is_int($value),
            // JSON writes 2.0 as 2.
            'float'  => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool'   => is_bool($value),
            'array'  => is_array($value) && self::plain($value),
            default  => false,
        };
    }

    /** @param array<mixed> $value */
    private static function plain(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                if (!self::plain($item)) {
                    return false;
                }
            } elseif (!is_scalar($item) && $item !== null) {
                return false;
            }
        }
        return true;
    }
}
