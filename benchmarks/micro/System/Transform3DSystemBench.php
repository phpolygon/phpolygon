<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\System\Transform3DSystem;

/**
 * Transform3DSystem no longer walks the whole Transform3D pool every tick
 * (Phase 1 of the engine's visibility/activity-tier plan, 2026-11-11): a
 * dirty queue fed by Transform3D's set hooks drives an active set instead of
 * a per-entity float compare. A built world is mostly static: thousands of
 * roots that never move, a few hierarchies, a handful of animated entities.
 * The steady-state tick should now cost O(changes + a 1/32 safety-net
 * sample), not O(entity count) - benchStaticWorldTick and
 * benchTenThousandStaticTick are the regression guard for that.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/System/Transform3DSystemBench.php --report=aggregate
 */
final class Transform3DSystemBench
{
    private const ROOTS = 2600;
    private const PARENTS = 20;
    private const CHILDREN_PER_PARENT = 2;
    private const MOVERS = 20;
    private const STATIC_ONLY = 10_000;

    private World $world;
    private Transform3DSystem $system;

    /** @var list<Transform3D> */
    private array $movers = [];

    private float $phase = 0.0;

    private Transform3D $writeTarget;

    public function setUp(): void
    {
        $this->world = new World();
        $this->system = new Transform3DSystem();
        $this->movers = [];

        for ($i = 0; $i < self::ROOTS; $i++) {
            $t = new Transform3D(
                new Vec3((float) ($i % 97), 0.0, (float) intdiv($i, 97)),
                Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), $i * 0.01),
            );
            $this->world->createEntity()->attach($t);
            if ($i < self::MOVERS) {
                $this->movers[] = $t;
            }
        }

        for ($p = 0; $p < self::PARENTS; $p++) {
            $parent = $this->world->createEntity();
            $parentT = new Transform3D(new Vec3((float) $p, 5.0, 0.0));
            $parent->attach($parentT);
            for ($c = 0; $c < self::CHILDREN_PER_PARENT; $c++) {
                $child = $this->world->createEntity();
                $childT = new Transform3D(new Vec3(0.0, 1.0 + $c, 0.0));
                $child->attach($childT);
                $parentT->addChild($childT, $child->id, $parent->id);
            }
        }

        // Warm the snapshot cache: the benchmark measures steady state.
        $this->system->update($this->world, 0.016);
    }

    /**
     * Everything static - the common frame on a built world.
     *
     * @BeforeMethods("setUp")
     * @Revs(50)
     * @Iterations(5)
     */
    public function benchStaticWorldTick(): void
    {
        $this->system->update($this->world, 0.016);
    }

    /**
     * One awake root with a large parked subtree (an interior kept loaded but
     * dormant): the dormant children are skipped, not walked.
     */
    public function setUpParkedSubtree(): void
    {
        $this->setUp();
        $root = $this->world->createEntity();
        $rootT = new Transform3D(new Vec3(0.0, 0.0, 0.0));
        $root->attach($rootT);
        $ids = [];
        for ($i = 0; $i < 2400; $i++) {
            $child = $this->world->createEntity();
            $childT = new Transform3D(new Vec3((float) ($i % 40), 0.0, (float) intdiv($i, 40)));
            $child->attach($childT);
            $rootT->addChild($childT, $child->id, $root->id);
            $ids[] = $child->id;
        }
        $this->system->update($this->world, 0.016);
        $this->world->setEntitiesDormant($ids, true);
        $this->system->update($this->world, 0.016);
    }

    /**
     * @BeforeMethods("setUpParkedSubtree")
     * @Revs(50)
     * @Iterations(5)
     */
    public function benchParkedSubtreeTick(): void
    {
        $this->system->update($this->world, 0.016);
    }

    /**
     * A few animated roots per tick.
     *
     * @BeforeMethods("setUp")
     * @Revs(50)
     * @Iterations(5)
     */
    public function benchFewMoversTick(): void
    {
        $this->phase += 0.016;
        foreach ($this->movers as $i => $t) {
            $t->position = new Vec3($t->position->x, sin($this->phase + $i), $t->position->z);
        }
        $this->system->update($this->world, 0.016);
    }

    /**
     * 10,000 childless static roots that never move again after creation -
     * the exact shape that grew from 5,600 to 11,800 entities in a game and
     * took the OLD full-pool walk from 3.3 to 6.2 ms per tick (measured
     * 2026-11-11). Nothing in this set is ever touched after setUp()'s warm
     * tick, so the steady-state cost here is purely the dirty-queue drain
     * (empty) plus the 1/32 safety-net sample - independent of entity count.
     */
    public function setUpStaticOnly(): void
    {
        $this->world = new World();
        $this->system = new Transform3DSystem();
        for ($i = 0; $i < self::STATIC_ONLY; $i++) {
            $t = new Transform3D(new Vec3((float) ($i % 200), 0.0, (float) intdiv($i, 200)));
            $this->world->createEntity()->attach($t);
        }
        // Every entity is freshly attached, so the first tick processes all
        // of them once (same as the old code's first tick) - the bench
        // measures the SECOND tick onward, where the fix applies.
        $this->system->update($this->world, 0.016);
    }

    /**
     * @BeforeMethods("setUpStaticOnly")
     * @Revs(50)
     * @Iterations(5)
     */
    public function benchTenThousandStaticTick(): void
    {
        $this->system->update($this->world, 0.016);
    }

    /**
     * Raw cost of the position set hook on a bound Transform3D, independent
     * of Transform3DSystem::update() - the "cost of set-hooks on the write
     * path" risk from turning a plain property into a hooked one. A quick
     * standalone probe on this machine (PHP 8.5.5,
     * E:\ClaudeTemp\phpolygon\transform-active\hook-probe.php) measured a
     * bound hooked assignment at ~213 ns against ~69 ns for the old plain
     * property; this bench is the same write inside the real Transform3D,
     * run through PHPBench so CI's regression gate sees it too.
     */
    public function setUpWriteCost(): void
    {
        $this->world = new World();
        $this->system = new Transform3DSystem();
        $this->writeTarget = new Transform3D();
        $this->world->createEntity()->attach($this->writeTarget);
        $this->phase = 0.0;
    }

    /**
     * @BeforeMethods("setUpWriteCost")
     * @Revs(1000)
     * @Iterations(5)
     */
    public function benchPositionAssignment(): void
    {
        $this->phase += 0.016;
        $this->writeTarget->position = new Vec3($this->phase, 0.0, 0.0);
    }
}
