<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

/**
 * Heat haze (shader plan 4.2): a fullscreen pass of the Scene stage that wobbles
 * the image in the rising air above {@see \PHPolygon\Component\HeatSource}s. Inputs
 * from {@see \PHPolygon\System\HeatHazeSystem}; a game calls {@see register()} once
 * and adds the system. Effect setting {@see NAME}.
 */
final class HeatHaze
{
    public const string NAME = 'heat_haze';

    public static function register(): void
    {
        if (FullscreenPassRegistry::get(self::NAME) !== null) {
            return;
        }
        FullscreenPassRegistry::register(new FullscreenPass(
            self::NAME,
            PostStage::Scene,
            dirname(__DIR__, 3) . '/resources/shaders/post/heat_haze.frag.glsl',
            order: 20,
        ));
    }
}
