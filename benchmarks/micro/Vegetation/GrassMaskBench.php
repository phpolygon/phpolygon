<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\Vegetation;

use PHPolygon\Vegetation\GrassGroundRaster;
use PHPolygon\Vegetation\GrassMask;

/**
 * One tuft candidate against the mask and the ground: walking all shapes
 * (legacy, {@see GrassMask::factorExhaustive()}) against the tiles of
 * {@see GrassMask::factor()}, at the size where tiles start and on a crowded mask,
 * and a read of the {@see GrassGroundRaster}. Tiles and raster are warm.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/Vegetation/GrassMaskBench.php --report=aggregate
 */
final class GrassMaskBench
{
    private GrassMask $threshold;
    private GrassMask $crowded;
    private GrassGroundRaster $raster;

    /** @var list<array{0: float, 1: float}> */
    private array $points = [];

    public function setUp(): void
    {
        // 16 edges and areas: one path and two discs, just where tiles start.
        $this->threshold = (new GrassMask())
            ->excludePath([[-40.0, -10.0], [-30.0, 5.0], [-20.0, -5.0], [-10.0, 10.0], [0.0, 0.0], [10.0, 8.0], [20.0, -6.0], [30.0, 4.0], [40.0, -8.0], [50.0, 3.0], [60.0, 0.0], [70.0, 5.0], [80.0, 0.0]], 1.2, 0.8)
            ->excludeCircle(15.0, 20.0, 5.0)
            ->excludeCircle(-25.0, -20.0, 4.0);

        $this->crowded = new GrassMask();
        mt_srand(11);
        for ($i = 0; $i < 60; $i++) {
            $x = mt_rand(-40, 40);
            $z = mt_rand(-40, 40);
            $a = mt_rand(0, 628) / 100.0;
            $line = [];
            for ($j = 0; $j < 30; $j++) {
                $line[] = [$x + $j * 3.0 * cos($a), $z + $j * 3.0 * sin($a)];
            }
            $this->crowded->excludePath($line, 0.9, 0.7);
        }
        $ring = [];
        for ($j = 0; $j < 300; $j++) {
            $a = $j / 300.0 * 2.0 * M_PI;
            $ring[] = [cos($a) * (50.0 + 9.0 * sin($a * 9.0)), sin($a) * (50.0 + 9.0 * sin($a * 9.0))];
        }
        $this->crowded->excludePolygon($ring, 0.8);

        $this->raster = GrassGroundRaster::fromHeight(static fn(float $x, float $z): ?float => 0.2 * sin($x * 0.05));

        $this->points = [];
        for ($i = 0; $i < 1000; $i++) {
            $this->points[] = [mt_rand(0, 12000) / 1000.0, mt_rand(0, 12000) / 1000.0];
        }
        foreach ($this->points as [$x, $z]) {
            $this->threshold->factor($x, $z);
            $this->crowded->factor($x, $z);
            $this->raster->heightAt($x, $z);
        }
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(20)
     * @Iterations(3)
     */
    public function benchThresholdExhaustive(): void
    {
        foreach ($this->points as [$x, $z]) {
            $this->threshold->factorExhaustive($x, $z);
        }
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(20)
     * @Iterations(3)
     */
    public function benchThresholdTiled(): void
    {
        foreach ($this->points as [$x, $z]) {
            $this->threshold->factor($x, $z);
        }
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(5)
     * @Iterations(3)
     */
    public function benchCrowdedExhaustive(): void
    {
        foreach ($this->points as [$x, $z]) {
            $this->crowded->factorExhaustive($x, $z);
        }
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(20)
     * @Iterations(3)
     */
    public function benchCrowdedTiled(): void
    {
        foreach ($this->points as [$x, $z]) {
            $this->crowded->factor($x, $z);
        }
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(20)
     * @Iterations(3)
     */
    public function benchRasterHeight(): void
    {
        foreach ($this->points as [$x, $z]) {
            $this->raster->heightAt($x, $z);
        }
    }
}
