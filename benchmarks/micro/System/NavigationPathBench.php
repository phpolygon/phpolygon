<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Component\NavMeshAgent;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\System\NavigationSystem;

/**
 * CPU cost of one NavigationSystem update for 10 figures walking authored
 * waypoint paths (no NavMesh), turning toward their travel direction. Each
 * figure walks a long zig-zag, so all of them are moving while measured.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/System/NavigationPathBench.php --report=aggregate
 */
final class NavigationPathBench
{
    private const int FIGURES = 10;

    private World $world;
    private NavigationSystem $system;

    public function setUp(): void
    {
        $this->world = new World();
        for ($f = 0; $f < self::FIGURES; $f++) {
            $points = [];
            for ($i = 0; $i < 400; $i++) {
                $points[] = new Vec3(($i % 2) * 6.0, 0.0, $f * 4.0 + $i * 2.0);
            }
            $agent = new NavMeshAgent(speed: 1.3, angularSpeed: 360.0, acceleration: 8.0, stoppingDistance: 0.2);
            $agent->faceMovement = true;
            $this->world->createEntity()->attach(new Transform3D(position: $points[0]))->attach($agent);
            $agent->followPath($points, [5 => 0.5], new Vec3(0.0, 0.0, 0.0));
        }
        $this->system = new NavigationSystem();
        for ($i = 0; $i < 120; $i++) {
            $this->system->update($this->world, 1.0 / 60.0);
        }
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(1000)
     * @Iterations(5)
     */
    public function benchUpdate(): void
    {
        $this->system->update($this->world, 1.0 / 60.0);
    }
}
