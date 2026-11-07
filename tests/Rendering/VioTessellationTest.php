<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Ocean\OceanSpectrum;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Tessellation (shader plan 10.2, Material::$tessellation): a sea of two triangles
 * cannot carry the ocean spectrum's waves - with tessellation the vertex stage runs on
 * the generated vertices and the waves appear, like on a finely meshed sea; single and
 * instanced.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioTessellationTest extends TestCase
{
    public function testTessellationBringsTheWavesToACoarseSurface(): void
    {
        $ctx = VioTestContext::create(128, 80, ['shader_model' => 6, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            if (!defined('VIO_FEATURE_TESSELLATION') || !vio_supports_feature($ctx, VIO_FEATURE_TESSELLATION)) {
                $this->markTestSkipped('no tessellation on this backend');
            }
            MeshRegistry::clear();
            MaterialRegistry::clear();
            $renderer = new VioRenderer3D($ctx, 128, 80);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false));
            $renderer->warmShaders();
            if ((ProcModeShaderRegistry::textureUnits() ?? 0) <= 18) {
                $this->markTestSkipped('fewer than 19 texture units: no ocean volume in the vertex stage');
            }
            $renderer->registerGpuTexture('ocean', OceanSpectrum::upload($ctx, OceanSpectrum::bake(32, 32.0, 8.0, 0.4, 8, 6.0)));
            MeshRegistry::register('coarse', PlaneMesh::generate(60.0, 60.0, 1));
            MeshRegistry::register('fine', PlaneMesh::generate(60.0, 60.0, 160));
            $sea = static fn (float $tess): Material => new Material(
                albedo: new Color(0.05, 0.25, 0.35),
                roughness: 0.3,
                oceanTexture: 'ocean',
                oceanPatch: 32.0,
                oceanAmplitude: 1.2,
                oceanLoop: 6.0,
                tessellation: $tess,
                tessNear: 10.0,
                tessFar: 120.0,
            );
            MaterialRegistry::register('plain', $sea(0.0));
            MaterialRegistry::register('tess', $sea(48.0));
            $renderer->setAnimationTime(1.0);
            $shot = function (object $draw) use ($renderer): string {
                $list = new RenderCommandList();
                $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 4.0, 18.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(50.0), 1.6, 0.1, 200.0)));
                $list->add(new SetAmbientLight(new Color(0.8, 0.85, 1.0), 0.5));
                $list->add(new SetDirectionalLight((new Vec3(-0.3, -0.6, -0.7))->normalize(), new Color(1.0, 0.95, 0.85), 1.6));
                $list->add($draw);
                return $renderer->renderToImage($list, 128, 80, new Color(0.6, 0.7, 0.85));
            };
            $diff = static function (string $a, string $b): float {
                $s = 0;
                for ($i = 0, $n = strlen($a); $i < $n; $i += 4) {
                    $s += abs(ord($a[$i]) - ord($b[$i])) + abs(ord($a[$i + 1]) - ord($b[$i + 1])) + abs(ord($a[$i + 2]) - ord($b[$i + 2]));
                }
                return $s / (strlen($a) / 4 * 3);
            };
            $fine = $shot(new DrawMesh('fine', 'plain', Mat4::identity()));
            $coarse = $shot(new DrawMesh('coarse', 'plain', Mat4::identity()));
            $tess = $shot(new DrawMesh('coarse', 'tess', Mat4::identity()));
            $tessInstanced = $shot(new DrawMeshInstanced('coarse', 'tess', [Mat4::identity()]));
            fprintf(STDERR, "[tess] vs the fine sea: coarse %.2f, tessellated %.2f, tessellated instanced %.2f\n", $diff($fine, $coarse), $diff($fine, $tess), $diff($fine, $tessInstanced));
            self::assertLessThan($diff($fine, $coarse) * 0.6, $diff($fine, $tess), 'tessellated, the coarse sea comes close to the fine one');
            self::assertLessThan($diff($fine, $coarse) * 0.6, $diff($fine, $tessInstanced), 'instanced too');
        } finally {
            MaterialRegistry::clear();
            MeshRegistry::clear();
            vio_destroy($ctx);
        }
    }
}
