<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effect2D;

use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Renderer2DInterface;

/**
 * A fragment effect a game draws among its 2D draws: a glow around a panel, scanlines over
 * the screen, a wipe, a sprite with swapped colours. The effect fills a rectangle (plus
 * `$padding` on every side) at its place in the 2D draw order; what lies under it shows
 * where it writes alpha < 1.
 *
 * The fragment stage reads `v_uv` (0..1 over the padded rectangle, (0, 0) top left) and
 * writes `frag_color` in the 2D colour space (the values a 2D colour has, straight alpha).
 * Provided by the renderer: `u_resolution` (vec2, pixels of the padded rectangle), `u_rect`
 * (vec4, the rectangle without padding in `v_uv`: min x, min y, max x, max y), `u_time`
 * (animation time, held at 0 for a frozen effect under reduced motion) and `u_motion`
 * (1, or the game's scale under reduced motion). Further uniforms come from the
 * parameters of the draw call, textures for the names in `$samplers` from its textures.
 *
 * An effect runs where its setting is on, every feature in `$features` is there and the
 * shader compiles; otherwise, and on renderers without effects (the php-glfw renderer,
 * GD, headless), `$fallback` draws instead. Nothing at all happens when there is none.
 */
final class Effect2D
{
    /** php-vio's D3D12 path numbers samplers t0..t7. */
    public const MAX_SAMPLERS = 8;

    /**
     * @param string        $name         lower snake case; also its setting ({@see GraphicsSettings::effectEnabled()})
     *                                    and its entry in the effect register
     * @param string        $fragmentPath GLSL file of the fragment stage
     * @param list<string>  $samplers     sampler2D uniforms of the fragment stage, slots 0.. in this order
     * @param float         $padding      pixels (in the caller's units) the effect may cover outside its rectangle
     * @param list<string>  $features     names of VIO_FEATURE_* constants it needs
     * @param bool          $default      state while the settings hold nothing for the name
     * @param (\Closure(Renderer2DInterface, float, float, float, float, array<string, int|float|list<float>>): void)|null $fallback
     *                                    plain 2D draws in its place: renderer, x, y, w, h, parameters
     */
    public function __construct(
        public readonly string $name,
        public readonly string $fragmentPath,
        public readonly array $samplers = [],
        public readonly float $padding = 0.0,
        public readonly array $features = [],
        public readonly bool $default = true,
        public readonly ?\Closure $fallback = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("2D effect name '{$name}' must be lower snake case");
        }
        if (count($samplers) > self::MAX_SAMPLERS) {
            throw new \InvalidArgumentException("2D effect '{$name}' asks for more than " . self::MAX_SAMPLERS . ' samplers');
        }
        foreach ($samplers as $sampler) {
            if (preg_match('/^u_[a-z0-9_]+$/', $sampler) !== 1) {
                throw new \InvalidArgumentException("Sampler '{$sampler}' of 2D effect '{$name}' must be u_snake_case");
            }
        }
        if ($padding < 0.0) {
            throw new \InvalidArgumentException("2D effect '{$name}' has a negative padding");
        }
    }

    public function enabledBy(GraphicsSettings $settings): bool
    {
        return $settings->effectEnabled($this->name, $this->default);
    }
}
