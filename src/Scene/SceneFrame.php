<?php

declare(strict_types=1);

namespace PHPolygon\Scene;

use PHPolygon\Component\Transform3D;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Places a group of scene declarations in a turned frame: build the group as if its
 * origin were (0, 0, 0) and its axes the world's, then move every top-level entity
 * declared since {@see mark()} to `origin + yaw · position` and turn its own rotation
 * by the yaw.
 *
 * Why not a parent transform: a box collider collides as an oriented box only when the
 * rotation is its OWN (see Physics3DSystem); turned through a parent it would collide as
 * its world AABB, metres deeper than the wall. Here every entity carries the turn itself.
 * Children of a declaration keep their local transform (they follow their parent).
 *
 * Positions a prefab hands back (sockets, spawn points) go through {@see point()} and
 * {@see yaw()} with the same frame.
 */
final class SceneFrame
{
    private readonly Quaternion $turn;

    public function __construct(
        public readonly Vec3 $origin,
        /** Turn about +Y in radians (right-handed: +X turns toward -Z). */
        public readonly float $yaw = 0.0,
    ) {
        $this->turn = Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), $yaw);
    }

    /** How many declarations the builder holds now: pass it to {@see place()}. */
    public static function mark(SceneBuilder $builder): int
    {
        return count($builder->getDeclarations());
    }

    /** Moves and turns every top-level declaration added after $mark. Returns how many. */
    public function place(SceneBuilder $builder, int $mark): int
    {
        $count = 0;
        foreach (array_slice($builder->getDeclarations(), $mark) as $declaration) {
            foreach ($declaration->getComponents() as $component) {
                if ($component instanceof Transform3D) {
                    $component->position = $this->point($component->position);
                    $component->rotation = $this->turn->multiply($component->rotation)->normalize();
                    $component->worldMatrix = $component->getLocalMatrix();
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /** A point of the frame in world space. */
    public function point(Vec3 $local): Vec3
    {
        return $this->origin->add($this->turn->rotateVec3($local));
    }

    /** A world point in the frame's own space (the inverse of {@see point()}). */
    public function toLocal(Vec3 $world): Vec3
    {
        return $this->turn->conjugate()->rotateVec3($world->sub($this->origin));
    }

    /** A direction of the frame in world space (no translation). */
    public function direction(Vec3 $local): Vec3
    {
        return $this->turn->rotateVec3($local);
    }

    /**
     * A yaw angle of the frame in world space. The angle is measured the way
     * Quaternion::fromEuler(0, yaw, 0) turns, so frame and object turns add up.
     */
    public function yaw(float $localYaw): float
    {
        return $localYaw + $this->yaw;
    }
}
