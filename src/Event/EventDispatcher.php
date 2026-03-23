<?php

declare(strict_types=1);

namespace PHPolygon\Event;

class EventDispatcher
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    public function listen(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(object $event): void
    {
        $class = get_class($event);
        foreach ($this->listeners[$class] ?? [] as $listener) {
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
}
