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
use PHPolygon\Rendering\Command\AddRectLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The rectangular area light on the GPU: a long, narrow panel (4 m along X, 0.2 m
 * along Z) 1.5 m above a white floor, shining down, the floor seen from above. Its
 * light must reach farther along the panel than across it (a point light would be
 * round), be brightest under the middle, and leave the floor dark when the panel is
 * turned to face up (one-sided).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRectLightTest extends TestCase
{
    private const SIZE = 64;
    private const HALF = 4.0;

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
        MeshRegistry::register('floor', BoxMesh::generate(20.0, 0.1, 20.0));
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

    public function testALongPanelLightsALongPatch(): void
    {
        // axisX x axisY = (2,0,0) x (0,0,0.1) points down: the panel faces the floor.
        $image = $this->render(new Vec3(2.0, 0.0, 0.0), new Vec3(0.0, 0.0, 0.1));
        $centre = $this->red($image, 0.0, 0.0);
        $along = $this->red($image, 2.2, 0.0);
        $across = $this->red($image, 0.0, 2.2);

        self::assertGreaterThan(40, $centre, "the panel lights the floor under it ({$centre})");
        self::assertGreaterThan($along, $centre, "brightest under the middle ({$centre} vs {$along})");
        self::assertGreaterThan($across * 1.3 + 4, $along, "farther along the panel than across ({$along} vs {$across})");
    }

    public function testThePanelIsOneSided(): void
    {
        // Swapped axes: the normal points up, away from the floor.
        $image = $this->render(new Vec3(0.0, 0.0, 0.1), new Vec3(2.0, 0.0, 0.0));
        $centre = $this->red($image, 0.0, 0.0);

        self::assertLessThan(6, $centre, "a panel facing away leaves the floor dark ({$centre})");
    }

    private function render(Vec3 $axisX, Vec3 $axisY): string
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
            Mat4::lookAt(new Vec3(0.0, 20.0, 0.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)),
            Mat4::orthographic(-self::HALF, self::HALF, -self::HALF, self::HALF, 1.0, 50.0),
        ));
        $list->add(new SetAmbientLight(new Color(0.0, 0.0, 0.0), 0.0));
        $list->add(new AddRectLight(new Vec3(0.0, 1.5, 0.0), $axisX, $axisY, new Color(1.0, 1.0, 1.0), 0.4, 12.0));
        $list->add(new DrawMesh('floor', 'white', Mat4::translation(0.0, -0.05, 0.0)));

        return $renderer->renderToImage($list, self::SIZE, self::SIZE);
    }

    private function red(string $image, float $x, float $z): int
    {
        $col = min(self::SIZE - 1, (int) (($x + self::HALF) / (2.0 * self::HALF) * self::SIZE));
        $row = min(self::SIZE - 1, (int) (($z + self::HALF) / (2.0 * self::HALF) * self::SIZE));

        return ord($image[($row * self::SIZE + $col) * 4]);
    }
}
