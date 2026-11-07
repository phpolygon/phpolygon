<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

/**
 * Sun shafts in screen space (shader plan 4.2): a fullscreen pass of the Scene stage
 * that streams the bright sky round the sun past everything in front of it. Its
 * inputs come from {@see \PHPolygon\System\SunShaftsSystem}; a game calls
 * {@see register()} once and adds the system. The player switches it with the
 * effect setting {@see NAME}.
 */
final class SunShafts
{
    public const string NAME = 'sun_shafts';

    public static function register(): void
    {
        if (FullscreenPassRegistry::get(self::NAME) !== null) {
            return;
        }
        FullscreenPassRegistry::register(new FullscreenPass(
            self::NAME,
            PostStage::Scene,
            dirname(__DIR__, 3) . '/resources/shaders/post/sun_shafts.frag.glsl',
            order: 10,
            depth: true,
        ));
    }
}
