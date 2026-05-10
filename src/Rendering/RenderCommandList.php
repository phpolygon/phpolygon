<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

class RenderCommandList
{
    /** @var list<object> */
    private array $commands = [];

    public function add(object $command): void
    {
        $this->commands[] = $command;
    }

    public function clear(): void
    {
        $this->commands = [];
    }

    /** @return list<object> */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return list<T>
     */
    public function ofType(string $type): array
    {
        $result = [];
        foreach ($this->commands as $command) {
            if ($command instanceof $type) {
                $result[] = $command;
            }
        }
        return $result;
    }

    public function count(): int
    {
        return count($this->commands);
    }

    public function isEmpty(): bool
    {
        return $this->commands === [];
    }

    /**
     * Return the most recent command of the given type, or null when no
     * such command has been pushed this frame.
     *
     * Use this when a system needs to peek at frame-level state another
     * system has already published (e.g. ParticleSystem reading the
     * camera matrix produced by Camera3DSystem) - avoids open-coding a
     * scan of getCommands() at every call site.
     *
     * @template T of object
     * @param class-string<T> $type
     * @return T|null
     */
    public function lastOfType(string $type): ?object
    {
        for ($i = count($this->commands) - 1; $i >= 0; $i--) {
            if ($this->commands[$i] instanceof $type) {
                return $this->commands[$i];
            }
        }
        return null;
    }
}
