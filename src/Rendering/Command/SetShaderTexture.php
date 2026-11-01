<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

/**
 * Texture of a sampler declared with ProcModeShaderRegistry::declareSampler(). Sticky
 * like SetShaderParameter. Null, or an id the texture manager cannot load, binds white.
 */
readonly class SetShaderTexture
{
    public function __construct(
        public string $name,
        public ?string $textureId,
    ) {}
}
