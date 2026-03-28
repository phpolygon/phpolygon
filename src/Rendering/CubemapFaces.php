<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Paths to the six faces of a cubemap.
 * Order: +X (right), -X (left), +Y (top), -Y (bottom), +Z (front), -Z (back).
 */
readonly class CubemapFaces
{
    public function __construct(
        public string $right,
        public string $left,
        public string $top,
        public string $bottom,
        public string $front,
        public string $back,
    ) {}

    /** @return string[] Face paths in GL_TEXTURE_CUBE_MAP order (+X, -X, +Y, -Y, +Z, -Z) */
    public function toArray(): array
    {
        return [$this->right, $this->left, $this->top, $this->bottom, $this->front, $this->back];
    }
}
