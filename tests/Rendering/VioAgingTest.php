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
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Weathering on the GPU (shader plan 5.2): a dark floor inside an aging zone of age 1
 * gathers light dust; a material with weathering 0 and the floor outside any zone
 * stay as they are.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioAgingTest extends TestCase
{
    private const SIZE = 64;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(10.0, 0.1, 10.0));
        MaterialRegistry::register('dark', new Material(albedo: new Color(0.08, 0.08, 0.08), roughness: 1.0, useEnvironmentMap: false));
        MaterialRegistry::register('immune', new Material(albedo: new Color(0.08, 0.08, 0.08), roughness: 1.0, useEnvironmentMap: false, weathering: 0.0));
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

    public function testDustSettlesInsideTheZone(): void
    {
        $old = $this->render('dark', 1);
        $new = $this->render('dark', 0);
        $immune = $this->render('immune', 1);

        self::assertGreaterThan(self::mean($new) + 15, self::mean($old), 'dust brightens the dark floor');
        self::assertEqualsWithDelta(self::mean($new), self::mean($immune), 2, 'weathering 0 keeps it clean');
    }

    private static function mean(string $image): float
    {
        $sum = 0;
        for ($i = 0; $i < strlen($image); $i += 4) {
            $sum += ord($image[$i]);
        }

        return $sum / (strlen($image) / 4);
    }

    private function render(string $material, int $zones): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 8.0, 0.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)),
            Mat4::orthographic(-4.0, 4.0, -4.0, 4.0, 1.0, 20.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
        $list->add(new SetShaderParameter('u_age_zone_count', $zones));
        $list->add(new SetShaderParameter('u_age_zones[0]', [0.0, 0.0, 0.0, 0.0]));
        $list->add(new SetShaderParameter('u_age_zones[1]', [20.0, 5.0, 20.0, 1.0]));
        $list->add(new DrawMesh('floor', $material, Mat4::translation(0.0, -0.05, 0.0)));

        return $renderer->renderToImage($list, self::SIZE, self::SIZE);
    }
}
