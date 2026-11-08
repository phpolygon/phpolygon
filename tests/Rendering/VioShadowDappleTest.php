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
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Dappled shadow (shader plan 6.1, Material::$shadowDapple): a flat canopy above the
 * floor, the sun at 45 degrees, the camera looking down at the shadow beside it. Solid, the floor
 * under the canopy is one dark patch; dappled, spots of sun break through - part of
 * the patch is lit, part stays dark - while the open floor does not change.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioShadowDappleTest extends TestCase
{
    private const W = 160;
    private const H = 120;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(40.0, 0.2, 40.0));
        MeshRegistry::register('canopy', BoxMesh::generate(6.0, 0.6, 6.0));
        MaterialRegistry::register('floor', new Material(albedo: new Color(0.7, 0.7, 0.7), roughness: 0.95, useEnvironmentMap: false));
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

    private function frame(float $dapple): string
    {
        self::assertNotNull($this->ctx);
        MaterialRegistry::register('canopy', new Material(albedo: new Color(0.2, 0.4, 0.15), roughness: 0.9, useEnvironmentMap: false, shadowDapple: $dapple));
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::High,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
            ambientOcclusion: ScreenSpaceAO::Off,
        ));
        $list = new RenderCommandList();
        // The sun stands at -x, 45 degrees up: the canopy's shadow falls 3.5 m toward +x, beside it.
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(5.0, 6.5, 0.5), new Vec3(1.2, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.2));
        $list->add(new SetDirectionalLight(new Vec3(1.0, -1.0, 0.0), new Color(1.0, 1.0, 1.0), 1.0));
        $list->add(new DrawMesh('floor', 'floor', Mat4::translation(0.0, -0.1, 0.0)));
        // The canopy hangs at 3.5 m, centred at x = -3.5 (edge at x = -0.5).
        $list->add(new DrawMesh('canopy', 'canopy', Mat4::translation(-3.5, 3.5, 0.0)));
        $rgba = '';
        for ($i = 0; $i < 3; $i++) {
            vio_begin($this->ctx);
            $renderer->beginFrame();
            $renderer->clear(new Color(0.0, 0.0, 0.0, 1.0));
            $renderer->render($list);
            $renderer->endFrame();
            vio_end($this->ctx);
        }
        $rgba = (string) vio_read_pixels($this->ctx);
        self::assertSame(self::W * self::H * 4, strlen($rgba));
        return $rgba;
    }

    private static function luma(string $rgba, int $x, int $y): float
    {
        $o = ($y * self::W + $x) * 4;
        return 0.2126 * ord($rgba[$o]) + 0.7152 * ord($rgba[$o + 1]) + 0.0722 * ord($rgba[$o + 2]);
    }

    public function testSpotsOfSunBreakThroughTheCanopyShadow(): void
    {
        $solid = $this->frame(0.0);
        $dappled = $this->frame(0.6);

        // Floor pixels: the open floor is the brightest level of the solid frame, the
        // shadow the darkest floor level. Classify by the solid frame.
        // The open floor's level: the bottom rows of the frame are open floor in front
        // of the shadow (the canopy itself, near the top, is brighter still).
        $lit = 0.0;
        $shadow = 255.0;
        for ($y = (int) (self::H * 0.85); $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $lit = max($lit, self::luma($solid, $x, $y));
            }
        }
        $shadowPx = [];
        $openPx = [];
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $l = self::luma($solid, $x, $y);
                if (abs($l - $lit) <= 6.0) {
                    $openPx[] = [$x, $y];
                } elseif ($l < $lit - 40.0) {
                    $shadowPx[] = [$x, $y];
                    $shadow = min($shadow, $l);
                }
            }
        }
        self::assertGreaterThan(500, count($shadowPx), 'the solid canopy casts a shadow in view');
        self::assertGreaterThan(500, count($openPx), 'open floor in view');

        // Under the dappled canopy: how many former shadow pixels are now lit (above
        // halfway to the open floor), and does the open floor stay?
        $mid = ($lit + $shadow) / 2.0;
        $spots = 0;
        foreach ($shadowPx as [$x, $y]) {
            if (self::luma($dappled, $x, $y) > $mid) {
                $spots++;
            }
        }
        $changedOpen = 0;
        foreach ($openPx as [$x, $y]) {
            if (abs(self::luma($dappled, $x, $y) - self::luma($solid, $x, $y)) > 3.0) {
                $changedOpen++;
            }
        }
        $share = $spots / count($shadowPx);
        $label = sprintf('lit %.0f, shadow %.0f, spots %.0f %% of %d, open changed %d', $lit, $shadow, $share * 100, count($shadowPx), $changedOpen);
        self::assertGreaterThan(0.15, $share, "{$label}: sun breaks through");
        self::assertLessThan(0.85, $share, "{$label}: but the shadow stays");
        self::assertLessThan(count($openPx) * 0.03, $changedOpen, "{$label}: the open floor does not change");
    }
}
