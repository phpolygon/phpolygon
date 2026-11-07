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
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Froxel fog (GraphicsSettings::$froxelFog): at night a lamp hanging in the air gets
 * a halo of lit fog around it - over the sky as well - while the dark sky far from
 * it stays dark; the setting survives saving.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioFroxelFogTest extends TestCase
{
    private const W = 96;
    private const H = 64;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('ground', BoxMesh::generate(200.0, 0.2, 200.0));
        MaterialRegistry::register('ground', new Material(albedo: new Color(0.3, 0.3, 0.3), roughness: 0.9, useEnvironmentMap: false));
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
    }

    public function testTheSettingIsSaved(): void
    {
        $on = (new GraphicsSettings())->with(volumetricFog: true, froxelFog: true);
        $this->assertTrue(GraphicsSettings::fromJson($on->toJson())->froxelFog);
        $this->assertFalse((new GraphicsSettings())->froxelFog);
    }

    public function testALampGetsAHaloOfLitFog(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            $renderer = new VioRenderer3D($ctx, self::W, self::H);
            $shot = function (bool $froxel) use ($renderer): string {
                $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Medium, antiAliasing: AntiAliasing::Off, bloom: false, volumetricFog: true, froxelFog: $froxel));
                // Two frames: the scene-depth copy the fog reads exists from the second on.
                $renderer->renderToImage($this->scene(), self::W, self::H, new Color(0.0, 0.0, 0.0));
                return $renderer->renderToImage($this->scene(), self::W, self::H, new Color(0.0, 0.0, 0.0));
            };
            $off = $shot(false);
            $on = $shot(true);
            if (!$renderer->froxelFogActive()) {
                $this->markTestSkipped('froxel fog needs a scene target whose depth can be sampled (the per-pixel march stays)');
            }
            // The lamp hangs in the middle of the upper half, against the sky.
            $near = $this->mean($on, 40, 56, 10, 22) - $this->mean($off, 40, 56, 10, 22);
            $far = $this->mean($on, 0, 12, 0, 10) - $this->mean($off, 0, 12, 0, 10);
            fprintf(STDERR, "[froxel] halo +%.1f near the lamp, +%.1f in the far corner\n", $near, $far);
            self::assertGreaterThan(8.0, $near, 'lit fog around the lamp');
            self::assertLessThan($near / 3.0, $far, 'the sky far from the lamp stays dark');
        } finally {
            vio_destroy($ctx);
        }
    }

    private function scene(): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 2.0, 8.0), new Vec3(0.0, 3.0, -10.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(70.0), self::W / self::H, 0.1, 400.0)));
        $list->add(new SetSky(sunDirection: new Vec3(0.3, 0.6, -0.7), sunColor: new Color(0.4, 0.45, 0.6), sunIntensity: 0.0,
            zenithColor: new Color(0.01, 0.012, 0.03), horizonColor: new Color(0.02, 0.025, 0.04), groundColor: new Color(0.02, 0.02, 0.02)));
        $list->add(new SetAmbientLight(new Color(0.3, 0.35, 0.5), 0.02));
        $list->add(new SetDirectionalLight(new Vec3(-0.3, -0.6, 0.7), new Color(0.4, 0.45, 0.6), 0.02, 0.1));
        $list->add(new AddPointLight(new Vec3(0.0, 4.5, -2.0), new Color(1.0, 0.8, 0.5), 8.0, 10.0));
        $list->add(new DrawMesh('ground', 'ground', Mat4::translation(0.0, -0.1, 0.0)));
        return $list;
    }

    /** Mean of the RGB channels over columns $x0..$x1, rows $y0..$y1. */
    private function mean(string $img, int $x0, int $x1, int $y0, int $y1): float
    {
        $s = 0;
        $n = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                $o = ($y * self::W + $x) * 4;
                $s += ord($img[$o]) + ord($img[$o + 1]) + ord($img[$o + 2]);
                $n += 3;
            }
        }
        return $s / $n;
    }
}
