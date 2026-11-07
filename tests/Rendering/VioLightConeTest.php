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
use PHPolygon\Rendering\Command\AddSpotLight;
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
 * A visible light beam on the GPU: a narrow spot light shines from left to right
 * across the view, 3 m in front of a black wall it never reaches. With the air
 * scattering light (u_light_halo) the pixels along the beam come out brighter
 * than those above it; without, the wall stays black everywhere.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioLightConeTest extends TestCase
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
        MeshRegistry::register('wall', BoxMesh::generate(40.0, 40.0, 0.1));
        MaterialRegistry::register('black', new Material(albedo: new Color(0.0, 0.0, 0.0), roughness: 1.0, useEnvironmentMap: false));
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

    public function testTheBeamLightsTheAir(): void
    {
        $lit = $this->render(0.05);
        $clear = $this->render(0.0);

        $beam = $this->red($lit, 32, 32);
        $above = $this->red($lit, 32, 8);
        self::assertGreaterThan($above + 20, $beam, "the beam glows in the air ({$beam} vs {$above} above)");
        self::assertLessThan(5, $this->red($clear, 32, 32), 'clear air shows no beam');
    }

    private function red(string $image, int $x, int $y): int
    {
        return ord($image[($y * self::SIZE + $x) * 4]);
    }

    private function render(float $halo): string
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
            Mat4::lookAt(new Vec3(0.0, 0.0, 6.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(1.2, 1.0, 0.1, 50.0),
        ));
        $list->add(new SetAmbientLight(new Color(0.0, 0.0, 0.0), 0.0));
        $list->add(new SetShaderParameter('u_light_halo', $halo));
        // From the left edge to the right, 3 m in front of the wall, aimed along +X.
        $list->add(new AddSpotLight(new Vec3(-8.0, 0.0, 0.0), new Vec3(1.0, 0.0, 0.0), new Color(1.0, 1.0, 1.0), 20.0, 30.0, 0.08, 0.3));
        $list->add(new DrawMesh('wall', 'black', Mat4::translation(0.0, 0.0, -3.0)));

        return $renderer->renderToImage($list, self::SIZE, self::SIZE);
    }
}
