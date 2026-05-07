<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\Math;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Mat4 is the most-used hot-leaf math type in the engine. Every entity's
 * world matrix is a Mat4, every camera frame builds view + projection,
 * every DrawMesh command carries one. Watch these microseconds.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/Math --report=aggregate
 *   vendor/bin/phpbench run benchmarks/micro/Math --report=default --tag=baseline
 *   vendor/bin/phpbench run benchmarks/micro/Math --ref=baseline --report=aggregate
 */
final class Mat4Bench
{
    private Mat4 $a;
    private Mat4 $b;
    private Vec3 $point;

    public function setUp(): void
    {
        $this->a = Mat4::trs(
            new Vec3(1.0, 2.0, 3.0),
            Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), 0.5),
            new Vec3(1.5, 1.5, 1.5),
        );
        $this->b = Mat4::lookAt(
            new Vec3(0.0, 5.0, 10.0),
            new Vec3(0.0, 0.0, 0.0),
            new Vec3(0.0, 1.0, 0.0),
        );
        $this->point = new Vec3(1.0, 2.0, 3.0);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(10000)
     * @Iterations(5)
     */
    public function benchMultiply(): void
    {
        $this->a->multiply($this->b);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(10000)
     * @Iterations(5)
     */
    public function benchTransformPoint(): void
    {
        $this->a->transformPoint($this->point);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(10000)
     * @Iterations(5)
     */
    public function benchInverse(): void
    {
        $this->a->inverse();
    }

    /**
     * @Revs(10000)
     * @Iterations(5)
     */
    public function benchTrs(): void
    {
        Mat4::trs(
            new Vec3(1.0, 2.0, 3.0),
            Quaternion::identity(),
            new Vec3(1.0, 1.0, 1.0),
        );
    }
}
