<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use PHPolygon\Rendering\GraphicsSettings;

/**
 * A fullscreen pass a game adds to the image chain. The fragment stage reads
 * `u_source` (the chain so far) and writes the next image; postprocess.vert hands it
 * `v_uv`. Provided by the renderer: `u_resolution` (vec2, target size), `u_time`, and
 * with `$depth` the scene depth as `u_scene_depth_raw` (raw depth buffer value).
 * Uniforms come from {@see \PHPolygon\Rendering\Command\SetShaderParameter}, textures
 * from {@see \PHPolygon\Rendering\Command\SetShaderTexture} for the names in
 * `$samplers`. A pass runs when its setting is on, every feature in `$features` is
 * there and, with `$depth`, the frame has a readable depth. Otherwise the chain goes
 * on without it, so the frame stays what it was.
 */
final class FullscreenPass
{
    /** Slots of `$samplers`; 0 is `u_source`, 1 the scene depth. */
    public const FIRST_SAMPLER_SLOT = 2;
    public const MAX_SAMPLERS = 6;

    /**
     * @param string       $name     lower snake case; the GPU mark is `post.<name>`, the
     *                               setting {@see GraphicsSettings::effectEnabled()}
     * @param string       $fragmentPath GLSL file of the fragment stage
     * @param int          $order    place within the stage
     * @param bool         $default  state while the settings hold nothing for the name
     * @param list<string> $features names of VIO_FEATURE_* constants the pass needs
     * @param list<string> $samplers extra sampler2D uniforms of the fragment stage
     */
    public function __construct(
        public readonly string $name,
        public readonly PostStage $stage,
        public readonly string $fragmentPath,
        public readonly int $order = 0,
        public readonly bool $default = true,
        public readonly array $features = [],
        public readonly bool $depth = false,
        public readonly array $samplers = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Fullscreen pass name '{$name}' must be lower snake case");
        }
        if (count($samplers) > self::MAX_SAMPLERS) {
            throw new \InvalidArgumentException("Fullscreen pass '{$name}' asks for more than " . self::MAX_SAMPLERS . ' samplers');
        }
    }

    /** The player's setting and the game's run-time switch ({@see FullscreenPassRegistry::setActive()}) both let it run. */
    public function enabledBy(GraphicsSettings $settings): bool
    {
        return $settings->effectEnabled($this->name, $this->default) && FullscreenPassRegistry::isActive($this->name);
    }
}
