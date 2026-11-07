<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
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
use PHPolygon\Rendering\Hlod\HlodBuilder;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * HLOD (shader plan 9.1): from beyond the switch distance a block of 200 placed meshes
 * and its two simplified proxies (one per material) look alike.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioHlodRenderTest extends TestCase
{
    public function testTheProxiesLookLikeTheBlockFromAfar(): void
    {
        $ctx = VioTestContext::create(160, 100, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            MeshRegistry::clear();
            MaterialRegistry::clear();
            MeshRegistry::register('ball', SphereMesh::generate(0.4, 24, 32));
            MeshRegistry::register('crate', BoxMesh::generate(1.0, 1.0, 1.0));
            MaterialRegistry::register('red', new Material(albedo: new Color(0.7, 0.15, 0.1), roughness: 0.8));
            MaterialRegistry::register('wood', new Material(albedo: new Color(0.5, 0.35, 0.2), roughness: 0.9));
            $draws = [];
            for ($x = 0; $x < 10; $x++) {
                for ($z = 0; $z < 10; $z++) {
                    $draws[] = ['ball', 'red', Mat4::translation($x * 2.0, 0.4, $z * 2.0)];
                    $draws[] = ['crate', 'wood', Mat4::translation($x * 2.0 + 1.0, 0.5, $z * 2.0)->multiply(Mat4::rotationY(0.3 * $x))];
                }
            }
            $cell = HlodBuilder::build('block', $draws, 0.3, 40.0);
            $camera = new Vec3(9.0, 25.0, 70.0);
            self::assertTrue($cell->useProxy($camera));

            $renderer = new VioRenderer3D($ctx, 160, 100);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false));
            $real = $this->scene($camera);
            foreach ($draws as [$mesh, $material, $model]) {
                $real->add(new DrawMesh($mesh, $material, $model));
            }
            $proxy = $this->scene($camera);
            foreach ($cell->proxyDraws() as $d) {
                $proxy->add($d);
            }
            $a = $renderer->renderToImage($real, 160, 100, new Color(0.5, 0.6, 0.75));
            $b = $renderer->renderToImage($proxy, 160, 100, new Color(0.5, 0.6, 0.75));
            $sum = 0;
            $off = 0;
            for ($i = 0, $n = strlen($a); $i < $n; $i++) {
                if (($i & 3) === 3) {
                    continue;
                }
                $d = abs(ord($a[$i]) - ord($b[$i]));
                $sum += $d;
                $off += $d > 40 ? 1 : 0;
            }
            $mean = $sum / (160 * 100 * 3);
            fprintf(STDERR, "[hlod] %d -> %d triangles, mean difference %.2f, channels off by > 40: %d\n", $cell->sourceTriangles, $cell->proxyTriangles, $mean, $off);
            self::assertLessThan(2.5, $mean);
            self::assertLessThan(160 * 100 * 3 / 50, $off);
        } finally {
            MaterialRegistry::clear();
            MeshRegistry::clear();
            vio_destroy($ctx);
        }
    }

    private function scene(Vec3 $camera): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::lookAt($camera, new Vec3(9.0, 0.0, 9.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(30.0), 1.6, 0.5, 300.0)));
        $list->add(new SetAmbientLight(new Color(0.8, 0.85, 1.0), 0.5));
        $list->add(new SetDirectionalLight((new Vec3(-0.4, -1.0, -0.3))->normalize(), new Color(1.0, 0.95, 0.85), 1.4));
        return $list;
    }
}
