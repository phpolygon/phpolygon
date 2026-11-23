<?php

declare(strict_types=1);

namespace PHPolygon\Camera;

use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * A camera placement: position plus yaw and pitch in radians. Immutable.
 *
 * Convention (the same as `Quaternion::fromEuler($pitch, $yaw, 0)` and a
 * camera that looks down -Z in a right-handed, Y-up world):
 *   yaw   0 looks along -Z; positive yaw turns counter-clockwise seen from above
 *   pitch 0 is level; positive pitch looks up
 *   forward = (-sin(yaw) * cos(pitch), sin(pitch), -cos(yaw) * cos(pitch))
 *
 * Yaw is NOT wrapped: a flight may carry it past 2 PI so consecutive frames
 * never jump. Compare angles with `equivalentTo()`, which is wrap-aware.
 */
final class CameraPose
{
    public readonly Vec3 $position;

    public function __construct(
        Vec3 $position,
        public readonly float $yaw,
        public readonly float $pitch,
    ) {
        $this->position = clone $position;
    }

    /** Pose at `$position` looking at `$target`. A target on the position keeps yaw 0 / pitch 0. */
    public static function lookingAt(Vec3 $position, Vec3 $target): self
    {
        [$yaw, $pitch] = self::anglesFor($target->sub($position));
        return new self($position, $yaw, $pitch);
    }

    /**
     * Yaw and pitch for a view direction (need not be normalised).
     * A zero vector gives [0, 0]; straight up/down gives yaw 0.
     *
     * @return array{0: float, 1: float} [yaw, pitch] in radians, yaw in (-PI, PI]
     */
    public static function anglesFor(Vec3 $direction): array
    {
        $len = $direction->length();
        if ($len < 1e-12) {
            return [0.0, 0.0];
        }
        $yaw = ($direction->x * $direction->x + $direction->z * $direction->z) < 1e-24
            ? 0.0
            : atan2(-$direction->x, -$direction->z);
        $pitch = asin(max(-1.0, min(1.0, $direction->y / $len)));
        return [$yaw, $pitch];
    }

    /** Wrap an angle into (-PI, PI]. */
    public static function wrapAngle(float $radians): float
    {
        $twoPi = 2.0 * M_PI;
        $a = fmod($radians, $twoPi);
        if ($a > M_PI) {
            $a -= $twoPi;
        } elseif ($a <= -M_PI) {
            $a += $twoPi;
        }
        return $a;
    }

    /** Unit view direction. */
    public function forward(): Vec3
    {
        $cp = cos($this->pitch);
        return new Vec3(-sin($this->yaw) * $cp, sin($this->pitch), -cos($this->yaw) * $cp);
    }

    /** Camera rotation (YXZ Euler, roll 0); rotating (0, 0, -1) by it gives `forward()`. */
    public function toQuaternion(): Quaternion
    {
        return Quaternion::fromEuler($this->pitch, $this->yaw, 0.0);
    }

    /**
     * Blend `$a` to `$b`. Yaw takes the short way round. `$t` <= 0 returns `$a`
     * and `$t` >= 1 returns `$b` exactly (no float residue).
     */
    public static function lerp(self $a, self $b, float $t): self
    {
        if ($t <= 0.0) {
            return $a;
        }
        if ($t >= 1.0) {
            return $b;
        }
        return new self(
            $a->position->lerp($b->position, $t),
            $a->yaw + self::wrapAngle($b->yaw - $a->yaw) * $t,
            $a->pitch + ($b->pitch - $a->pitch) * $t,
        );
    }

    /** Same pose within `$epsilon`; yaw is compared modulo 2 PI. */
    public function equivalentTo(self $other, float $epsilon = 1e-6): bool
    {
        return $this->position->equals($other->position, $epsilon)
            && abs(self::wrapAngle($this->yaw - $other->yaw)) < $epsilon
            && abs($this->pitch - $other->pitch) < $epsilon;
    }

    /** A copy with yaw shifted by `$radians` (used to keep a flight's yaw continuous with its origin). */
    public function withYawOffset(float $radians): self
    {
        return new self($this->position, $this->yaw + $radians, $this->pitch);
    }
}
