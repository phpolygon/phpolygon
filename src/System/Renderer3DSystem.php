<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Wind;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetSnowCover;
use PHPolygon\Rendering\Command\SetWaveAnimation;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Renderer3DInterface;

class Renderer3DSystem extends AbstractSystem
{
    private float $wavePhase = 0.0;
    private float $snowCover = 0.0;

    public function __construct(
        private readonly Renderer3DInterface $renderer,
        private readonly RenderCommandList $commandList,
    ) {}

    public function update(World $world, float $dt): void
    {
        $this->wavePhase += $dt;

        // Gradual snow accumulation / melting
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            if ($weather->snowIntensity > 0.1 && $weather->temperature < 2.0) {
                $this->snowCover = min(1.0, $this->snowCover + $weather->snowIntensity * 0.008 * $dt);
            } else {
                $meltRate = $weather->temperature > 5.0 ? 0.006 : 0.003;
                $this->snowCover = max(0.0, $this->snowCover - $meltRate * $dt);
            }
            break;
        }
    }

    public function render(World $world): void
    {
        // Collect lights in render() — must stay in sync with draws to avoid flickering
        foreach ($world->query(DirectionalLight::class, Transform3D::class) as $entity) {
            $light = $entity->get(DirectionalLight::class);
            $this->commandList->add(new SetDirectionalLight(
                $light->direction,
                $light->color,
                $light->intensity,
            ));
        }

        foreach ($world->query(PointLight::class, Transform3D::class) as $entity) {
            $light = $entity->get(PointLight::class);
            $transform = $entity->get(Transform3D::class);
            $this->commandList->add(new AddPointLight(
                $transform->getWorldPosition(),
                $light->color,
                $light->intensity,
                $light->radius,
            ));
        }

        // Wave animation driven by wind + weather
        $windIntensity = 0.5;
        $stormIntensity = 0.0;
        foreach ($world->query(Wind::class) as $entity) {
            $windIntensity = $entity->get(Wind::class)->intensity;
            break;
        }
        foreach ($world->query(Weather::class) as $entity) {
            $stormIntensity = $entity->get(Weather::class)->stormIntensity;
            break;
        }
        $waveAmp = 0.1 + $windIntensity * 0.25 + $stormIntensity * 0.35;
        $waveFreq = 0.4 + $windIntensity * 0.15 + $stormIntensity * 0.15;
        // Snow cover
        $this->commandList->add(new SetSnowCover($this->snowCover));

        $this->commandList->add(new SetWaveAnimation(
            enabled: true,
            amplitude: $waveAmp,
            frequency: $waveFreq,
            phase: $this->wavePhase,
        ));

        // Collect mesh draw calls
        foreach ($world->query(MeshRenderer::class, Transform3D::class) as $entity) {
            $mesh = $entity->get(MeshRenderer::class);
            $transform = $entity->get(Transform3D::class);
            $this->commandList->add(new DrawMesh(
                $mesh->meshId,
                $mesh->materialId,
                $transform->getLocalMatrix(),
            ));
        }

        // Flush command list to renderer
        $this->renderer->render($this->commandList);

        // Clear for next frame
        $this->commandList->clear();
    }
}
