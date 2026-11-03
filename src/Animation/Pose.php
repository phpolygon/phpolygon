<?php

declare(strict_types=1);

namespace PHPolygon\Animation;

use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * One key pose of a figure: a rotation per joint, relative to the joint's rest
 * orientation in its {@see PoseRig}, plus an offset of the root joints (a pelvis bob,
 * sitting down). A joint the pose does not name stays at rest. Immutable.
 *
 * The per-frame path works on plain arrays ({@see raw()}, {@see blendRaw()}), the
 * object methods are for authoring and tests; both give the same numbers.
 *
 * @phpstan-type RawQuat array{0: float, 1: float, 2: float, 3: float}
 * @phpstan-type RawPose array{0: array<string, RawQuat>, 1: array{0: float, 1: float, 2: float}|null}
 */
final class Pose
{
    /** @var RawPose|null */
    private ?array $raw = null;

    /**
     * @param array<string, Quaternion> $rotations joint name => rotation relative to rest
     */
    public function __construct(
        public readonly array $rotations = [],
        public readonly ?Vec3 $rootOffset = null,
    ) {
    }

    /**
     * Authoring shorthand: angles in degrees per joint as [x, y, z], combined like
     * {@see Quaternion::fromEuler()} (pitch about X, yaw about Y, roll about Z; Y·X·Z).
     *
     * @param array<string, array{0: float|int, 1?: float|int, 2?: float|int}> $degrees
     */
    public static function degrees(array $degrees, ?Vec3 $rootOffset = null): self
    {
        $rotations = [];
        foreach ($degrees as $joint => $angles) {
            $rotations[$joint] = Quaternion::fromEuler(
                deg2rad((float) $angles[0]),
                deg2rad((float) ($angles[1] ?? 0.0)),
                deg2rad((float) ($angles[2] ?? 0.0)),
            );
        }
        return new self($rotations, $rootOffset);
    }

    /** @param RawPose $raw */
    public static function fromRaw(array $raw): self
    {
        $rotations = [];
        foreach ($raw[0] as $joint => $q) {
            $rotations[$joint] = new Quaternion($q[0], $q[1], $q[2], $q[3]);
        }
        $root = $raw[1];
        return new self($rotations, $root === null ? null : new Vec3($root[0], $root[1], $root[2]));
    }

    public function rotation(string $joint): Quaternion
    {
        return $this->rotations[$joint] ?? Quaternion::identity();
    }

    /** @return RawPose */
    public function raw(): array
    {
        if ($this->raw === null) {
            $rotations = [];
            foreach ($this->rotations as $joint => $q) {
                $rotations[$joint] = [$q->x, $q->y, $q->z, $q->w];
            }
            $root = $this->rootOffset;
            $this->raw = [$rotations, $root === null ? null : [$root->x, $root->y, $root->z]];
        }
        return $this->raw;
    }

    /**
     * $a at $t = 0, $b at $t = 1: rotations slerped per joint (a joint only one of them
     * names blends against rest), root offsets lerped.
     */
    public static function blend(self $a, self $b, float $t): self
    {
        if ($t <= 0.0) {
            return $a;
        }
        if ($t >= 1.0) {
            return $b;
        }
        return self::fromRaw(self::blendRaw($a->raw(), $b->raw(), $t));
    }

    /**
     * {@see blend()} on raw poses.
     *
     * @param RawPose $a
     * @param RawPose $b
     * @return RawPose
     */
    public static function blendRaw(array $a, array $b, float $t): array
    {
        if ($t <= 0.0) {
            return $a;
        }
        if ($t >= 1.0) {
            return $b;
        }
        $identity = [0.0, 0.0, 0.0, 1.0];
        $rotations = [];
        foreach ($a[0] + $b[0] as $joint => $_) {
            $rotations[$joint] = QuatMath::slerp($a[0][$joint] ?? $identity, $b[0][$joint] ?? $identity, $t);
        }
        $root = null;
        if ($a[1] !== null || $b[1] !== null) {
            [$ax, $ay, $az] = $a[1] ?? [0.0, 0.0, 0.0];
            [$bx, $by, $bz] = $b[1] ?? [0.0, 0.0, 0.0];
            $root = [$ax + ($bx - $ax) * $t, $ay + ($by - $ay) * $t, $az + ($bz - $az) * $t];
        }
        return [$rotations, $root];
    }
}
