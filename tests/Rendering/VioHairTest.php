<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\HairMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Strand hair (shader plan 10.2): HairMesh grows crossed-ribbon strands from a scalp,
 * bending down under gravity; Material::$hair shades them as thin cylinders, so the
 * hanging strands stay lit by a light from above instead of going dark like a
 * surface whose normal points down.
 */
final class VioHairTest extends TestCase
{
    public function testStrandsGrowFromTheScalpAndHangDown(): void
    {
        $scalp = SphereMesh::generate(0.1, 16, 24);
        $hair = HairMesh::generate($scalp, 200, 0.25, 0.006, 0.3, 6, 0.7, 3);
        self::assertSame(200 * 2 * 6 * 2, $hair->triangleCount());
        $perRibbon = 7 * 2;
        $rootsOnScalp = 0;
        $tipsLower = 0;
        for ($s = 0; $s < 200; $s++) {
            $first = $s * 2 * $perRibbon;
            $rx = ($hair->vertices[$first * 3] + $hair->vertices[($first + 1) * 3]) / 2;
            $ry = ($hair->vertices[$first * 3 + 1] + $hair->vertices[($first + 1) * 3 + 1]) / 2;
            $rz = ($hair->vertices[$first * 3 + 2] + $hair->vertices[($first + 1) * 3 + 2]) / 2;
            $rootsOnScalp += abs(sqrt($rx * $rx + $ry * $ry + $rz * $rz) - 0.1) < 0.01 ? 1 : 0;
            $tip = $first + 12;
            $tipsLower += $hair->vertices[$tip * 3 + 1] < $ry ? 1 : 0;
        }
        self::assertSame(200, $rootsOnScalp);
        self::assertGreaterThan(150, $tipsLower, 'gravity pulls most strands down');
        $h = HairMesh::generate($scalp, 10, 0.2, 0.01, seed: 5);
        self::assertSame($h->vertices, HairMesh::generate($scalp, 10, 0.2, 0.01, seed: 5)->vertices, 'deterministic per seed');
    }

    #[RequiresPhpExtension('vio')]
    #[Group('native-gpu')]
    public function testHairShadingKeepsHangingStrandsLit(): void
    {
        $ctx = VioTestContext::create(160, 160, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            MeshRegistry::clear();
            MaterialRegistry::clear();
            MeshRegistry::register('hair', HairMesh::generate(SphereMesh::generate(0.3, 16, 24), 1500, 0.5, 0.012, 0.2, 6, 0.8, 7));
            $renderer = new VioRenderer3D($ctx, 160, 160);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false));
            $mean = [];
            foreach (['surface' => false, 'hair' => true] as $label => $hair) {
                MaterialRegistry::register('strands', new Material(albedo: new Color(0.55, 0.35, 0.15), roughness: 0.4, useEnvironmentMap: false, twoSided: true, hair: $hair));
                $list = new RenderCommandList();
                $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.0, 2.2), new Vec3(0.0, -0.1, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(45.0), 1.0, 0.1, 20.0)));
                $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.15));
                $list->add(new SetDirectionalLight((new Vec3(0.2, -1.0, -0.5))->normalize(), new Color(1.0, 1.0, 1.0), 2.0));
                $list->add(new DrawMesh('hair', 'strands', Mat4::identity()));
                $img = $renderer->renderToImage($list, 160, 160, new Color(0.0, 0.0, 0.0));
                $sum = 0;
                $n = 0;
                for ($p = 0; $p < 160 * 160; $p++) {
                    $l = ord($img[$p * 4]) + ord($img[$p * 4 + 1]) + ord($img[$p * 4 + 2]);
                    if ($l > 6) {
                        $sum += $l;
                        $n++;
                    }
                }
                self::assertGreaterThan(1500, $n, "{$label}: the hair covers the picture");
                $mean[$label] = $sum / max(1, $n);
            }
            fprintf(STDERR, "[hair] mean brightness of the strands: surface shading %.1f, hair shading %.1f\n", $mean['surface'], $mean['hair']);
            self::assertGreaterThan($mean['surface'] * 1.3, $mean['hair']);
        } finally {
            MaterialRegistry::clear();
            MeshRegistry::clear();
            vio_destroy($ctx);
        }
    }
}
