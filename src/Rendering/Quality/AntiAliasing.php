<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Anti-aliasing technique selection.
 *
 * Off:    No AA, fastest path.
 * FXAA:   Cheap post-process AA, no FBO sample multiplier.
 * MSAA2x: 2x multisample anti-aliasing on the main framebuffer.
 * MSAA4x: 4x multisample anti-aliasing on the main framebuffer.
 *
 * Implementation note: MSAA requires an off-screen multisample FBO that is
 * resolved before presentation. That FBO is part of the render-scale work
 * planned for Phase 1.5 - until then the Renderer3D backends honour the
 * sample count where the windowing layer already provides it (e.g. GLFW's
 * GLFW_SAMPLES hint) and treat MSAA settings as a no-op otherwise.
 */
enum AntiAliasing: string
{
    case Off = 'off';
    case Fxaa = 'fxaa';
    case Msaa2x = 'msaa2x';
    case Msaa4x = 'msaa4x';

    public function sampleCount(): int
    {
        return match ($this) {
            self::Off, self::Fxaa => 1,
            self::Msaa2x => 2,
            self::Msaa4x => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Fxaa => 'FXAA',
            self::Msaa2x => 'MSAA 2x',
            self::Msaa4x => 'MSAA 4x',
        };
    }
}
