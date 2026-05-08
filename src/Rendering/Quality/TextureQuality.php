<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Texture resolution tier.
 *
 * Full applies textures at their authored resolution. Half and Quarter use
 * a positive GL_TEXTURE_LOD_BIAS so the GPU samples from a smaller mip
 * level, reducing texture-cache pressure on bandwidth-bound hardware.
 */
enum TextureQuality: string
{
    case Full = 'full';
    case Half = 'half';
    case Quarter = 'quarter';

    public function lodBias(): float
    {
        return match ($this) {
            self::Full => 0.0,
            self::Half => 1.0,
            self::Quarter => 2.0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full',
            self::Half => 'Half',
            self::Quarter => 'Quarter',
        };
    }
}
