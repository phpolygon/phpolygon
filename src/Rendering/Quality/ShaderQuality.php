<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Shader complexity tier.
 *
 * Full uses the engine's full PBR/lighting/shadow stack. Unlit forces every
 * draw through the engine's 'unlit' built-in shader (albedo + emission +
 * fog only). It is the strongest single optimisation available: it skips
 * lighting math, shadow sampling, and most uniforms.
 */
enum ShaderQuality: string
{
    case Full = 'full';
    case Unlit = 'unlit';

    public function shaderId(): ?string
    {
        return match ($this) {
            self::Full => null,
            self::Unlit => 'unlit',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full (PBR)',
            self::Unlit => 'Unlit',
        };
    }
}
