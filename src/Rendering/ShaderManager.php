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
     * Activate a shader globally for all subsequent draw commands this frame.
     * Emits a SetShader command into the current RenderCommandList.
     */
    public function use(string $shaderId): void
    {
        $this->activeOverride = $shaderId;
        $this->commandList?->add(new SetShader($shaderId));
    }

    /**
     * Reset to material-driven shader selection.
     * Emits a SetShader(null) command into the current RenderCommandList.
     */
    public function reset(): void
    {
        $this->activeOverride = null;
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
