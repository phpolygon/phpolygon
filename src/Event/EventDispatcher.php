<?php

declare(strict_types=1);

namespace PHPolygon\Event;

use PHPolygon\Log\Log;

class EventDispatcher
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /** @var array<class-string, list<string>> public property names per event class, cached by reflection once */
    private static array $loggedFields = [];

    public function listen(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(object $event): void
    {
        $class = get_class($event);
        if (Log::$hooks) {
            Log::event($class, self::fields($event));
        }
        $listeners = $this->listeners[$class] ?? [];
        // is_array() guard is intentional: glfwSetWindowMonitor corrupts PHP
        // zvals during the AppKit fullscreen animation. Even with static no-op
        // callbacks, the corrupt integer arguments are pushed to wrong stack
        // positions during call setup, overwriting adjacent properties. $listeners
        // can receive a garbage int despite the PHPDoc type. Do not remove.
        if (!is_array($listeners)) { // @phpstan-ignore function.alreadyNarrowedType
            return;
        }
        foreach ($listeners as $listener) {
            $listener($event);
        }
    }

    public function removeAll(?string $eventClass = null): void
    {
        if ($eventClass === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$eventClass]);
        }
    }

    /**
     * The event's public scalar/enum fields, in declaration order - the list
     * of public property names is reflected once per class, then read off
     * this one instance (skipping an uninitialized typed property, and
     * anything that is not a scalar, null or an enum once read).
     *
     * @return array<string, mixed>
     */
    private static function fields(object $event): array
    {
        $class = get_class($event);
        $names = self::$loggedFields[$class] ??= array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass($event))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );

        $values = [];
        foreach ($names as $name) {
            try {
                $value = $event->$name;
            } catch (\Error) {
                continue; // an uninitialized typed property throws on read
            }
            if (is_scalar($value) || $value === null || $value instanceof \UnitEnum) {
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
