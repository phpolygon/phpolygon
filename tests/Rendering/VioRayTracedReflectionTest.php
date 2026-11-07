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
 * Ray-traced reflections (shader plan 10.1): a mirror floor shows the red box standing
 * on it once reflection rays hit the box; without them it shows only the sky probe.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRayTracedReflectionTest extends TestCase
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
        MeshRegistry::register('floor', BoxMesh::generate(20.0, 0.1, 20.0));
        MeshRegistry::register('box', BoxMesh::generate(2.0, 3.0, 2.0));
        MaterialRegistry::register('mirror', new Material(albedo: new Color(0.9, 0.9, 0.9), roughness: 0.05, metallic: 1.0));
        MaterialRegistry::register('red', new Material(albedo: new Color(0.9, 0.05, 0.05), roughness: 0.8, useEnvironmentMap: false));
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

    public function testTheMirrorFloorShowsTheBox(): void
    {
        $plain = self::floorRedness($this->render(false));
        $rt = self::floorRedness($this->render(true));
        self::assertGreaterThan($plain + 8.0, $rt, "reflection rays bring the red box into the floor (plain {$plain}, rt {$rt})");
    }

    /** Mean of red minus green over the lower third (the floor in front of the box). */
    private static function floorRedness(string $img): float
    {
        $sum = 0.0;
        $n = 0;
        for ($y = 44; $y < self::SIZE; $y++) {
            for ($x = 16; $x < 48; $x++) {
                $o = ($y * self::SIZE + $x) * 4;
                $sum += ord($img[$o]) - ord($img[$o + 1]);
                $n++;
            }
        }
        return $sum / $n;
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
            rayTracedReflections: $rt,
        ));
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 1.2, 7.0), new Vec3(0.0, 0.5, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(1.1, 1.0, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.5));
        $list->add(new SetDirectionalLight(new Vec3(-0.3, -1.0, -0.4), new Color(1.0, 1.0, 1.0), 1.2));
        $list->add(new DrawMesh('floor', 'mirror', Mat4::translation(0.0, -0.05, 0.0)));
        $list->add(new DrawMesh('box', 'red', Mat4::translation(0.0, 1.5, 0.0)));
        $renderer->renderToImage($list, self::SIZE, self::SIZE);

        return $renderer->renderToImage($list, self::SIZE, self::SIZE);
    }
}
