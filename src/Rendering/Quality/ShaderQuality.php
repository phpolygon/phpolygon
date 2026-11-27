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
 *
 * Lite sits between them: a lit but reduced program of the mesh shader (hemispherical
 * ambient, the sun as one Lambert term with a single shadow tap, a few unshadowed
 * local lights, fog, emission; no specular lobes, IBL, clearcoat, relief, normal or
 * surface patterns, weathering, rain, decals, volumetrics, cloud shadows). It is a
 * variant of the base program, not a shader override: instancing, the proc-mode
 * branches, the surface hook and the MRT path keep working. Only the vio renderers
 * apply it; the GL family and native Vulkan draw it as Full.
 */
enum ShaderQuality: string
{
    case Full = 'full';
    case Lite = 'lite';
    case Unlit = 'unlit';

    /**
     * The id of a global shader override the tier sets (a standing SetShader), or null
     * when the tier is not an override. Lite is null: the renderer reads the tier from the
     * settings and draws its lit base program in the reduced variant.
     */
    public function shaderId(): ?string
    {
        return match ($this) {
            self::Full, self::Lite => null,
            self::Unlit => 'unlit',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full (PBR)',
            self::Lite => 'Lite (reduced lighting)',
            self::Unlit => 'Unlit',
        };
    }
}
