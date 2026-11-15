<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effect2D;

use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Renderer2DInterface;

/**
 * Implements {@see EffectRenderer2DInterface} for a 2D renderer that wraps
 * another one (a mirroring, scaling or recording decorator).
 *
 * The engine hands the accessibility settings (colour vision correction, reduced
 * motion) and the effect settings to `$engine->renderer2D` only when that object
 * is an EffectRenderer2DInterface. A decorator that is not one silently cuts the
 * wrapped VioRenderer2D off from them, so a player's colour correction is lost
 * the moment the game wraps or swaps the renderer. Use this trait and return the
 * wrapped renderer from effectTarget(); after swapping the renderer call
 * `GraphicsSettingsManager::applyToRenderer()` once.
 */
trait ForwardsEffects
{
    /** The renderer this one wraps. */
    abstract protected function effectTarget(): Renderer2DInterface;

    /** @param array<string, int|float|list<float>> $parameters */
    public function drawEffect(
        string $name,
        float $x,
        float $y,
        float $w,
        float $h,
        array $parameters = [],
        array $textures = [],
        ?float $padding = null,
    ): bool {
        $target = $this->effectTarget();

        return $target instanceof EffectRenderer2DInterface
            && $target->drawEffect($name, $x, $y, $w, $h, $parameters, $textures, $padding);
    }

    public function effectAvailable(string $name): bool
    {
        $target = $this->effectTarget();

        return $target instanceof EffectRenderer2DInterface && $target->effectAvailable($name);
    }

    public function applyEffectSettings(GraphicsSettings $settings): void
    {
        $target = $this->effectTarget();
        if ($target instanceof EffectRenderer2DInterface) {
            $target->applyEffectSettings($settings);
        }
    }
}
