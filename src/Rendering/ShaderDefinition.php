<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Describes a shader program by its GLSL source file paths.
 * Backends compile these sources into GPU-specific programs.
 */
readonly class ShaderDefinition
{
    public function __construct(
        public string $vertexPath,
        public string $fragmentPath,
    ) {}
}
