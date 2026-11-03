<?php

declare(strict_types=1);

namespace PHPolygon\Animation;

use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * The joint hierarchy a {@see PoseClip} is played on. Each joint has a parent (null =
 * attached to the figure's root), an offset from the parent joint in the parent's
 * frame at rest, and a rest orientation. Forward kinematics, per joint in the order
 * they were added:
 *
 *     rotation = parent rotation · rest · pose rotation
 *     position = parent position + parent rotation · offset   (root joints: offset + pose root offset)
 *
 * Both are in the figure root's space - exactly the local transform of a part entity
 * whose parent is the root (the flat part layout procedural figures use), with the
 * part's mesh origin at its joint. The engine knows no joint names; a game describes
 * its figure here.
 */
final class PoseRig
{
    /**
     * In FK order: parent, offset x/y/z, rest quaternion.
     *
     * @var array<string, array{0: ?string, 1: float, 2: float, 3: float, 4: array{0: float, 1: float, 2: float, 3: float}}>
     */
    private array $joints = [];

    /**
     * Add a joint; a parent must have been added before its children.
     *
     * @return $this
     */
    public function joint(string $name, ?string $parent, Vec3 $offset, ?Quaternion $rest = null): self
    {
        if (isset($this->joints[$name])) {
            throw new \InvalidArgumentException("Joint '{$name}' is defined twice");
        }
        if ($parent !== null && !isset($this->joints[$parent])) {
            throw new \InvalidArgumentException("Parent '{$parent}' of joint '{$name}' must be added first");
        }
        $rest ??= Quaternion::identity();
        $this->joints[$name] = [$parent, $offset->x, $offset->y, $offset->z, [$rest->x, $rest->y, $rest->z, $rest->w]];
        return $this;
    }

    /** @return list<string> */
    public function jointNames(): array
    {
        return array_keys($this->joints);
    }

    public function has(string $joint): bool
    {
        return isset($this->joints[$joint]);
    }

    /**
     * Root-space position and rotation of every joint for a pose.
     *
     * @return array<string, array{0: Vec3, 1: Quaternion}>
     */
    public function solve(Pose $pose): array
    {
        $out = [];
        foreach ($this->solveRaw($pose->raw()) as $name => $j) {
            $out[$name] = [new Vec3($j[0], $j[1], $j[2]), new Quaternion($j[3], $j[4], $j[5], $j[6])];
        }
        return $out;
    }

    /**
     * {@see solve()} on a raw pose: per joint [px, py, pz, qx, qy, qz, qw].
     *
     * @param array{0: array<string, array{0: float, 1: float, 2: float, 3: float}>, 1: array{0: float, 1: float, 2: float}|null} $pose
     * @return array<string, array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}>
     */
    public function solveRaw(array $pose): array
    {
        $out = [];
        [$rotations, $root] = $pose;
        [$rx, $ry, $rz] = $root ?? [0.0, 0.0, 0.0];
        foreach ($this->joints as $name => [$parent, $ox, $oy, $oz, $rest]) {
            $local = isset($rotations[$name]) ? QuatMath::mul($rest, $rotations[$name]) : $rest;
            $p = $parent === null ? null : ($out[$parent] ?? null);
            if ($p === null) {
                $out[$name] = [$ox + $rx, $oy + $ry, $oz + $rz, $local[0], $local[1], $local[2], $local[3]];
                continue;
            }
            $parentRot = [$p[3], $p[4], $p[5], $p[6]];
            [$dx, $dy, $dz] = QuatMath::rotate($parentRot, $ox, $oy, $oz);
            $q = QuatMath::mul($parentRot, $local);
            $out[$name] = [$p[0] + $dx, $p[1] + $dy, $p[2] + $dz, $q[0], $q[1], $q[2], $q[3]];
        }
        return $out;
    }
}
