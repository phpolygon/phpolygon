<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * A travelling wave through a mesh, in the vertex shader ({@see Material::$bodyWave}):
 * along one local axis from an anchor, the vertices move sideways by
 * `amplitude · s² · sin(2π(s·|extent|/wavelength − speed·t) + phase)`, s = 0 at the
 * anchor and 1 at `anchor + extent`. The anchor stays put, the free end swings most.
 * Each object or instance gets its own phase from its position, so a school or a
 * kelp bed does not move in lockstep. Reduced motion stops it with the foliage wind.
 *
 * - A fish swimming: axis = its length, anchor = the head, lateral = sideways.
 * - Kelp or a reed: axis = up, anchor = the root, lateral = {@see LATERAL_CIRCLE}.
 *
 * Positions are local (mesh) units; the normals are not bent (small amplitudes).
 */
final readonly class BodyWave
{
    public const int AXIS_X = 0;
    public const int AXIS_Y = 1;
    public const int AXIS_Z = 2;

    /** Lateral offset on x and, a quarter period later, on z: a slow circling sway. */
    public const int LATERAL_CIRCLE = 3;

    public function __construct(
        /** Sideways offset at the free end, local units; 0 = off. */
        public float $amplitude,
        /** Length of one wave along the axis, local units. */
        public float $wavelength,
        /** Waves per second travelling from the anchor to the free end. */
        public float $speed,
        public int $axis = self::AXIS_Z,
        /** Local coordinate of the anchor on the axis. */
        public float $anchor = 0.0,
        /** Signed distance from the anchor to the free end. */
        public float $extent = 1.0,
        public int $lateral = self::AXIS_X,
    ) {
        if (!in_array($axis, [self::AXIS_X, self::AXIS_Y, self::AXIS_Z], true)) {
            throw new \InvalidArgumentException("BodyWave: axis {$axis} is not 0, 1 or 2");
        }
        if (!in_array($lateral, [self::AXIS_X, self::AXIS_Y, self::AXIS_Z, self::LATERAL_CIRCLE], true) || $lateral === $axis) {
            throw new \InvalidArgumentException("BodyWave: lateral {$lateral} must be another axis or LATERAL_CIRCLE");
        }
        if ($extent == 0.0 || $wavelength <= 0.0) {
            throw new \InvalidArgumentException('BodyWave: extent must not be 0 and wavelength must be positive');
        }
    }

    /** A fish of length 1 along local z, nose at $nose (−z forward), wagging on x. */
    public static function swim(float $amplitude = 0.08, float $wavelength = 1.0, float $speed = 1.5, float $nose = -0.5, float $length = 1.0): self
    {
        return new self($amplitude, $wavelength, $speed, self::AXIS_Z, $nose, $length, self::AXIS_X);
    }

    /** A plant standing on y = $root, $height tall, circling slowly. */
    public static function sway(float $amplitude = 0.25, float $height = 2.0, float $speed = 0.25, float $root = 0.0): self
    {
        return new self($amplitude, $height * 2.0, $speed, self::AXIS_Y, $root, $height, self::LATERAL_CIRCLE);
    }

    /**
     * The uniform values: u_body_wave = (amplitude, wavelength, speed, 0),
     * u_body_wave_axis = (axis, anchor, extent, lateral). Off = all zero.
     *
     * @return array{u_body_wave: list<float>, u_body_wave_axis: list<float>}
     */
    public static function uniforms(?self $wave): array
    {
        if ($wave === null) {
            return ['u_body_wave' => [0.0, 1.0, 0.0, 0.0], 'u_body_wave_axis' => [2.0, 0.0, 1.0, 0.0]];
        }

        return [
            'u_body_wave' => [$wave->amplitude, $wave->wavelength, $wave->speed, 0.0],
            'u_body_wave_axis' => [(float) $wave->axis, $wave->anchor, $wave->extent, (float) $wave->lateral],
        ];
    }
}
