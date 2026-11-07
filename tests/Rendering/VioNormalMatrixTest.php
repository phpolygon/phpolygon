<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
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
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * A rotated single draw is lit like the same draw instanced: u_normal_matrix carries
 * transpose(inverse(model)). It used to carry inverse(model), which turned the normals
 * of every rotated non-instanced object the other way (a box turned a quarter showed
 * the face towards the camera black).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioNormalMatrixTest extends TestCase
{
    public function testARotatedSingleDrawIsLitLikeTheInstancedOne(): void
    {
        $ctx = VioTestContext::create(64, 64, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            MeshRegistry::clear();
            MaterialRegistry::clear();
            MeshRegistry::register('box', BoxMesh::generate(1.0, 2.0, 0.4));
            MaterialRegistry::register('box', new Material(albedo: new Color(0.7, 0.15, 0.1), roughness: 1.0, useEnvironmentMap: false));
            $renderer = new VioRenderer3D($ctx, 64, 64);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false, hdr: false));
            foreach ([45, 90, 160, 270] as $deg) {
                // Scale and translation too: the inverse must not be a plain rotation.
                $model = Mat4::translation(0.2, 0.0, 0.0)->multiply(Mat4::rotationY(deg2rad($deg)))->multiply(Mat4::scaling(1.0, 1.0, 1.6));
                $single = $renderer->renderToImage($this->scene(new DrawMesh('box', 'box', $model)), 64, 64, new Color(0.0, 0.0, 0.3));
                $instanced = $renderer->renderToImage($this->scene(new DrawMeshInstanced('box', 'box', [$model])), 64, 64, new Color(0.0, 0.0, 0.3));
                $o = (32 * 64 + 32) * 4;
                for ($c = 0; $c < 3; $c++) {
                    self::assertEqualsWithDelta(ord($instanced[$o + $c]), ord($single[$o + $c]), 4, "yaw {$deg}, channel {$c}");
                }
            }
        } finally {
            MaterialRegistry::clear();
            MeshRegistry::clear();
            vio_destroy($ctx);
        }
    }

    private function scene(object $draw): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.5, 4.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(40.0), 1.0, 0.1, 10.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.4));
        $list->add(new SetDirectionalLight((new Vec3(-0.5, -0.6, -0.6))->normalize(), new Color(1.0, 1.0, 1.0), 1.2));
        $list->add($draw);
        return $list;
    }
}
