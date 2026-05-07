<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\Math;

use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Quaternion ops are called per entity per frame for any animated rotation.
 */
final class QuaternionBench
{
    private Quaternion $a;
    private Quaternion $b;
    private Vec3 $vector;

    public function setUp(): void
    {
        $this->a = Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), 0.7);
        $this->b = Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), 0.4);
        $this->vector = new Vec3(1.0, 2.0, 3.0);
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
    public function benchSlerp(): void
    {
        $this->a->slerp($this->b, 0.5);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(10000)
     * @Iterations(5)
     */
    public function benchRotateVec3(): void
    {
        $this->a->rotateVec3($this->vector);
    }

    /**
     * @Revs(10000)
     * @Iterations(5)
     */
    public function benchFromAxisAngle(): void
    {
        Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), 0.5);
    }
}
