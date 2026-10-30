<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\Math\Vec3;
use PHPolygon\System\Physics3DSystem;

/**
 * Makes CharacterController3D::$stepHeight hold on triangle colliders.
 *
 * Boxes lift a character on contact, but a mesh only pushes the capsule the
 * shortest way out: an edge blocks sideways however low it is. So a grounded
 * character that wanted to walk and hardly moved is probed for a walkable surface
 * ahead, within its step height, and set onto it if its body fits there. No second
 * physics run, only narrow queries into the BVHs of this tick, and only for a
 * blocked character.
 *
 * The capsule keeps its radius away from an edge, so the tread lies further ahead
 * than a frame's travel. The lift goes just past the edge, and what that carries the
 * character beyond the way it wanted to walk is booked as
 * {@see CharacterController3D::$stepDebt} and taken off the next frames: steps do not
 * make anybody faster. The probe sees behind thin walls - a mesh wall has no
 * thickness and a column does not see upright faces - which is why the way there has
 * to be clear ({@see pathIsClear()}).
 *
 * Built once per physics tick from the colliders that tick collected.
 */
final class StepAssist
{
    /**
     * Rises below this are no steps (m). The capsule rolls over them by itself, and
     * a flat bump must not cause a lift.
     */
    public const float MIN_RISE = 0.06;

    /**
     * Wanted movement below this speed (m/s) is standing still. A speed, not a
     * distance: 0.02 m per frame is full walking pace at 300 Hz.
     */
    public const float MIN_INTENT_SPEED = 0.5;

    /** More wanted movement than this in one tick (m) is a warp, not a step. */
    public const float MAX_REACH = 1.0;

    /**
     * Below this share of the wanted distance a character counts as blocked. Low on
     * purpose: scraping along a wall loses distance too and must not lift anybody.
     */
    private const float BLOCKED_RATIO = 0.45;

    /**
     * Clearance above a tread from where things count as obstacles in its column
     * (m). The tread itself lies at the foot of the query and must not report as a
     * beam.
     */
    private const float HEADROOM_FROM = 0.15;

    /** Half width of the probe column (m): slim as a footprint. */
    private const float PROBE_HALF = 0.05;

    /** How far past the capsule wall the probe reaches at least (m). */
    private const float PROBE_MARGIN = 0.05;

    /** Spacing of the probes along the way (m). */
    private const float PROBE_SPACING = 0.05;

    /** How far past an edge the centre of the capsule ends up (m): enough to stand. */
    private const float OVERHANG = 0.01;

    /** How much slimmer the fitting capsule is (m): a side wall touches the real one. */
    private const float PATH_CLEARANCE = 0.02;

    /** Surfaces with |normal.y| above this carry a character at all. */
    private const float MIN_FLOOR_NORMAL_Y = 0.5;

    /** Columns from the centre of the capsule to its leading side that may hold its ground. */
    private const int GROUND_SAMPLES = 4;

    /** A body contact pushing further down than this (share of its length) is a ceiling. */
    private const float CEILING_SHARE = 0.3;

    /** A body contact pushing further back along the step's push than this is in the way. */
    private const float AHEAD_SHARE = 0.5;

    /**
     * @param list<array{entityId: int, min: Vec3, max: Vec3}> $boxes unrotated solid boxes
     * @param list<array{entityId: int, bvh: BVH}> $meshes solid meshes
     * @param list<array{entityId: int, box: OrientedBox}> $rotatedBoxes solid rotated boxes
     */
    public function __construct(
        private readonly array $boxes,
        private readonly array $meshes,
        private readonly array $rotatedBoxes,
    ) {}

    /**
     * Would a character fit on a tread at (x, z)? For a step lift on contact, while
     * the capsule may still be pressed into a side wall that a later pass resolves:
     * only what would push the raised body down, or back the way the step pushes it
     * ($stepPush), is in the way. Back counts for a wall that rises from the tread, and
     * for anything the capsule is deeper in than in the step itself; the next
     * rungs of a ladder lie in the plane of this one and are not in the way of it.
     * Everything lower than the step height above the tread is a further step, and
     * $stepEntityId the tread itself.
     */
    public function bodyFitsAt(float $x, float $z, float $tread, CharacterController3D $controller, int $selfId, int $stepEntityId, Vec3 $stepPush): bool
    {
        $radius = max(0.01, $controller->radius - self::PATH_CLEARANCE);
        $headY = $tread + $controller->height;
        $fromY = self::obstaclesFrom($tread, $controller, $radius);
        $pushLength = sqrt($stepPush->x * $stepPush->x + $stepPush->z * $stepPush->z);
        $backX = $pushLength > 0.0 ? $stepPush->x / $pushLength : 0.0;
        $backZ = $pushLength > 0.0 ? $stepPush->z / $pushLength : 0.0;

        $min = new Vec3($x - $radius, $fromY, $z - $radius);
        $max = new Vec3($x + $radius, $headY, $z + $radius);
        foreach ($this->boxes as $box) {
            if ($box['entityId'] === $selfId || $box['entityId'] === $stepEntityId) {
                continue;
            }
            $push = Physics3DSystem::resolveAABB($min, $max, $box['min'], $box['max']);
            if ($push !== null && self::inTheWay($push, $backX, $backZ, $pushLength, $box['min']->y <= $tread)) {
                return false;
            }
        }

        $low = new Vec3($x, $fromY + $radius, $z);
        $high = new Vec3($x, $headY - $radius, $z);
        foreach ($this->meshes as $mesh) {
            if ($mesh['entityId'] === $selfId || $mesh['entityId'] === $stepEntityId) {
                continue;
            }
            foreach ($mesh['bvh']->query($min, $max) as $triangle) {
                $push = CollisionMath::capsuleVsTriangle($low, $high, $radius, $triangle);
                $bottom = min($triangle->v0->y, $triangle->v1->y, $triangle->v2->y);
                if ($push !== null && self::inTheWay($push, $backX, $backZ, $pushLength, $bottom <= $tread)) {
                    return false;
                }
            }
        }
        foreach ($this->rotatedBoxes as $rotated) {
            if ($rotated['entityId'] === $selfId || $rotated['entityId'] === $stepEntityId) {
                continue;
            }
            $push = $rotated['box']->capsulePush($low, $high, $radius);
            if ($push !== null && self::inTheWay($push, $backX, $backZ, $pushLength, $rotated['box']->min->y <= $tread)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Set a blocked character onto the tread ahead of it, if there is one.
     *
     * @param Vec3 $from where the character stood after the last tick
     * @param float $wantedX movement it wanted this tick
     * @param float $wantedZ movement it wanted this tick
     */
    public function climb(CharacterController3D $controller, Transform3D $transform, Vec3 $from, float $wantedX, float $wantedZ, int $selfId): void
    {
        $wantedLen = sqrt($wantedX * $wantedX + $wantedZ * $wantedZ);
        $position = $transform->position;
        $gotX = $position->x - $from->x;
        $gotZ = $position->z - $from->z;
        if (sqrt($gotX * $gotX + $gotZ * $gotZ) >= $wantedLen * self::BLOCKED_RATIO) {
            return;
        }

        $feetY = $position->y - $controller->height / 2.0;
        $dirX = $wantedX / $wantedLen;
        $dirZ = $wantedZ / $wantedLen;
        $reach = $controller->radius + self::PROBE_MARGIN;
        $near = min($wantedLen, $reach);
        $far = max($wantedLen, $reach);

        $minNormalY = max(self::MIN_FLOOR_NORMAL_Y, cos(deg2rad($controller->slopeLimit)));
        $floors = $this->floorsAlong($position, $dirX, $dirZ, $far, $feetY, $controller->stepHeight, $minNormalY, $selfId);
        if ($floors === []) {
            return;
        }

        $previous = 0.0;
        for ($distance = $near; ; $distance = min($far, $distance + self::PROBE_SPACING)) {
            $targetX = $position->x + $dirX * $distance;
            $targetZ = $position->z + $dirZ * $distance;
            $tread = self::lowestTread($floors, $targetX, $targetZ, $feetY, $controller->stepHeight);

            if ($tread !== null) {
                if ($distance > $wantedLen) {
                    // The edge lies between the last two probes: go no further than it.
                    $distance = self::edgeBetween($floors, $position, $dirX, $dirZ, $previous, $distance, $feetY, $controller->stepHeight);
                    $distance = min($far, $distance + self::OVERHANG);
                    $targetX = $position->x + $dirX * $distance;
                    $targetZ = $position->z + $dirZ * $distance;
                    $tread = self::lowestTread($floors, $targetX, $targetZ, $feetY, $controller->stepHeight) ?? $tread;
                }
                if ($this->rise($controller, $transform, $from, $tread, $distance, $wantedLen, $dirX, $dirZ, $selfId)) {
                    return;
                }
            }
            if ($distance >= $far) {
                return;
            }
            $previous = $distance;
        }
    }

    /**
     * Lift onto one tread, if everything about it agrees.
     *
     * @param array{y: float, triangle: Triangle, entityId: int} $tread
     */
    private function rise(CharacterController3D $controller, Transform3D $transform, Vec3 $from, array $tread, float $distance, float $wantedLen, float $dirX, float $dirZ, int $selfId): bool
    {
        $position = $transform->position;
        $halfHeight = $controller->height / 2.0;
        $feetY = $position->y - $halfHeight;
        $targetX = $position->x + $dirX * $distance;
        $targetZ = $position->z + $dirZ * $distance;
        $treadY = $tread['y'];
        $triangle = $tread['triangle'];

        if ($distance > $wantedLen && self::planeHeight($triangle, $position->x, $position->z) <= $feetY + self::MIN_RISE) {
            // A ramp, not an edge: it starts under the feet, the far probe just sees
            // a rise that is really continuous. Only this frame's way up the
            // surface, at the height a capsule rests on it.
            $targetX = $position->x + $dirX * $wantedLen;
            $targetZ = $position->z + $dirZ * $wantedLen;
            $treadY = self::planeHeight($triangle, $targetX, $targetZ)
                + $controller->radius * (1.0 / $triangle->normal->y - 1.0);
            if ($treadY <= $feetY) {
                return false;
            }
        }

        if (!$this->columnIsFree($targetX, $targetZ, $tread['y'], $controller->height, $tread['triangle'], $selfId)) {
            return false;
        }
        // Counted from the ground, not the feet: a capsule pushed up a few
        // centimetres by a rounded edge must not gain as much step height. On a
        // narrow stair the capsule can rest on an edge with its centre still over
        // the tread below, so the ground under its leading side counts as well.
        $standing = null;
        for ($i = 0; $i < self::GROUND_SAMPLES; $i++) {
            $ahead = $controller->radius * $i / (self::GROUND_SAMPLES - 1);
            $ground = $this->groundBelow($position->x + $dirX * $ahead, $position->z + $dirZ * $ahead, $feetY, $controller->stepHeight, $selfId);
            if ($ground !== null && ($standing === null || $ground > $standing)) {
                $standing = $ground;
            }
        }
        if ($treadY - ($standing ?? $feetY) > $controller->stepHeight + 1e-6) {
            return false;
        }
        if (!$this->pathIsClear($position, $targetX, $targetZ, $treadY, $controller, $selfId)) {
            return false;
        }

        $transform->position = new Vec3($targetX, $treadY + $halfHeight, $targetZ);
        $transform->worldMatrix = $transform->getLocalMatrix();
        $controller->velocity = new Vec3($controller->velocity->x, 0.0, $controller->velocity->z);
        $controller->isGrounded = true;
        $controller->groundEntityId = $tread['entityId'];

        $travelled = ($targetX - $from->x) * $dirX + ($targetZ - $from->z) * $dirZ;
        $excess = $travelled - $wantedLen;
        if ($excess > 0.0) {
            $debt = new Vec3($dirX * $excess, 0.0, $dirZ * $excess);
            $controller->stepDebt = $controller->stepDebt?->add($debt) ?? $debt;
        }

        return true;
    }

    /** Where obstacles begin above a tread: lower things are further steps. */
    private static function obstaclesFrom(float $tread, CharacterController3D $controller, float $radius): float
    {
        return min($tread + $controller->stepHeight, $tread + $controller->height - 2.0 * $radius);
    }

    private static function inTheWay(Vec3 $push, float $backX, float $backZ, float $stepDepth, bool $risesFromTread): bool
    {
        $length = $push->length();
        if ($length < 1e-9) {
            return false;
        }
        if ($push->y < -self::CEILING_SHARE * $length) {
            return true;
        }

        return ($push->x * $backX + $push->z * $backZ) > self::AHEAD_SHARE * $length
            && ($risesFromTread || $length > $stepDepth);
    }

    /**
     * All walkable mesh triangles in the step band along the way ahead. Usually none
     * (a wall, an empty corridor), and then no column has to be looked at.
     *
     * @return list<array{triangle: Triangle, entityId: int}>
     */
    private function floorsAlong(Vec3 $position, float $dirX, float $dirZ, float $far, float $feetY, float $stepHeight, float $minNormalY, int $selfId): array
    {
        $toX = $position->x + $dirX * $far;
        $toZ = $position->z + $dirZ * $far;
        $min = new Vec3(min($position->x, $toX) - self::PROBE_HALF, $feetY + self::MIN_RISE, min($position->z, $toZ) - self::PROBE_HALF);
        $max = new Vec3(max($position->x, $toX) + self::PROBE_HALF, $feetY + $stepHeight, max($position->z, $toZ) + self::PROBE_HALF);

        $floors = [];
        foreach ($this->meshes as $mesh) {
            if ($mesh['entityId'] === $selfId) {
                continue;
            }
            foreach ($mesh['bvh']->query($min, $max) as $triangle) {
                if ($triangle->normal->y >= $minNormalY) {
                    $floors[] = ['triangle' => $triangle, 'entityId' => $mesh['entityId']];
                }
            }
        }

        return $floors;
    }

    /**
     * Lowest of the given floors over (x, z) within the step height of the feet.
     *
     * @param list<array{triangle: Triangle, entityId: int}> $floors
     * @return array{y: float, triangle: Triangle, entityId: int}|null
     */
    private static function lowestTread(array $floors, float $x, float $z, float $feetY, float $stepHeight): ?array
    {
        $lowest = $feetY + self::MIN_RISE;
        $highest = $feetY + $stepHeight;
        $best = null;
        foreach ($floors as $floor) {
            $y = self::heightOver($floor['triangle'], $x, $z);
            if ($y !== null && $y >= $lowest && $y <= $highest && ($best === null || $y < $best['y'])) {
                $best = ['y' => $y, 'triangle' => $floor['triangle'], 'entityId' => $floor['entityId']];
            }
        }

        return $best;
    }

    /**
     * Narrow down where a tread begins between a probe that missed it and one that found it.
     *
     * @param list<array{triangle: Triangle, entityId: int}> $floors
     */
    private static function edgeBetween(array $floors, Vec3 $position, float $dirX, float $dirZ, float $miss, float $hit, float $feetY, float $stepHeight): float
    {
        for ($i = 0; $i < 4; $i++) {
            $mid = ($miss + $hit) * 0.5;
            if (self::lowestTread($floors, $position->x + $dirX * $mid, $position->z + $dirZ * $mid, $feetY, $stepHeight) !== null) {
                $hit = $mid;
            } else {
                $miss = $mid;
            }
        }

        return $hit;
    }

    /** Highest floor under the character, at most $depth below its feet. */
    private function groundBelow(float $x, float $z, float $feetY, float $depth, int $selfId): ?float
    {
        $lowest = $feetY - $depth;
        $min = new Vec3($x - self::PROBE_HALF, $lowest, $z - self::PROBE_HALF);
        $max = new Vec3($x + self::PROBE_HALF, $feetY, $z + self::PROBE_HALF);
        $best = null;

        foreach ($this->meshes as $mesh) {
            if ($mesh['entityId'] === $selfId) {
                continue;
            }
            foreach ($mesh['bvh']->query($min, $max) as $triangle) {
                if ($triangle->normal->y <= self::MIN_FLOOR_NORMAL_Y) {
                    continue;
                }
                $y = self::heightOver($triangle, $x, $z);
                if ($y !== null && $y >= $lowest && $y <= $feetY && ($best === null || $y > $best)) {
                    $best = $y;
                }
            }
        }

        foreach ($this->rotatedBoxes as $rotated) {
            if ($rotated['entityId'] === $selfId) {
                continue;
            }
            $y = $rotated['box']->surfaceAt($x, $z);
            if ($y !== null && $y >= $lowest && $y <= $feetY && ($best === null || $y > $best)) {
                $best = $y;
            }
        }

        foreach ($this->boxes as $box) {
            $top = $box['max']->y;
            if ($box['entityId'] !== $selfId
                && $top >= $lowest && $top <= $feetY && ($best === null || $top > $best)
                && $x >= $box['min']->x && $x <= $box['max']->x
                && $z >= $box['min']->z && $z <= $box['max']->z
            ) {
                $best = $top;
            }
        }

        return $best;
    }

    /**
     * Does the body fit, at tread height, all the way from here to the target? The
     * capsule is carried in intervals below its radius, so a face without thickness
     * cannot fall between two samples. As in {@see bodyFitsAt()} it starts one step
     * height above the tread: anything lower is a further step or the tread itself.
     */
    private function pathIsClear(Vec3 $from, float $toX, float $toZ, float $tread, CharacterController3D $controller, int $selfId): bool
    {
        $radius = max(0.01, $controller->radius - self::PATH_CLEARANCE);
        $headY = $tread + $controller->height;
        $fromY = self::obstaclesFrom($tread, $controller, $radius);
        $lowY = $fromY + $radius;
        $highY = max($lowY, $headY - $radius);

        $min = new Vec3(min($from->x, $toX) - $radius, $fromY, min($from->z, $toZ) - $radius);
        $max = new Vec3(max($from->x, $toX) + $radius, $headY, max($from->z, $toZ) + $radius);

        /** @var list<Triangle> $triangles */
        $triangles = [];
        foreach ($this->meshes as $mesh) {
            if ($mesh['entityId'] !== $selfId) {
                array_push($triangles, ...$mesh['bvh']->query($min, $max));
            }
        }

        /** @var list<OrientedBox> $rotated */
        $rotated = [];
        foreach ($this->rotatedBoxes as $candidate) {
            $box = $candidate['box'];
            if ($candidate['entityId'] !== $selfId
                && $box->max->x > $min->x && $box->min->x < $max->x
                && $box->max->y > $min->y && $box->min->y < $max->y
                && $box->max->z > $min->z && $box->min->z < $max->z
            ) {
                $rotated[] = $box;
            }
        }

        /** @var list<array{entityId: int, min: Vec3, max: Vec3}> $boxes */
        $boxes = [];
        foreach ($this->boxes as $box) {
            if ($box['entityId'] !== $selfId
                && $box['max']->x > $min->x && $box['min']->x < $max->x
                && $box['max']->y > $min->y && $box['min']->y < $max->y
                && $box['max']->z > $min->z && $box['min']->z < $max->z
            ) {
                $boxes[] = $box;
            }
        }

        if ($triangles === [] && $boxes === [] && $rotated === []) {
            return true;
        }

        $length = sqrt(($toX - $from->x) ** 2 + ($toZ - $from->z) ** 2);
        $samples = max(1, (int) ceil($length / $radius));
        for ($i = 0; $i <= $samples; $i++) {
            $t = $i / $samples;
            $x = $from->x + ($toX - $from->x) * $t;
            $z = $from->z + ($toZ - $from->z) * $t;

            $low = new Vec3($x, $lowY, $z);
            $high = new Vec3($x, $highY, $z);
            foreach ($triangles as $triangle) {
                if (CollisionMath::capsuleVsTriangle($low, $high, $radius, $triangle) !== null) {
                    return false;
                }
            }
            foreach ($rotated as $box) {
                if ($box->capsulePush($low, $high, $radius) !== null) {
                    return false;
                }
            }
            foreach ($boxes as $box) {
                if ($x + $radius > $box['min']->x && $x - $radius < $box['max']->x
                    && $z + $radius > $box['min']->z && $z - $radius < $box['max']->z
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Is there room for the body in the column over a tread? Everything from
     * HEADROOM_FROM above it up to the head counts: beams, ceilings, ledges. Rotated
     * boxes are left to {@see pathIsClear()}, which knows their real shape.
     */
    private function columnIsFree(float $x, float $z, float $tread, float $bodyHeight, Triangle $treadTriangle, int $selfId): bool
    {
        $bottom = $tread + self::HEADROOM_FROM;
        $top = $tread + $bodyHeight;
        $min = new Vec3($x - self::PROBE_HALF, $bottom, $z - self::PROBE_HALF);
        $max = new Vec3($x + self::PROBE_HALF, $top, $z + self::PROBE_HALF);

        foreach ($this->meshes as $mesh) {
            if ($mesh['entityId'] === $selfId) {
                continue;
            }
            foreach ($mesh['bvh']->query($min, $max) as $triangle) {
                if ($triangle === $treadTriangle) {
                    continue;
                }
                $y = self::heightOver($triangle, $x, $z);
                if ($y !== null && $y > $bottom && $y < $top) {
                    return false;
                }
            }
        }

        foreach ($this->boxes as $box) {
            if ($box['entityId'] !== $selfId
                && $x >= $box['min']->x && $x <= $box['max']->x
                && $z >= $box['min']->z && $z <= $box['max']->z
                && $box['max']->y > $bottom
                && $box['min']->y < $top
            ) {
                return false;
            }
        }

        return true;
    }

    /** Height of a triangle's plane over (x, z), also outside the triangle. */
    private static function planeHeight(Triangle $triangle, float $x, float $z): float
    {
        $normal = $triangle->normal;

        return $triangle->v0->y
            - ($normal->x * ($x - $triangle->v0->x) + $normal->z * ($z - $triangle->v0->z)) / $normal->y;
    }

    /** Height of a triangle over (x, z), or null outside its footprint or upright. */
    private static function heightOver(Triangle $triangle, float $x, float $z): ?float
    {
        $a = $triangle->v0;
        $b = $triangle->v1;
        $c = $triangle->v2;
        $den = ($b->z - $c->z) * ($a->x - $c->x) + ($c->x - $b->x) * ($a->z - $c->z);
        if (abs($den) < 1e-9) {
            return null;
        }

        $w1 = (($b->z - $c->z) * ($x - $c->x) + ($c->x - $b->x) * ($z - $c->z)) / $den;
        if ($w1 < -1e-6 || $w1 > 1.0 + 1e-6) {
            return null;
        }
        $w2 = (($c->z - $a->z) * ($x - $c->x) + ($a->x - $c->x) * ($z - $c->z)) / $den;
        if ($w2 < -1e-6 || $w2 > 1.0 + 1e-6) {
            return null;
        }
        $w3 = 1.0 - $w1 - $w2;
        if ($w3 < -1e-6 || $w3 > 1.0 + 1e-6) {
            return null;
        }

        return $w1 * $a->y + $w2 * $b->y + $w3 * $c->y;
    }
}
