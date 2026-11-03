<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;
use PHPolygon\Motion\TrackEnd;

/**
 * Items carried along a polyline by signals: a belt with crates, a rail with carts,
 * a chain of buckets. Driven by {@see \PHPolygon\System\SignalMotionSystem}.
 *
 * - The items are a fixed POOL of entities the game builds beforehand
 *   ({@see $items}); nothing is spawned or destroyed at run time. A free item is
 *   hidden (its MeshRenderer and those of its children), an item on the track is shown.
 * - A pulse on {@see $spawnChannel} puts the next free item at the start, as soon as
 *   the start is clear by {@see $spacing}; the pulse's value is remembered with the
 *   item ({@see valueOf()}) - a number to show on it, a kind.
 * - The track moves while {@see $runChannel} is on ('' = always) and
 *   {@see $haltChannel} is off ('' = never halts), at {@see $speed} metres per second
 *   times the number channel {@see $speedChannel} ('' = 1), easing in over
 *   {@see $rampUp} and out over {@see $rampDown} seconds.
 * - Items keep {@see $spacing} apart and never pass each other. At the end they queue
 *   or are recycled ({@see $end}); every arrival adds 1 to the number channel
 *   {@see $arrivedChannel}. The bool channel {@see $fullChannel} is on while a pulse
 *   waits and no item is free (a full buffer).
 *
 * Points are in the items' parent space: give the items the same parent as the track
 * (or none, then world space). Item positions are written to their Transform3D;
 * with {@see $alignToPath} their local +Z turns along the segment they are on.
 *
 * With {@see $cullRadius} > 0 the whole track freezes (nothing advances, nothing is
 * written) while the sphere of that radius around the entity's world position is
 * outside the camera or beyond {@see $maxDistance}; pulses keep waiting meanwhile.
 * {@see $effect} names its entry in the effect register for reduced motion
 * ({@see \PHPolygon\Motion\MotionTime}); ramps then also stretch by {@see $reducedRampScale}.
 */
#[Serializable]
#[Category('Motion')]
class SignalTrack extends AbstractComponent
{
    /** Entity holding the {@see Signals}; null = this entity. */
    #[Property]
    public ?int $source = null;

    /** @var list<float> polyline, flat x, y, z per point (at least two points); change it through setPoints() */
    #[Property]
    public array $points = [];

    /** @var list<int> entity ids of the item pool */
    #[Property]
    public array $items = [];

    #[Property]
    public string $spawnChannel = 'spawn';

    #[Property]
    public string $runChannel = '';

    #[Property]
    public string $haltChannel = '';

    #[Property]
    public string $speedChannel = '';

    #[Property]
    public string $arrivedChannel = '';

    #[Property]
    public string $fullChannel = '';

    /** Metres per second at full drive. */
    #[Property]
    public float $speed = 1.0;

    #[Property]
    public float $rampUp = 0.0;

    #[Property]
    public float $rampDown = 0.0;

    /** Distance kept between item origins along the track, metres. */
    #[Property]
    public float $spacing = 1.0;

    #[Property]
    public TrackEnd $end = TrackEnd::Queue;

    #[Property]
    public bool $alignToPath = false;

    #[Property]
    public ?string $effect = null;

    /** Ramp durations are multiplied by this while the player asked for reduced motion. */
    #[Property]
    public float $reducedRampScale = 1.0;

    #[Property]
    public float $cullRadius = 0.0;

    #[Property]
    public float $maxDistance = 0.0;

    /** Drive level 0..1 (eased into the speed). */
    #[Hidden]
    public float $drive = 0.0;

    /** Distance the track surface has moved in total, metres - for rollers that follow it. */
    #[Hidden]
    public float $travel = 0.0;

    /** @var array<int, float> item entity id => distance along the track (items on the track only) */
    #[Hidden]
    public array $positions = [];

    /** @var array<int, float> item entity id => value of the pulse that sent it */
    #[Hidden]
    public array $values = [];

    /** @var array<int, true> items that already counted as arrived */
    #[Hidden]
    public array $arrived = [];

    /** Whether the track froze out of view in the last update. */
    #[Hidden]
    public bool $frozen = false;

    /** Whether the pool items were hidden once (done on the first update). */
    #[Hidden]
    public bool $initialized = false;

    /** @var list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}>|null */
    #[Hidden]
    private ?array $segmentCache = null;

    /**
     * @param list<Vec3> $points
     * @param list<int> $items
     */
    public function __construct(
        array $points = [],
        array $items = [],
        float $speed = 1.0,
        float $spacing = 1.0,
        TrackEnd $end = TrackEnd::Queue,
        string $spawnChannel = 'spawn',
        string $runChannel = '',
        string $haltChannel = '',
        float $rampUp = 0.0,
        float $rampDown = 0.0,
        ?int $source = null,
    ) {
        $this->setPoints($points);
        $this->items = $items;
        $this->speed = $speed;
        $this->spacing = max(0.0, $spacing);
        $this->end = $end;
        $this->spawnChannel = $spawnChannel;
        $this->runChannel = $runChannel;
        $this->haltChannel = $haltChannel;
        $this->rampUp = max(0.0, $rampUp);
        $this->rampDown = max(0.0, $rampDown);
        $this->source = $source;
    }

    /** @param list<Vec3> $points */
    public function setPoints(array $points): void
    {
        $flat = [];
        foreach ($points as $p) {
            $flat[] = $p->x;
            $flat[] = $p->y;
            $flat[] = $p->z;
        }
        $this->points = $flat;
        $this->segmentCache = null;
    }

    public function length(): float
    {
        $length = 0.0;
        foreach ($this->segments() as $s) {
            $length += $s[6];
        }
        return $length;
    }

    /**
     * Point and unit direction at a distance along the track (clamped to its ends).
     *
     * @return array{0: Vec3, 1: Vec3}
     */
    public function sample(float $distance): array
    {
        $segments = $this->segments();
        if ($segments === []) {
            $p = $this->points;
            return [count($p) >= 3 ? new Vec3((float) $p[0], (float) $p[1], (float) $p[2]) : Vec3::zero(), new Vec3(0.0, 0.0, 1.0)];
        }
        $left = max(0.0, $distance);
        $last = count($segments) - 1;
        foreach ($segments as $i => [$x, $y, $z, $dx, $dy, $dz, $len]) {
            if ($left <= $len || $i === $last) {
                $t = min($len, $left);
                return [new Vec3($x + $dx * $t, $y + $dy * $t, $z + $dz * $t), new Vec3($dx, $dy, $dz)];
            }
            $left -= $len;
        }
        return [Vec3::zero(), new Vec3(0.0, 0.0, 1.0)];
    }

    /**
     * The polyline as segments [start x, y, z, unit dx, dy, dz, length], zero-length
     * ones left out. Cached until {@see setPoints()}.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}>
     */
    public function segments(): array
    {
        if ($this->segmentCache !== null) {
            return $this->segmentCache;
        }
        $segments = [];
        $p = $this->points;
        for ($i = 0, $n = count($p) - 5; $i < $n; $i += 3) {
            $x = (float) $p[$i];
            $y = (float) $p[$i + 1];
            $z = (float) $p[$i + 2];
            $dx = (float) ($p[$i + 3] ?? $x) - $x;
            $dy = (float) ($p[$i + 4] ?? $y) - $y;
            $dz = (float) ($p[$i + 5] ?? $z) - $z;
            $len = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
            if ($len >= 1e-9) {
                $segments[] = [$x, $y, $z, $dx / $len, $dy / $len, $dz / $len, $len];
            }
        }
        return $this->segmentCache = $segments;
    }

    /**
     * Items currently on the track, front (furthest along) first.
     *
     * @return list<int>
     */
    public function activeItems(): array
    {
        $positions = $this->positions;
        arsort($positions);
        return array_keys($positions);
    }

    public function valueOf(int $item): ?float
    {
        return $this->values[$item] ?? null;
    }

    public function distanceOf(int $item): ?float
    {
        return $this->positions[$item] ?? null;
    }

    /** Take every item off the track (hidden by the next update) and forget arrivals. */
    public function clearItems(): void
    {
        $this->positions = [];
        $this->values = [];
        $this->arrived = [];
        $this->initialized = false;
    }
}
