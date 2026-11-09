<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Component\LightCone;
use PHPolygon\Component\SpotLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\System\LightConeSystem;

/**
 * CPU cost per frame of LightConeSystem over 64 lamps with a visible beam spread over a
 * yard, about half of them in view: query, cull, fade, sort, keep the 16 nearest.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/System/LightConeSystemBench.php --report=aggregate
 */
final class LightConeSystemBench
{
    private const int LAMPS = 64;

    private World $world;
    private SetCamera $camera;

    public function setUp(): void
    {
        $this->world = new World();
        for ($i = 0; $i < self::LAMPS; $i++) {
            $position = new Vec3(($i % 8) * 6.0 - 21.0, 4.0, -(int) ($i / 8) * 6.0 + 20.0);
            $this->world->createEntity()
                ->attach(new Transform3D(position: $position))
                ->attach(new SpotLight(new Vec3(0.1, -1.0, 0.2), intensity: 3.0, range: 8.0, angle: 0.45))
                ->attach(new LightCone());
        }
        $this->camera = new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 2.0, 25.0), new Vec3(0.0, 2.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(70.0), 1.777, 0.1, 500.0),
        );
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(500)
     * @Iterations(5)
     */
    public function benchCones(): void
    {
        LightConeSystem::cones($this->world, $this->camera);
    }
}
