<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\System\Transform3DSystem;

/**
 * Transform3DSystem runs every tick over every Transform3D in the world, and a
 * built world is mostly static: thousands of roots that never move, a few
 * hierarchies, a handful of animated entities. The steady-state tick is the
 * dirty CHECK, not the matrix rebuild.
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

    private World $world;
    private Transform3DSystem $system;

    /** @var list<Transform3D> */
    private array $movers = [];

    private float $phase = 0.0;

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
}
