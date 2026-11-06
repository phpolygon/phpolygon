<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Reflection\ReflectionProbe;
use PHPolygon\Rendering\Reflection\ReflectionProbes;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ScreenProjection;

/**
 * Binds the local reflection probes nearest to the camera
 * ({@see ReflectionProbes::nearest()}) for the snippet library. The commands are
 * sticky, so they are queued only when the chosen probes change.
 *
 * Reads SetCamera: register after {@see Camera3DSystem} and before
 * {@see Renderer3DSystem}.
 */
class ReflectionProbeSystem extends AbstractSystem
{
    /** @var list<ReflectionProbe>|null the probes bound last, null before the first frame */
    private ?array $bound = null;

    public function __construct(
        private readonly RenderCommandList $commandList,
    ) {}

    public function update(World $world, float $dt): void
    {
        // Render-only.
    }

    public function render(World $world): void
    {
        if (!ReflectionProbes::isRegistered()) {
            return;
        }
        $cameras = $this->commandList->ofType(SetCamera::class);
        if ($cameras === []) {
            return;
        }
        $eye = ScreenProjection::cameraPosition($cameras[0]->viewMatrix);
        $chosen = ReflectionProbes::nearest($eye);
        if ($chosen === $this->bound) {
            return;
        }
        $this->bound = $chosen;
        foreach (ReflectionProbes::commandsFor($eye) as $command) {
            $this->commandList->add($command);
        }
    }
}
