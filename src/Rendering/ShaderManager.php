<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Rendering\Command\SetShader;

/**
 * Game-facing API for shader management.
 * Wraps ShaderRegistry and provides runtime shader switching via RenderCommandList.
 */
class ShaderManager
{
    private ?RenderCommandList $commandList;
    private ?string $activeOverride = null;

    public function __construct(?RenderCommandList $commandList)
    {
        $this->commandList = $commandList;
    }

    /**
     * Register a custom shader. Can also override built-in shaders.
     */
    public function register(string $id, ShaderDefinition $definition): void
    {
        ShaderRegistry::register($id, $definition);
    }

    /**
     * Check if a shader is registered.
     */
    public function has(string $id): bool
    {
        return ShaderRegistry::has($id);
    }

    /**
     * Get a shader definition by ID.
     */
    public function get(string $id): ?ShaderDefinition
    {
        return ShaderRegistry::get($id);
    }

    /**
     * List all registered shader IDs.
     *
     * @return string[]
     */
    public function available(): array
    {
        return ShaderRegistry::ids();
    }

    /**
     * Activate a shader globally for all subsequent draw commands until reset().
     * Emits a SetShader command into the current RenderCommandList, and the list
     * puts it back at the start of every following frame (the renderer drops its
     * override each frame, so a command sent once used to hold for one frame only).
     */
    public function use(string $shaderId): void
    {
        $this->activeOverride = $shaderId;
        $command = new SetShader($shaderId);
        $this->commandList?->add($command);
        $this->commandList?->setStandingShader($command);
    }

    /**
     * Reset to material-driven shader selection.
     * Emits a SetShader(null) command into the current RenderCommandList and ends the repeat.
     */
    public function reset(): void
    {
        $this->activeOverride = null;
        $this->commandList?->setStandingShader(null);
        $this->commandList?->add(new SetShader(null));
    }

    /**
     * Get the currently active global shader override, or null if material-driven.
     */
    public function active(): ?string
    {
        return $this->activeOverride;
    }

    /**
     * Check if a global shader override is active.
     */
    public function isOverridden(): bool
    {
        return $this->activeOverride !== null;
    }
}
