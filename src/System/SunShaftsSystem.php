<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\PostProcess\FullscreenPassRegistry;
use PHPolygon\Rendering\PostProcess\SunShafts;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ScreenProjection;

/**
 * Inputs of the {@see \PHPolygon\Rendering\PostProcess\SunShafts} pass: the sun's
 * screen position from this frame's SetSky and SetCamera, its strength (up, in
 * front of the camera, fading towards the horizon and as it leaves the screen,
 * weaker under clouds) and colour. Register after the day/night system and the
 * camera, before Renderer3DSystem.
 *
 * It owns the pass's run-time switch: without a sun on screen the pass would draw
 * nothing, so it does not run at all that frame.
 */
class SunShaftsSystem extends AbstractSystem
{
    /** Below this strength the shafts are invisible and the pass is switched off. */
    public const float MIN_STRENGTH = 0.001;

    /**
     * @param float $strength overall strength of the shafts
     */
    public function __construct(
        private readonly RenderCommandList $commandList,
        private readonly float $strength = 1.0,
    ) {
    }

    public function update(World $world, float $dt): void
    {
        // Render-only.
    }

    public function render(World $world): void
    {
        $skies = $this->commandList->ofType(SetSky::class);
        $cameras = $this->commandList->ofType(SetCamera::class);
        $off = [0.0, 0.0, 0.0, 0.0];
        if ($skies === [] || $cameras === []) {
            $this->commandList->add(new SetShaderParameter('u_sun_shafts', $off));
            FullscreenPassRegistry::setActive(SunShafts::NAME, false);
            return;
        }
        $sky = $skies[count($skies) - 1];
        $camera = $cameras[0];
        [$uv, $strength] = self::sunOnScreen($sky, $camera, $this->strength);
        FullscreenPassRegistry::setActive(SunShafts::NAME, $uv !== null && $strength > self::MIN_STRENGTH);
        $this->commandList->add(new SetShaderParameter('u_sun_shafts', $uv === null ? $off : [$uv[0], $uv[1], $strength, 1.0]));
        $this->commandList->add(new SetShaderParameter('u_sun_shaft_color', [$sky->sunColor->r, $sky->sunColor->g, $sky->sunColor->b, 1.0]));
    }

    /**
     * Sun position on screen (0..1, origin top left) and the shafts' strength, or
     * null when the sun is down or behind the camera.
     *
     * @return array{0: ?array{0: float, 1: float}, 1: float}
     */
    public static function sunOnScreen(SetSky $sky, SetCamera $camera, float $strength): array
    {
        $d = $sky->sunDirection;
        $len = $d->length();
        if ($len < 1e-6 || $sky->sunIntensity <= 0.01) {
            return [null, 0.0];
        }
        $dir = $d->mul(1.0 / $len);
        if ($dir->y <= 0.0) {
            return [null, 0.0];
        }
        $eye = ScreenProjection::cameraPosition($camera->viewMatrix);
        $vp = $camera->projectionMatrix->multiply($camera->viewMatrix);
        // Far enough to sit on the sky, near enough for float precision.
        $p = ScreenProjection::worldToScreen($eye->add($dir->mul(2000.0)), $vp, 1.0, 1.0, 0.6);
        if ($p === null) {
            return [null, 0.0];
        }
        $edge = max(abs($p->x - 0.5), abs($p->y - 0.5));
        $onScreen = 1.0 - max(0.0, min(1.0, ($edge - 0.5) / 0.3));
        $high = max(0.0, min(1.0, $dir->y / 0.08));
        $clear = 1.0 - 0.7 * max(0.0, min(1.0, $sky->cloudCover));

        return [[$p->x, $p->y], $strength * $onScreen * $high * $clear * min(1.0, $sky->sunIntensity)];
    }
}
