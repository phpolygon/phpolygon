<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effect2D;

use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Texture;

/**
 * A 2D renderer that can draw the effects of {@see Effect2DRegistry}. Games call
 * {@see Effect2DRegistry::draw()}, which uses this where the renderer has it and the
 * effect's fallback everywhere else.
 */
interface EffectRenderer2DInterface
{
    /**
     * Draw a registered effect over the rectangle at the current place in the draw
     * order, under the current transform, scissor and global alpha. False when it
     * cannot run here (unknown, switched off, a missing feature, a shader that does not
     * compile, held back by reduced motion or the shader quality): nothing was drawn.
     *
     * @param array<string, int|float|list<float>> $parameters uniform values by name
     * @param array<string, Texture>               $textures   textures for the effect's samplers
     * @param float|null                           $padding    overrides the effect's padding
     */
    public function drawEffect(
        string $name,
        float $x,
        float $y,
        float $w,
        float $h,
        array $parameters = [],
        array $textures = [],
        ?float $padding = null,
    ): bool;

    /** Whether a registered effect can run on this renderer at all; a settings screen greys out the rest. */
    public function effectAvailable(string $name): bool;

    /** The settings the effects follow: their on/off state and the shader quality. */
    public function applyEffectSettings(GraphicsSettings $settings): void;
}
