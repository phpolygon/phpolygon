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
use PHPolygon\Rendering\Command\SetGroundWetness;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Rain on glass on the GPU: a clear pane 2 m in front of the camera over a dark
 * backdrop. Wet, drops show on it (many pixels change); dry, and with the pane
 * below the dry line, it stays as it is.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioGlassRainTest extends TestCase
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
        MeshRegistry::register('pane', BoxMesh::generate(2.0, 2.0, 0.02));
        MeshRegistry::register('back', BoxMesh::generate(10.0, 10.0, 0.1));
        MaterialRegistry::register('glass', new Material(albedo: new Color(0.8, 0.9, 1.0), roughness: 0.05, alpha: 0.25, useEnvironmentMap: false));
        MaterialRegistry::register('back', new Material(albedo: new Color(0.05, 0.05, 0.05), roughness: 1.0, useEnvironmentMap: false));
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

    public function testDropsShowOnAWetPaneOnly(): void
    {
        $dry = $this->render(0.0, null);
        $wet = $this->render(1.0, null);
        $sunk = $this->render(1.0, 50.0);

        self::assertGreaterThan(40, self::changed($dry, $wet), 'drops on the wet pane');
        self::assertLessThan(5, self::changed($dry, $sunk), 'below the dry line no drops');
    }

    private static function changed(string $a, string $b): int
    {
        $n = 0;
        for ($i = 0; $i < strlen($a); $i += 4) {
            if (abs(ord($a[$i]) - ord($b[$i])) + abs(ord($a[$i + 1]) - ord($b[$i + 1])) + abs(ord($a[$i + 2]) - ord($b[$i + 2])) > 12) {
                $n++;
            }
        }

        return $n;
    }

    private function render(float $wetness, ?float $dryBelow): string
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
            Mat4::lookAt(new Vec3(0.0, 1.0, 2.0), new Vec3(0.0, 1.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(0.9, 1.0, 0.1, 50.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
        $list->add(new SetGroundWetness($wetness, 0.0, $dryBelow));
        $list->add(new DrawMesh('back', 'back', Mat4::translation(0.0, 1.0, -3.0)));
        $list->add(new DrawMesh('pane', 'glass', Mat4::translation(0.0, 1.0, 0.0)));

        return $renderer->renderToImage($list, self::SIZE, self::SIZE);
    }
}
