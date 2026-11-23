<?php

declare(strict_types=1);

namespace PHPolygon\Camera;

use InvalidArgumentException;
use PHPolygon\Math\Vec3;

/**
 * A cinematic camera path: waypoints joined by a centripetal Catmull-Rom spline,
 * flown at constant speed (arc-length parameterised) and warped by an easing.
 * Pure and immutable: `evaluate($t)` is a function of `$t` alone, so any frame
 * rate gives the same pose at the same time.
 *
 * - The path passes through every waypoint and is C1 (centripetal parameterisation:
 *   no loops or cusps, little overshoot).
 * - The view looks at one fixed point, or at per-waypoint look points that are
 *   spline-interpolated with the same segment mapping (look point i is hit exactly
 *   at waypoint i).
 * - Yaw is unwrapped: it never jumps by 2 PI between nearby `$t`, even when the
 *   flight circles the target more than once.
 *
 * `CameraFlightPlayer` runs one flight in time and handles blending from/to the
 * camera the flight started from.
 */
final class CameraFlight
{
    private const TABLE_PER_SEGMENT = 48;
    private const YAW_SAMPLES = 512;
    private const MIN_WAYPOINT_DISTANCE = 1e-6;

    /** @var list<Vec3> */
    private array $waypoints;
    /** @var list<Vec3>|null */
    private ?array $lookPoints = null;
    private ?Vec3 $fixedLook = null;
    /** @var list<float> cumulative arc length at table node k (segment-major, node 0 of each segment shared) */
    private array $tableLength = [];
    private float $totalLength = 0.0;
    /** @var list<float> unwrapped yaw at path fractions k / YAW_SAMPLES */
    private array $yawTable = [];

    /**
     * @param list<Vec3>           $waypoints at least two, consecutive points at least 1e-6 apart
     * @param Vec3|list<Vec3>      $look      one fixed target, or one look point per waypoint
     * @param float                $duration  seconds, > 0
     */
    public function __construct(
        array $waypoints,
        Vec3|array $look,
        private readonly float $duration,
        private readonly CameraEasing $easing = CameraEasing::EaseInOut,
    ) {
        $count = count($waypoints);
        if ($count < 2) {
            throw new InvalidArgumentException("CameraFlight needs at least 2 waypoints, got {$count}.");
        }
        if (!is_finite($duration) || $duration <= 0.0) {
            throw new InvalidArgumentException("CameraFlight duration must be a finite number > 0, got {$duration}.");
        }
        $pts = [];
        foreach ($waypoints as $i => $p) {
            if (!is_finite($p->x) || !is_finite($p->y) || !is_finite($p->z)) {
                throw new InvalidArgumentException("CameraFlight waypoint {$i} is not finite.");
            }
            if ($i > 0 && $p->sub($pts[$i - 1])->length() < self::MIN_WAYPOINT_DISTANCE) {
                throw new InvalidArgumentException(
                    'CameraFlight waypoints ' . ($i - 1) . " and {$i} coincide; consecutive waypoints must be distinct.",
                );
            }
            $pts[] = clone $p;
        }
        $this->waypoints = $pts;

        if ($look instanceof Vec3) {
            $this->fixedLook = clone $look;
        } else {
            if (count($look) !== $count) {
                throw new InvalidArgumentException(
                    'CameraFlight needs one look point per waypoint (' . $count . '), got ' . count($look) . '.',
                );
            }
            $this->lookPoints = array_map(static fn(Vec3 $v): Vec3 => clone $v, $look);
        }

        $this->buildArcLengthTable();
        $this->buildYawTable();
    }

    /**
     * Circular arc around `$centre` at `$height` above it. Angle 0 is on the +Z side
     * of the centre, positive angles move towards +X. Waypoints sit at most 15
     * degrees apart, which keeps interior segments within 0.02% of the radius; the
     * first and last segment, whose end tangents are extrapolated, dip by up to 0.5% (measured).
     * `$lookAt` defaults to the centre.
     */
    public static function orbitArc(
        Vec3 $centre,
        float $radius,
        float $height,
        float $startAngle,
        float $sweepAngle,
        float $duration,
        ?Vec3 $lookAt = null,
        CameraEasing $easing = CameraEasing::EaseInOut,
    ): self {
        if (!is_finite($radius) || $radius <= 0.0) {
            throw new InvalidArgumentException("orbitArc radius must be > 0, got {$radius}.");
        }
        if (!is_finite($sweepAngle) || abs($sweepAngle) < 1e-6) {
            throw new InvalidArgumentException('orbitArc sweep angle must be non-zero.');
        }
        $segments = max(2, (int) ceil(abs($sweepAngle) / (M_PI / 12.0)));
        $points = [];
        for ($i = 0; $i <= $segments; $i++) {
            $a = $startAngle + $sweepAngle * ($i / $segments);
            $points[] = new Vec3(
                $centre->x + $radius * sin($a),
                $centre->y + $height,
                $centre->z + $radius * cos($a),
            );
        }
        return new self($points, $lookAt ?? $centre, $duration, $easing);
    }

    /** Straight move from `$from` to `$to`, looking at `$lookAt` throughout. */
    public static function approach(
        Vec3 $from,
        Vec3 $to,
        Vec3 $lookAt,
        float $duration,
        CameraEasing $easing = CameraEasing::EaseInOut,
    ): self {
        return new self([$from, $to], $lookAt, $duration, $easing);
    }

    public function duration(): float
    {
        return $this->duration;
    }

    public function easing(): CameraEasing
    {
        return $this->easing;
    }

    /** Path length in world units (the spline, not the polyline). */
    public function pathLength(): float
    {
        return $this->totalLength;
    }

    /** @return list<Vec3> */
    public function waypoints(): array
    {
        return array_map(static fn(Vec3 $v): Vec3 => clone $v, $this->waypoints);
    }

    /** Pose at normalised time `$t` (clamped to 0..1). t = 0 is the first waypoint, t = 1 the last. */
    public function evaluate(float $t): CameraPose
    {
        return $this->poseAtPathFraction($this->easing->apply($t));
    }

    /** Camera position only at normalised time `$t`. */
    public function positionAt(float $t): Vec3
    {
        return $this->sample($this->easing->apply($t))[0];
    }

    private function poseAtPathFraction(float $p): CameraPose
    {
        [$pos, $look] = $this->sample($p);
        [$rawYaw, $pitch] = CameraPose::anglesFor($look->sub($pos));

        // Unwrap against the nearest table node so yaw is continuous along the whole path.
        $k = min(self::YAW_SAMPLES, (int) round($p * self::YAW_SAMPLES));
        $ref = $this->yawTable[$k];
        $yaw = $ref + CameraPose::wrapAngle($rawYaw - $ref);

        return new CameraPose($pos, $yaw, $pitch);
    }

    /** @return array{0: Vec3, 1: Vec3} position and look point at path fraction `$p` (0..1) */
    private function sample(float $p): array
    {
        [$seg, $local] = $this->locate(max(0.0, min(1.0, $p)));
        $pos = self::catmullRom($this->waypoints, $seg, $local);
        $look = $this->lookPoints !== null
            ? self::catmullRom($this->lookPoints, $seg, $local)
            : $this->fixedLook;
        assert($look !== null);
        return [$pos, $look];
    }

    /**
     * Path fraction -> [segment, local t within the segment] through the arc-length table.
     *
     * @return array{0: int, 1: float}
     */
    private function locate(float $p): array
    {
        $segments = count($this->waypoints) - 1;
        $n = self::TABLE_PER_SEGMENT;
        $target = $p * $this->totalLength;

        // Binary search the largest node whose cumulative length <= target.
        $lo = 0;
        $hi = $segments * $n;
        while ($hi - $lo > 1) {
            $mid = ($lo + $hi) >> 1;
            if ($this->tableLength[$mid] <= $target) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        $span = $this->tableLength[$hi] - $this->tableLength[$lo];
        $f = $span > 0.0 ? ($target - $this->tableLength[$lo]) / $span : 0.0;
        $f = max(0.0, min(1.0, $f));

        $seg = intdiv($lo, $n);
        $localIndex = $lo - $seg * $n;
        $local = ($localIndex + $f) / $n;
        return [$seg, $local];
    }

    private function buildArcLengthTable(): void
    {
        $segments = count($this->waypoints) - 1;
        $n = self::TABLE_PER_SEGMENT;
        $this->tableLength = [0.0];
        $prev = $this->waypoints[0];
        $sum = 0.0;
        for ($s = 0; $s < $segments; $s++) {
            for ($k = 1; $k <= $n; $k++) {
                $q = self::catmullRom($this->waypoints, $s, $k / $n);
                $sum += $q->sub($prev)->length();
                $this->tableLength[] = $sum;
                $prev = $q;
            }
        }
        $this->totalLength = $sum;
    }

    private function buildYawTable(): void
    {
        $this->yawTable = [];
        $prev = 0.0;
        for ($k = 0; $k <= self::YAW_SAMPLES; $k++) {
            [$pos, $look] = $this->sample($k / self::YAW_SAMPLES);
            $raw = CameraPose::anglesFor($look->sub($pos))[0];
            $yaw = $k === 0 ? $raw : $prev + CameraPose::wrapAngle($raw - $prev);
            $this->yawTable[] = $yaw;
            $prev = $yaw;
        }
    }

    /**
     * Centripetal Catmull-Rom (Barry-Goldman pyramid) on segment `$seg` (points i, i+1),
     * `$u` in 0..1. End tangents come from reflected phantom points.
     *
     * @param list<Vec3> $pts
     */
    private static function catmullRom(array $pts, int $seg, float $u): Vec3
    {
        $last = count($pts) - 1;
        $p1 = $pts[$seg];
        $p2 = $pts[$seg + 1];
        $p0 = $seg > 0 ? $pts[$seg - 1] : $p1->mul(2.0)->sub($p2);
        $p3 = $seg + 1 < $last ? $pts[$seg + 2] : $p2->mul(2.0)->sub($p1);

        $t0 = 0.0;
        $t1 = $t0 + self::knot($p0, $p1);
        $t2 = $t1 + self::knot($p1, $p2);
        $t3 = $t2 + self::knot($p2, $p3);
        $t = $t1 + ($t2 - $t1) * $u;

        $a1 = self::mix($p0, $p1, $t0, $t1, $t);
        $a2 = self::mix($p1, $p2, $t1, $t2, $t);
        $a3 = self::mix($p2, $p3, $t2, $t3, $t);
        $b1 = self::mix($a1, $a2, $t0, $t2, $t);
        $b2 = self::mix($a2, $a3, $t1, $t3, $t);
        return self::mix($b1, $b2, $t1, $t2, $t);
    }

    /** Centripetal knot spacing sqrt(|b - a|), floored so coincident look points stay finite. */
    private static function knot(Vec3 $a, Vec3 $b): float
    {
        return max(1e-4, sqrt($b->sub($a)->length()));
    }

    private static function mix(Vec3 $a, Vec3 $b, float $ta, float $tb, float $t): Vec3
    {
        $w = ($t - $ta) / ($tb - $ta);
        return new Vec3(
            $a->x + ($b->x - $a->x) * $w,
            $a->y + ($b->y - $a->y) * $w,
            $a->z + ($b->z - $a->z) * $w,
        );
    }
}
