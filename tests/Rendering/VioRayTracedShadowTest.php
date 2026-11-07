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
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Ray-traced sun shadows (shader plan 10.1): with the shadow map off, a box above a
 * floor still shades the floor under it once the acceleration structure is built
 * (from the previous frame's draws); without ray tracing the floor stays lit.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRayTracedShadowTest extends TestCase
{
    private const SIZE = 64;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_model' => 6, 'headless_hardware' => true, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!defined('VIO_FEATURE_RAY_QUERY') || !vio_supports_feature($ctx, VIO_FEATURE_RAY_QUERY)) {
            vio_destroy($ctx);
            $this->markTestSkipped('no inline ray tracing on this device');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(10.0, 0.1, 10.0));
        MeshRegistry::register('box', BoxMesh::generate(1.5, 1.5, 1.5));
        MaterialRegistry::register('white', new Material(albedo: new Color(0.8, 0.8, 0.8), roughness: 1.0, useEnvironmentMap: false));
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testTheBoxShadesTheFloorWithoutAShadowMap(): void
    {
        $lit = $this->render(false);
        $rt = $this->render(true);
        $under = static fn (string $img): int => ord($img[(20 * self::SIZE + 32) * 4]);   // floor at z = -1.5: where the box shadow falls (light travels -y, -z)
        self::assertGreaterThan($under($rt) + 40, $under($lit), 'the ray-traced shadow darkens the floor under the box');
    }

    private function render(bool $rt): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
            rayTracedShadows: $rt,
        ));
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 9.0, 0.01), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)),
            Mat4::orthographic(-4.0, 4.0, -4.0, 4.0, 1.0, 30.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.15));
        $list->add(new SetDirectionalLight(new Vec3(0.0, -1.0, -0.6), new Color(1.0, 1.0, 1.0), 1.5));
        $list->add(new DrawMesh('floor', 'white', Mat4::translation(0.0, -0.05, 0.0)));
        $list->add(new DrawMesh('box', 'white', Mat4::translation(0.0, 2.5, 0.0)));
        $renderer->renderToImage($list, self::SIZE, self::SIZE);   // collects the scene

        return $renderer->renderToImage($list, self::SIZE, self::SIZE);
    }
}
