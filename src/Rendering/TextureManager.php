<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use GL\Texture\Texture2D;
use RuntimeException;

class TextureManager
{
    /** @var array<string, Texture> */
    private array $textures = [];

    private string $basePath;

    public function __construct(string $basePath = '')
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function load(string $id, ?string $path = null): Texture
    {
        if (isset($this->textures[$id])) {
            return $this->textures[$id];
        }

        $filePath = $path ?? ($this->basePath ? $this->basePath . '/' . $id : $id);

        if (!file_exists($filePath)) {
            throw new RuntimeException("Texture file not found: {$filePath}");
        }

        $tex2d = Texture2D::fromDisk($filePath);
        $width = $tex2d->width();
        $height = $tex2d->height();

        // Create OpenGL texture
        $glId = 0;
        glGenTextures(1, $glId);
        $texId = is_int($glId) ? $glId : 0;
        glBindTexture(GL_TEXTURE_2D, $texId);

        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);

        glTexImage2D(
            GL_TEXTURE_2D,
            0,
            GL_RGBA,
            $width,
            $height,
            0,
            $tex2d->channels() === 4 ? GL_RGBA : GL_RGB,
            GL_UNSIGNED_BYTE,
            $tex2d->buffer()
        );

        glBindTexture(GL_TEXTURE_2D, 0);

        $texture = new Texture($texId, $width, $height, $filePath);
        $this->textures[$id] = $texture;
        return $texture;
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
        if (isset($this->textures[$id])) {
            $tex = $this->textures[$id];
            $glId = $tex->glId;
            glDeleteTextures(1, $glId);
            unset($this->textures[$id]);
        }
    }

    public function clear(): void
    {
        foreach (array_keys($this->textures) as $id) {
            $this->unload($id);
        }
    }

    public function setBasePath(string $path): void
    {
        $this->basePath = rtrim($path, '/');
    }
}
