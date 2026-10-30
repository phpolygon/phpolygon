<?php

declare(strict_types=1);

namespace PHPolygon\Testing;

use PHPolygon\Rendering\Texture;
use PHPolygon\Rendering\TextureManager;

/**
 * Texture manager for headless mode that creates dummy textures
 * with configurable dimensions. No GPU calls.
 */
class NullTextureManager extends TextureManager
{
    /** @var array<string, Texture> */
    private array $textures = [];

    /**
     * Register a dummy texture with given dimensions.
     */
    public function register(string $id, int $width, int $height): Texture
    {
        $texture = new Texture(glId: 0, width: $width, height: $height, path: "null://{$id}");
        $this->textures[$id] = $texture;
        return $texture;
    }

    public function load(string $id, ?string $path = null): Texture
    {
        if (isset($this->textures[$id])) {
            return $this->textures[$id];
        }

        // Auto-create a default 64x64 dummy
        return $this->register($id, 64, 64);
    }

    public function loadRgba(string $id, int $width, int $height, string $rgba): ?Texture
    {
        return $this->textures[$id] ?? $this->register($id, $width, $height);
    }

    public function get(string $id): ?Texture
    {
        return $this->textures[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->textures[$id]);
    }

    public function unload(string $id): void
    {
        unset($this->textures[$id]);
    }

    public function clear(): void
    {
        $this->textures = [];
    }
}
