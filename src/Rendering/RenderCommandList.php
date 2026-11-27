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

    /**
     * Empty the list for the next frame. A standing shader override (see setStandingShader()) is put back
     * as the first command, so it reaches every draw of every frame.
     */
    public function clear(): void
    {
        $this->commands = $this->standingShader === null ? [] : [$this->standingShader];
    }

    /**
     * A shader override that holds until it is replaced or reset, not for one frame. The renderer drops
     * its override at the start of each frame and the frame loop clears the list after submitting it, so
     * a SetShader added once reached exactly one frame. The ShaderManager sets this; a command that is
     * null (or SetShader with a null id) ends the repeat.
     */
    public function setStandingShader(?Command\SetShader $command): void
    {
        $this->standingShader = $command !== null && $command->shaderId !== null ? $command : null;
    }

    public function standingShader(): ?Command\SetShader
    {
        return $this->standingShader;
    }

    private ?Command\SetShader $standingShader = null;

    /**
     * Replace the whole list (e.g. after reordering it). The caller keeps
     * every command it does not mean to drop.
     *
     * @param list<object> $commands
     */
    public function replaceCommands(array $commands): void
    {
        $this->commands = $commands;
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
