<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\HeatSource;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\PostProcess\FullscreenPassRegistry;
use PHPolygon\Rendering\PostProcess\HeatHaze;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ScreenProjection;

/**
 * Inputs of the {@see \PHPolygon\Rendering\PostProcess\HeatHaze} pass: the (up to)
 * four {@see HeatSource}s nearest to the camera that are on screen and within
 * {@see MAX_DISTANCE}, projected to the screen with their radius as a fraction of
 * the screen height and their strength (fading with distance, following a
 * PointLight on the entity). Register after the camera, before Renderer3DSystem.
 *
 * It owns the pass's run-time switch: with no source in reach the pass would draw
 * nothing, so it does not run at all that frame.
 */
class HeatHazeSystem extends AbstractSystem
{
    public const int SLOTS = 4;
    public const float MAX_DISTANCE = 60.0;

    public function __construct(private readonly RenderCommandList $commandList) {}

    public function update(World $world, float $dt): void
    {
        // Render-only.
    }

    public function render(World $world): void
    {
        $cameras = $this->commandList->ofType(SetCamera::class);
        $sources = $cameras === [] ? [] : self::sources($world, $cameras[0]);
        FullscreenPassRegistry::setActive(HeatHaze::NAME, $sources !== []);
        $this->commandList->add(new SetShaderParameter('u_heat_count', count($sources)));
        for ($k = 0; $k < self::SLOTS; $k++) {
            $this->commandList->add(new SetShaderParameter("u_heat_sources[{$k}]", $sources[$k] ?? [0.0, 0.0, 0.0, 0.0]));
        }
    }

    /**
     * @return list<list<float>> (x, y, radius on screen, strength), nearest first
     */
    public static function sources(World $world, SetCamera $camera): array
    {
        $eye = ScreenProjection::cameraPosition($camera->viewMatrix);
        $vp = $camera->projectionMatrix->multiply($camera->viewMatrix);
        // Screen height covered by one metre at one metre: P[1][1] / 2.
        $p = $camera->projectionMatrix->toArray();
        $scale = abs($p[5]) * 0.5;
        $found = [];
        foreach ($world->query(HeatSource::class, Transform3D::class) as $entity) {
            $heat = $entity->get(HeatSource::class);
            $pos = $entity->get(Transform3D::class)->getWorldPosition();
            $dist = $pos->sub($eye)->length();
            if ($dist > self::MAX_DISTANCE || $dist < 0.5) {
                continue;
            }
            $strength = $heat->strength * (1.0 - $dist / self::MAX_DISTANCE);
            if ($entity->has(PointLight::class) && $heat->fullIntensity > 0.0) {
                $strength *= max(0.0, min(1.0, $entity->get(PointLight::class)->intensity / $heat->fullIntensity));
            }
            if ($strength <= 0.01) {
                continue;
            }
            $s = ScreenProjection::worldToScreen($pos, $vp, 1.0, 1.0, 0.2);
            if ($s === null) {
                continue;
            }
            $found[] = [$dist, [$s->x, $s->y, $heat->radius * $scale / $dist, $strength]];
        }
        usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(static fn (array $f): array => $f[1], array_slice($found, 0, self::SLOTS));
    }
}
