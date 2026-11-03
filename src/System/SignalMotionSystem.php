<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\SignalSpin;
use PHPolygon\Component\SignalSwitch;
use PHPolygon\Component\SignalTrack;
use PHPolygon\Component\Signals;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Motion\MotionTime;
use PHPolygon\Motion\MotionView;
use PHPolygon\Motion\TrackEnd;
use PHPolygon\Rendering\RenderCommandList;

/**
 * Drives the signal motions: advances the sequences of every {@see Signals}, then moves
 * every {@see SignalTrack}, turns every {@see SignalSpin} and shows or hides every
 * {@see SignalSwitch}. Pure arithmetic on the update step - the same steps give the
 * same poses, with or without a GPU.
 *
 * Register it BEFORE Transform3DSystem (the written local transforms propagate in the
 * same frame). With a command list it remembers the camera of each rendered frame in
 * its render phase, for the out-of-view freeze of the next update: register it after
 * Camera3DSystem and before Renderer3DSystem then. Without one nothing ever freezes
 * unless the game sets {@see view()} itself.
 *
 * {@see $timeScale} scales every step (a game's pause or slow motion); the per-motion
 * reduced-motion scale comes on top. Sequences follow only {@see $timeScale}.
 * Dormant entities are not iterated (component pools skip them), so a motion in a
 * sleeping region stands still and goes on where it stopped.
 */
class SignalMotionSystem extends AbstractSystem
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

        /** @var array<int, Signals> $signals */
        $signals = $world->componentPool(Signals::class);
        foreach ($signals as $s) {
            if ($s->sequences !== []) {
                $s->advance($dt);
            }
        }

        foreach ($world->componentPool(SignalTrack::class) as $id => $track) {
            /** @var SignalTrack $track */
            $this->updateTrack($world, $id, $track, $signals[$track->source ?? $id] ?? null, $dt);
        }
        foreach ($world->componentPool(SignalSpin::class) as $id => $spin) {
            /** @var SignalSpin $spin */
            $this->updateSpin($world, $id, $spin, $signals[$spin->source ?? $id] ?? null, $dt);
        }
        foreach ($world->componentPool(SignalSwitch::class) as $id => $switch) {
            /** @var SignalSwitch $switch */
            $this->updateSwitch($world, $id, $switch, $signals[$switch->source ?? $id] ?? null, $dt);
        }
    }

    /**
     * Show or hide an entity's MeshRenderer and those of all its descendants.
     */
    public static function setVisible(World $world, int $entityId, bool $visible): void
    {
        $mesh = $world->tryGetComponent($entityId, MeshRenderer::class);
        if ($mesh instanceof MeshRenderer) {
            $mesh->visible = $visible;
        }
        $transform = $world->tryGetComponent($entityId, Transform3D::class);
        if ($transform instanceof Transform3D) {
            foreach ($transform->childEntityIds as $child) {
                self::setVisible($world, $child, $visible);
            }
        }
    }

    private static function running(?Signals $signals, string $run, string $halt): bool
    {
        $on = $run === '' || ($signals !== null && $signals->get($run));
        return $on && !($halt !== '' && $signals !== null && $signals->get($halt));
    }

    private function seen(World $world, int $id, float $radius, float $maxDistance): bool
    {
        if ($radius <= 0.0) {
            return true;
        }
        $transform = $world->tryGetComponent($id, Transform3D::class);
        $center = $transform instanceof Transform3D ? $transform->getWorldPosition() : Vec3::zero();
        return $this->view->sees($center, $radius, $maxDistance);
    }

    private function updateTrack(World $world, int $id, SignalTrack $track, ?Signals $signals, float $dt): void
    {
        if (!$track->initialized) {
            foreach ($track->items as $item) {
                self::setVisible($world, $item, isset($track->positions[$item]));
            }
            $track->initialized = true;
        }

        if (!$this->seen($world, $id, $track->cullRadius, $track->maxDistance)) {
            $track->frozen = true;
            return;
        }
        $track->frozen = false;

        $step = $dt * MotionTime::scale($track->effect);
        $ramp = MotionTime::reduced() ? max(0.0, $track->reducedRampScale) : 1.0;
        $on = self::running($signals, $track->runChannel, $track->haltChannel);
        $track->drive = MotionTime::drive($track->drive, $on, $track->rampUp * $ramp, $track->rampDown * $ramp, $step);
        $factor = $track->speedChannel !== '' && $signals !== null ? $signals->number($track->speedChannel, 1.0) : 1.0;
        $move = $track->speed * MotionTime::ease($track->drive) * $factor * $step;
        $track->travel += $move;

        $length = $track->length();
        $changed = [];
        $arrivals = 0;
        $front = null;
        foreach ($track->activeItems() as $item) {
            $pos = $track->positions[$item];
            $limit = $front === null
                ? ($track->end === TrackEnd::Queue ? $length : INF)
                : $front - $track->spacing;
            $new = max($pos, min($pos + $move, $limit));
            if ($track->end === TrackEnd::Recycle && $new >= $length) {
                unset($track->positions[$item], $track->values[$item], $track->arrived[$item]);
                self::setVisible($world, $item, false);
                $arrivals++;
                continue;
            }
            if ($track->end === TrackEnd::Queue && $new >= $length - 1e-9 && !isset($track->arrived[$item])) {
                $track->arrived[$item] = true;
                $arrivals++;
            }
            if ($new !== $pos) {
                $track->positions[$item] = $new;
                $changed[$item] = true;
            }
            $front = $new;
        }

        if ($signals !== null) {
            if ($arrivals > 0 && $track->arrivedChannel !== '') {
                $signals->setNumber($track->arrivedChannel, $signals->number($track->arrivedChannel) + $arrivals);
            }
            $full = false;
            if ($signals->pendingPulses($track->spawnChannel) > 0) {
                $free = null;
                foreach ($track->items as $item) {
                    if (!isset($track->positions[$item])) {
                        $free = $item;
                        break;
                    }
                }
                $clear = $track->positions === [] || min($track->positions) >= $track->spacing;
                if ($free === null) {
                    $full = true;
                } elseif ($clear) {
                    $track->values[$free] = (float) $signals->takePulse($track->spawnChannel);
                    $track->positions[$free] = 0.0;
                    unset($track->arrived[$free]);
                    self::setVisible($world, $free, true);
                    $changed[$free] = true;
                }
            }
            if ($track->fullChannel !== '') {
                $signals->set($track->fullChannel, $full);
            }
        }

        foreach ($changed as $item => $_) {
            $transform = $world->tryGetComponent($item, Transform3D::class);
            if (!$transform instanceof Transform3D) {
                continue;
            }
            [$point, $dir] = $track->sample($track->positions[$item]);
            $transform->position = $point;
            if ($track->alignToPath) {
                $transform->rotation = Quaternion::fromEuler(0.0, atan2($dir->x, $dir->z), 0.0);
            }
        }
    }

    private function updateSpin(World $world, int $id, SignalSpin $spin, ?Signals $signals, float $dt): void
    {
        if (!$this->seen($world, $id, $spin->cullRadius, $spin->maxDistance)) {
            return;
        }
        $angle = $spin->angle;
        if ($spin->followTrack !== null) {
            $track = $world->tryGetComponent($spin->followTrack, SignalTrack::class);
            if ($track instanceof SignalTrack && $spin->radius > 0.0) {
                $angle = fmod($track->travel / $spin->radius, 2.0 * M_PI);
            }
        } else {
            $step = $dt * MotionTime::scale($spin->effect);
            $ramp = MotionTime::reduced() ? max(0.0, $spin->reducedRampScale) : 1.0;
            $on = self::running($signals, $spin->runChannel, $spin->haltChannel);
            $spin->drive = MotionTime::drive($spin->drive, $on, $spin->rampUp * $ramp, $spin->rampDown * $ramp, $step);
            $factor = $spin->speedChannel !== '' && $signals !== null ? $signals->number($spin->speedChannel, 1.0) : 1.0;
            $angle = fmod($angle + $spin->speed * MotionTime::ease($spin->drive) * $factor * $step, 2.0 * M_PI);
        }

        $transform = $world->tryGetComponent($id, Transform3D::class);
        if (!$transform instanceof Transform3D) {
            $spin->angle = $angle;
            return;
        }
        $first = $spin->rest === null;
        $spin->rest ??= $transform->rotation;
        if ($first || $angle !== $spin->angle) {
            $spin->angle = $angle;
            $transform->rotation = $spin->rest->multiply(Quaternion::fromAxisAngle($spin->axis, $angle));
        }
    }

    private function updateSwitch(World $world, int $id, SignalSwitch $switch, ?Signals $signals, float $dt): void
    {
        $on = $switch->channel === '' || ($signals !== null && $signals->get($switch->channel));
        if ($switch->invert) {
            $on = !$on;
        }
        $show = $on;
        if ($on && $switch->blinkHz > 0.0) {
            $scale = MotionTime::scale($switch->effect);
            $hz = $switch->blinkHz;
            if ($switch->maxBlinkHzReduced > 0.0 && MotionTime::reduced()) {
                $hz = min($hz, $switch->maxBlinkHzReduced);
            }
            if ($scale > 0.0) {
                $switch->phase = fmod($switch->phase + $dt * $scale * $hz, 1.0);
                $show = $switch->phase < $switch->duty;
            }
        }
        if ($show !== $switch->shown) {
            $switch->shown = $show;
            self::setVisible($world, $id, $show);
        }
    }
}
