<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\MeshCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\System\Physics3DSystem;

/**
 * Physics3DSystem collects every box and mesh collider each tick before it
 * resolves the characters. In a built world the colliders are static and the
 * characters few, so the steady-state cost is the collection pass - one world
 * matrix check per collider - plus the per-character query over all BVHs.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/System/Physics3DSystemBench.php --report=aggregate
 */
final class Physics3DSystemBench
{
    private const BOXES = 260;
    private const ROTATED_BOXES = 20;
    private const MESHES = 470;

    private World $world;
    private Physics3DSystem $system;

    public function setUp(): void
    {
        MeshRegistry::register('bench_physics_box', BoxMesh::generate(2.0, 2.0, 2.0));
        $this->world = new World();
        $this->system = new Physics3DSystem();

        for ($i = 0; $i < self::BOXES; $i++) {
            $e = $this->world->createEntity();
            $rotation = $i < self::ROTATED_BOXES
                ? Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), 0.4)
                : Quaternion::identity();
            $e->attach(new Transform3D(new Vec3((float) ($i % 40) * 6.0, 1.0, (float) intdiv($i, 40) * 6.0), $rotation));
            $e->attach(new BoxCollider3D(size: new Vec3(2.0, 2.0, 2.0), isStatic: true));
        }
        for ($i = 0; $i < self::MESHES; $i++) {
            $e = $this->world->createEntity();
            $e->attach(new Transform3D(new Vec3((float) ($i % 40) * 6.0 + 3.0, 1.0, -(float) intdiv($i, 40) * 6.0 - 3.0)));
            $e->attach(new MeshCollider3D('bench_physics_box'));
        }

        $player = $this->world->createEntity();
        $player->attach(new Transform3D(new Vec3(-20.0, 0.9, -20.0)));
        $player->attach(new CharacterController3D(height: 1.8, radius: 0.4));

        // Build the BVHs / AABB caches once: the benchmark measures steady state.
        $this->system->update($this->world, 0.016);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(20)
     * @Iterations(5)
     */
    public function benchStaticCollidersOneCharacter(): void
    {
        $this->system->update($this->world, 0.016);
    }
}
