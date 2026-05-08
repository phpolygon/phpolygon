<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneBuilder;

/**
 * Workload-representative scene used by GraphicsAutoTuner for first-launch
 * calibration and the recalibrate-now button.
 *
 * Composition:
 *   - 200 instanced "buildings" (box meshes) on a 14 x 14 grid
 *   - 50 free-floating spheres / cylinders sampled from the same registry
 *   - One ground plane large enough to fill the visible area
 *   - One directional light + a 90 deg orbit camera (driven by the engine
 *     loop, not by this Scene; the auto-tuner advances the world clock)
 *
 * All meshes / materials are registered the first time the scene loads;
 * subsequent loads reuse them.
 */
final class BenchmarkScene extends Scene
{
    public const BUILDING_MESH_ID = '_phpolygon_bench_building';
    public const GROUND_MESH_ID = '_phpolygon_bench_ground';
    public const SPHERE_MESH_ID = '_phpolygon_bench_sphere';
    public const CYLINDER_MESH_ID = '_phpolygon_bench_cylinder';

    public const MAT_BUILDING = '_phpolygon_bench_mat_building';
    public const MAT_GROUND = '_phpolygon_bench_mat_ground';
    public const MAT_PROP_A = '_phpolygon_bench_mat_prop_a';
    public const MAT_PROP_B = '_phpolygon_bench_mat_prop_b';

    public function getName(): string
    {
        return 'PHPolygon Benchmark Scene';
    }

    public function build(SceneBuilder $b): void
    {
        $this->registerMeshesAndMaterials();

        // Ground plane: 200 x 200 units. The auto-tuner's view-distance steps
        // (200 -> 75) all comfortably stay inside this footprint.
        $b->entity('Ground')
            ->with(new Transform3D(
                position: new Vec3(0.0, 0.0, 0.0),
                scale: new Vec3(1.0, 1.0, 1.0),
            ))
            ->with(new MeshRenderer(self::GROUND_MESH_ID, self::MAT_GROUND, castShadows: false));

        // 200 buildings on a 14 x 14 grid (196 cells; the corners are dropped
        // to leave room for the camera dolly path). Heights vary by index so
        // the depth range exercises shadow and fog.
        $count = 0;
        $spacing = 8.0;
        for ($z = -7; $z < 7 && $count < 200; $z++) {
            for ($x = -7; $x < 7 && $count < 200; $x++) {
                if (abs($x) === 7 && abs($z) === 7) {
                    continue;
                }
                $h = 4.0 + (($x * 13 + $z * 7) % 6);
                $b->entity('Building_' . $count)
                    ->with(new Transform3D(
                        position: new Vec3($x * $spacing, $h * 0.5, $z * $spacing),
                        scale: new Vec3(1.0, $h, 1.0),
                    ))
                    ->with(new MeshRenderer(self::BUILDING_MESH_ID, self::MAT_BUILDING));
                $count++;
            }
        }

        // 50 free spheres / cylinders scattered around the dolly path, far
        // enough out that shadow + view-distance both have to do work.
        for ($i = 0; $i < 50; $i++) {
            $angle = ($i / 50.0) * M_PI * 2.0;
            $radius = 12.0 + ($i % 5) * 4.0;
            $px = cos($angle) * $radius;
            $pz = sin($angle) * $radius;
            $py = 1.5 + ($i % 4) * 0.6;
            $useSphere = ($i % 2) === 0;
            $b->entity('Prop_' . $i)
                ->with(new Transform3D(position: new Vec3($px, $py, $pz)))
                ->with(new MeshRenderer(
                    $useSphere ? self::SPHERE_MESH_ID : self::CYLINDER_MESH_ID,
                    $useSphere ? self::MAT_PROP_A : self::MAT_PROP_B,
                ));
        }

        // Orbit camera: starts at a fixed pose; tests / first-launch flow
        // can rotate it around the origin during measurement.
        $b->entity('BenchCamera')
            ->with(new Transform3D(
                position: new Vec3(0.0, 8.0, 30.0),
                rotation: Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), -0.25),
            ))
            ->with(new Camera3DComponent(
                fov: 60.0,
                near: 0.5,
                far: 200.0,
            ));

        // Sun: angled so the shadow map covers most of the visible scene.
        $b->entity('BenchSun')
            ->with(new Transform3D())
            ->with(new DirectionalLight(
                direction: new Vec3(-0.4, -1.0, -0.3),
                color: new Color(1.0, 0.96, 0.88),
                intensity: 1.0,
            ));
    }

    private function registerMeshesAndMaterials(): void
    {
        if (!MeshRegistry::has(self::BUILDING_MESH_ID)) {
            MeshRegistry::register(self::BUILDING_MESH_ID, BoxMesh::generate(1.0, 1.0, 1.0));
        }
        if (!MeshRegistry::has(self::GROUND_MESH_ID)) {
            MeshRegistry::register(self::GROUND_MESH_ID, PlaneMesh::generate(200.0, 200.0));
        }
        if (!MeshRegistry::has(self::SPHERE_MESH_ID)) {
            MeshRegistry::register(self::SPHERE_MESH_ID, SphereMesh::generate(0.6, 12, 16));
        }
        if (!MeshRegistry::has(self::CYLINDER_MESH_ID)) {
            MeshRegistry::register(self::CYLINDER_MESH_ID, CylinderMesh::generate(0.5, 1.4, 16));
        }

        if (!MaterialRegistry::has(self::MAT_BUILDING)) {
            MaterialRegistry::register(self::MAT_BUILDING, new Material(
                albedo: new Color(0.65, 0.62, 0.58),
                roughness: 0.7,
            ));
        }
        if (!MaterialRegistry::has(self::MAT_GROUND)) {
            MaterialRegistry::register(self::MAT_GROUND, new Material(
                albedo: new Color(0.32, 0.35, 0.30),
                roughness: 0.85,
            ));
        }
        if (!MaterialRegistry::has(self::MAT_PROP_A)) {
            MaterialRegistry::register(self::MAT_PROP_A, new Material(
                albedo: new Color(0.85, 0.55, 0.25),
                roughness: 0.5,
                metallic: 0.1,
            ));
        }
        if (!MaterialRegistry::has(self::MAT_PROP_B)) {
            MaterialRegistry::register(self::MAT_PROP_B, new Material(
                albedo: new Color(0.30, 0.45, 0.85),
                roughness: 0.4,
                metallic: 0.3,
            ));
        }
    }
}
