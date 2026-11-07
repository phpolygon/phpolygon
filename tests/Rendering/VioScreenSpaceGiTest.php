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
 * Screen-space radiosity (shader plan 10.2, GraphicsSettings::$screenSpaceGi): the
 * floor beside a sunlit red wall picks up its red bounce from the pixels of the wall;
 * the floor far from it does not change colour. MRT path (ambient occlusion on).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioScreenSpaceGiTest extends TestCase
{
    private const W = 192;
    private const H = 128;

    public function testTheFloorBesideTheRedWallTurnsRed(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            MeshRegistry::clear();
            MaterialRegistry::clear();
            MeshRegistry::register('floor', BoxMesh::generate(30.0, 0.1, 30.0));
            MeshRegistry::register('wall', BoxMesh::generate(0.2, 4.0, 8.0));
            MaterialRegistry::register('white', new Material(albedo: new Color(0.45, 0.45, 0.45), roughness: 1.0, useEnvironmentMap: false));
            MaterialRegistry::register('red', new Material(albedo: new Color(0.9, 0.05, 0.05), roughness: 1.0, useEnvironmentMap: false));
            $redness = [];
            foreach (['off' => false, 'on' => true] as $label => $gi) {
                $renderer = new VioRenderer3D($ctx, self::W, self::H);
                $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, ambientOcclusion: ScreenSpaceAO::Medium, antiAliasing: AntiAliasing::Off, bloom: false, screenSpaceGi: $gi));
                $list = new RenderCommandList();
                // Facing the wall's sunlit side (-x): a screen-space bounce needs the wall in view.
                $list->add(new SetCamera(Mat4::lookAt(new Vec3(-7.0, 2.5, 3.0), new Vec3(0.0, 0.8, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(60.0), self::W / self::H, 0.1, 100.0)));
                $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.3));
                $list->add(new SetDirectionalLight((new Vec3(0.6, -0.8, -0.1))->normalize(), new Color(1.0, 1.0, 1.0), 0.9));
                $list->add(new DrawMesh('floor', 'white', Mat4::translation(0.0, -0.05, 0.0)));
                $list->add(new DrawMesh('wall', 'red', Mat4::translation(0.0, 2.0, 0.0)));
                $img = '';
                for ($f = 0; $f < 3; $f++) {
                    $img = $renderer->renderToImage($list, self::W, self::H, new Color(0.5, 0.6, 0.75));
                }
                // Floor at the wall's foot vs. floor far in front of it.
                if (!$renderer->mrtActive()) {
                    $this->markTestSkipped('no MRT path on this backend');
                }
                $redness[$label] = [$this->redness($img, 100, 84), $this->redness($img, 20, 122)];
            }
            fprintf(STDERR, "[ssgi] near the wall r/g %.3f -> %.3f, far %.3f -> %.3f\n", $redness['off'][0], $redness['on'][0], $redness['off'][1], $redness['on'][1]);
            self::assertGreaterThan($redness['off'][0] * 1.03, $redness['on'][0], 'the floor beside the wall gathers red');
            self::assertLessThan(abs($redness['on'][0] - $redness['off'][0]) / 4, abs($redness['on'][1] - $redness['off'][1]), 'far away the colour hardly changes');
        } finally {
            MaterialRegistry::clear();
            MeshRegistry::clear();
            vio_destroy($ctx);
        }
    }

    /** Red over green, averaged over a 5x5 patch. */
    private function redness(string $img, int $cx, int $cy): float
    {
        $r = 0;
        $g = 0;
        for ($y = $cy - 2; $y <= $cy + 2; $y++) {
            for ($x = $cx - 2; $x <= $cx + 2; $x++) {
                $o = ($y * self::W + $x) * 4;
                $r += ord($img[$o]);
                $g += ord($img[$o + 1]);
            }
        }
        return $r / max(1, $g);
    }
}
