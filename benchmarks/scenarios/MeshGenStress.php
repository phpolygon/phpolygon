<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Scenarios;

use PHPolygon\Benchmarks\Scenario;
use PHPolygon\Engine;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Runtime\PerfProfiler;

/**
 * Stress-tests the procedural mesh generators. Runs ~30 generations per
 * frame across the three primitive types with varying parameters so the
 * per-call cost dominates the measurement. The generated meshes are
 * registered under predictable IDs so MeshRegistry doesn't grow unbounded.
 */
final class MeshGenStress implements Scenario
{
    private const PER_FRAME = 30;

    public function name(): string
    {
        return 'mesh-gen-stress';
    }

    public function setUp(Engine $engine): void
    {
    }

    public function tickFrame(Engine $engine, int $frame, float $dt): void
    {
        for ($i = 0; $i < self::PER_FRAME; $i++) {
            $key = ($i % 3);
            $w = 0.5 + ($i % 5) * 0.5;

            if ($key === 0) {
                PerfProfiler::begin('mesh.generate.box');
                $mesh = BoxMesh::generate($w, $w, $w);
                PerfProfiler::end();
                MeshRegistry::register("bench_box_{$i}", $mesh);
            } elseif ($key === 1) {
                PerfProfiler::begin('mesh.generate.cylinder');
                $mesh = CylinderMesh::generate(radius: $w * 0.5, height: $w, segments: 16);
                PerfProfiler::end();
                MeshRegistry::register("bench_cyl_{$i}", $mesh);
            } else {
                PerfProfiler::begin('mesh.generate.sphere');
                $mesh = SphereMesh::generate(radius: $w * 0.5, stacks: 12, slices: 16);
                PerfProfiler::end();
                MeshRegistry::register("bench_sph_{$i}", $mesh);
            }
        }
    }
}
