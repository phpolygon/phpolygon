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
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Micro-shadows (GraphicsSettings::$microShadows): under a low sun the joints of a
 * cobblestone floor - its occluded cavities - get darker, while the tops of the
 * stones keep their light. The setting survives saving.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioMicroShadowTest extends TestCase
{
    private const W = 96;
    private const H = 64;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(10.0, 0.1, 10.0));
        MaterialRegistry::register('cobble', new Material(albedo: new Color(0.55, 0.52, 0.48), roughness: 0.8, useEnvironmentMap: false,
            normalPattern: 'cobblestone', normalScale: 1.5, normalIntensity: 1.5, cavity: 1.0));
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
    }

    public function testTheSettingIsSaved(): void
    {
        $this->assertTrue(GraphicsSettings::fromJson((new GraphicsSettings())->with(microShadows: true)->toJson())->microShadows);
        $this->assertFalse((new GraphicsSettings())->microShadows);
    }

    public function testCavitiesDarkenUnderALowSunAndTopsKeepTheirLight(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            $renderer = new VioRenderer3D($ctx, self::W, self::H);
            $shot = function (bool $on) use ($renderer): array {
                $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false, microShadows: $on));
                $list = new RenderCommandList();
                $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 3.0, 2.0), new Vec3(0.0, 0.0, -0.5), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 50.0)));
                $list->add(new SetAmbientLight(new Color(0.55, 0.62, 0.8), 0.45));
                $list->add(new SetDirectionalLight(new Vec3(-0.9, -0.25, -0.3), new Color(1.0, 0.9, 0.75), 2.2));
                $list->add(new DrawMesh('floor', 'cobble', Mat4::identity()));
                $img = $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0));
                $l = [];
                for ($i = 0, $n = strlen($img); $i < $n; $i += 4) {
                    $l[] = ord($img[$i + 1]);
                }
                sort($l);
                $k = intdiv(count($l), 5);
                return [array_sum(array_slice($l, 0, $k)) / $k, array_sum(array_slice($l, -$k)) / $k];
            };
            [$darkOff, $brightOff] = $shot(false);
            [$darkOn, $brightOn] = $shot(true);
            fprintf(STDERR, "[micro shadows] darkest fifth %.1f -> %.1f, brightest fifth %.1f -> %.1f\n", $darkOff, $darkOn, $brightOff, $brightOn);
            self::assertLessThan($darkOff - 8.0, $darkOn, 'the joints darken');
            self::assertEqualsWithDelta($brightOff, $brightOn, ($brightOff - $darkOff) * 0.25, 'the stone tops keep most of their light');
        } finally {
            vio_destroy($ctx);
        }
    }
}
