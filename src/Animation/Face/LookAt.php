<?php

declare(strict_types=1);

namespace PHPolygon\Animation\Face;

use PHPolygon\Animation\QuatMath;

/**
 * Look-at building block for eyes or a head: yaw and pitch from a joint to a target in
 * the joint's own frame, clamped to limits. Frame convention, the same as the character
 * builders' root space (netrunner BodyModel): +Y up, +Z forward, +X = the character's
 * LEFT; yaw is positive toward +X (the character's left), pitch positive up. Returned as
 * the angles and as a quaternion ready for a {@see \PHPolygon\Animation\Pose} rotation (pitch about X,
 * yaw about Y, the Euler order of Pose::degrees()).
 */
final class LookAt
{
    /**
     * @param array{0: float, 1: float, 2: float} $local target position relative to the joint, in the joint's frame
     * @return array{yaw: float, pitch: float} radians; yaw positive toward +X, pitch positive up
     */
    public static function angles(array $local, float $maxYaw, float $maxPitch): array
    {
        [$x, $y, $z] = $local;
        if ($x * $x + $y * $y + $z * $z < 1e-12) {
            return ['yaw' => 0.0, 'pitch' => 0.0];
        }
        $yaw = atan2($x, $z);
        $pitch = atan2($y, sqrt($x * $x + $z * $z));

        return [
            'yaw' => max(-$maxYaw, min($maxYaw, $yaw)),
            'pitch' => max(-$maxPitch, min($maxPitch, $pitch)),
        ];
    }

    /**
     * The rotation for the clamped angles.
     *
     * @param array{0: float, 1: float, 2: float} $local
     * @return array{0: float, 1: float, 2: float, 3: float} x, y, z, w
     */
    public static function rotation(array $local, float $maxYaw, float $maxPitch): array
    {
        $a = self::angles($local, $maxYaw, $maxPitch);
        // Pitch up (positive) turns +Z toward +Y: a rotation about X by -pitch.
        $qx = [sin(-$a['pitch'] / 2.0), 0.0, 0.0, cos($a['pitch'] / 2.0)];
        $qy = [0.0, sin($a['yaw'] / 2.0), 0.0, cos($a['yaw'] / 2.0)];

        return QuatMath::mul($qy, $qx);
    }
}
