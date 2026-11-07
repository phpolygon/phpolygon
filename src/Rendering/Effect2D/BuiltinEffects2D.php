<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effect2D;

/**
 * Ready-made 2D effects (shader plan 8.1), registered by a game that wants them:
 *
 * - `backdrop_blur`: the 3D image behind a rectangle, blurred and tinted, with rounded
 *   corners - frosted panels, and over the whole screen the blurred scene behind a
 *   menu. Parameters: u_radius (px), u_tint (rgba, a = amount), u_corner (px).
 * - `edge_glow`: a breathing glow round a rectangle (pad it). u_color, u_corner, u_width.
 * - `screen_wipe`: a transition; u_progress 0..1, u_style 0 band / 1 iris / 2 dissolve, u_color.
 * - `visor_overlay`: scanlines, a slow scan band and a vignette over the screen; u_strength, u_tint.
 * - `sdf_icon`: crisp icons from distance functions (u_shape 0 circle .. 7 play), u_color, u_outline.
 *
 * `u_backdrop` is bound by the 2D renderer from the last 3D frame (VioRenderer3D::backdrop()).
 */
final class BuiltinEffects2D
{
    public const BACKDROP_BLUR = 'backdrop_blur';
    public const EDGE_GLOW = 'edge_glow';
    public const SCREEN_WIPE = 'screen_wipe';
    public const VISOR_OVERLAY = 'visor_overlay';
    public const SDF_ICON = 'sdf_icon';

    /** The sampler name the 2D renderer fills with the last 3D frame. */
    public const BACKDROP_SAMPLER = 'u_backdrop';

    public static function register(): void
    {
        $dir = __DIR__ . '/../../../resources/shaders/source/effects/';
        Effect2DRegistry::register(new Effect2D(self::BACKDROP_BLUR, $dir . 'backdrop_blur.frag.glsl', [self::BACKDROP_SAMPLER]));
        Effect2DRegistry::register(new Effect2D(self::EDGE_GLOW, $dir . 'edge_glow.frag.glsl', [], 16.0));
        Effect2DRegistry::register(new Effect2D(self::SCREEN_WIPE, $dir . 'screen_wipe.frag.glsl'));
        Effect2DRegistry::register(new Effect2D(self::VISOR_OVERLAY, $dir . 'visor_overlay.frag.glsl'));
        Effect2DRegistry::register(new Effect2D(self::SDF_ICON, $dir . 'sdf_icon.frag.glsl'));
    }
}
