<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Animation\Pose;
use PHPolygon\Animation\PoseLibrary;
use PHPolygon\Component\PosePlayer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Motion\MotionTime;
use PHPolygon\Motion\MotionView;
use PHPolygon\Rendering\RenderCommandList;

/**
 * Advances every {@see PosePlayer}, crossfades, solves its rig and writes the bound
 * parts' local transforms. Deterministic: the same steps give the same poses.
 *
 * Register it AFTER whatever moves the figures' roots and sets clips and speeds
 * (navigation, the game's state machine) and BEFORE Transform3DSystem. With a command
 * list it remembers the camera of each rendered frame (out-of-view freeze): register it
 * after Camera3DSystem and before Renderer3DSystem then, as with
 * {@see SignalMotionSystem}. Dormant figures are not iterated.
 */
class PosePlayerSystem extends AbstractSystem
{
    public float $timeScale = 1.0;

    private MotionView $view;

    public function __construct(private readonly ?RenderCommandList $commandList = null)
    {
        $this->view = new MotionView();
    }

    public function view(): MotionView
    {
        return $this->view;
    }

    public function render(World $world): void
    {
        if ($this->commandList !== null) {
            $this->view->capture($this->commandList);
        }
    }

    public function onWorldClear(World $world): void
    {
        $this->view->clear();
    }

    public function update(World $world, float $dt): void
    {
        $dt = max(0.0, $dt * $this->timeScale);
        foreach ($world->componentPool(PosePlayer::class) as $id => $player) {
            /** @var PosePlayer $player */
            $this->updatePlayer($world, $id, $player, $dt);
        }
    }

    private function updatePlayer(World $world, int $id, PosePlayer $player, float $dt): void
    {
        $rig = PoseLibrary::rig($player->rig);
        $clip = PoseLibrary::clip($player->clip);
        if ($rig === null || $clip === null) {
            return;
        }
        $root = $world->tryGetComponent($id, Transform3D::class);
        if ($player->cullRadius > 0.0 && $root instanceof Transform3D
            && !$this->view->sees($root->getWorldPosition(), $player->cullRadius, $player->maxDistance)) {
            return;
        }

        $step = $dt * MotionTime::scale($player->effect);
        $clipStep = $step * $player->speed;
        $before = $player->time;
        $player->time += $clipStep;

        // A completed cycle (or a one-shot that ran out) picks the next idle variant.
        if ($clip->duration > 0.0) {
            $ended = $clip->loop
                ? floor($player->time / $clip->duration) > floor($before / $clip->duration)
                : $player->time >= $clip->duration;
            if (!$clip->loop && $ended) {
                $player->finished = true;
            }
            if ($ended && $player->idle && $player->idleClips !== []) {
                $player->idleCycles++;
                $next = $player->idleVariant();
                if ($next !== $player->clip || !$clip->loop) {
                    $player->start($next, $player->idleBlend);
                    $clip = PoseLibrary::clip($player->clip) ?? $clip;
                }
            }
        }

        $previous = $player->previousClip !== '' ? PoseLibrary::clip($player->previousClip) : null;
        $weight = 1.0;
        if ($previous !== null) {
            $player->previousTime += $clipStep;
            $player->blendElapsed += $step;
            $weight = $player->blendDuration > 0.0 ? MotionTime::ease($player->blendElapsed / $player->blendDuration) : 1.0;
        }
        if ($previous === null || $weight >= 1.0) {
            $player->previousClip = '';
            $previous = null;
        }

        // Nothing changed since the parts were posed: time stood still or a one-shot holds its end.
        if ($player->posed && $previous === null && ($step <= 0.0 || $player->finished && $before >= $clip->duration)) {
            return;
        }

        $pose = $clip->sampleRaw($player->time);
        if ($previous !== null) {
            $pose = Pose::blendRaw($previous->sampleRaw($player->previousTime), $pose, $weight);
        }
        $joints = $rig->solveRaw($pose);
        foreach ($player->bindings as $joint => $entityId) {
            $j = $joints[$joint] ?? null;
            if ($j === null) {
                continue;
            }
            $part = $world->tryGetComponent($entityId, Transform3D::class);
            if ($part instanceof Transform3D) {
                $part->position = new Vec3($j[0], $j[1], $j[2]);
                $part->rotation = new Quaternion($j[3], $j[4], $j[5], $j[6]);
            }
        }
        $player->posed = true;
    }
}
