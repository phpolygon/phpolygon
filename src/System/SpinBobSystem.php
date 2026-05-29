<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\SpinBob;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Idle decoration animation: a constant per-axis spin plus a vertical bob,
 * for every {@see SpinBob} entity (coins, the goal star, hopping enemies …).
 *
 * The bob owns the Y axis (oscillating around the captured rest height), so it
 * composes with {@see PatrolSystem} moving the same entity on X/Z.
 */
class SpinBobSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        foreach ($world->query(SpinBob::class, Transform3D::class) as $entity) {
            $sb = $entity->get(SpinBob::class);
            $tf = $entity->get(Transform3D::class);

            if ($sb->baseY === null) {
                $sb->baseY = $tf->position->y;
            }
            $sb->elapsed += 1.0;

            // Spin: compose the per-tick delta onto the current rotation.
            $ss = $sb->spinSpeed;
            if ($ss->x !== 0.0 || $ss->y !== 0.0 || $ss->z !== 0.0) {
                $delta = Quaternion::fromEuler($ss->x, $ss->y, $ss->z);
                $tf->rotation = $tf->rotation->multiply($delta)->normalize();
            }

            // Bob: amplitude * sin (or |sin|) around the rest height.
            if ($sb->bobAmplitude !== 0.0) {
                $s = sin($sb->elapsed * $sb->bobFrequency + $sb->phaseOffset);
                if ($sb->bobAbsolute) { $s = abs($s); }
                $pos = $tf->position;
                $tf->position = new Vec3($pos->x, $sb->baseY + $sb->bobAmplitude * $s, $pos->z);
            }
        }
    }
}
