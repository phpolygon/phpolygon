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
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * More point lights than the eight of the fixed loop: 24 small lights in a row along a long wall; every one of
 * them lights its own patch, whatever its index in the list (the Light Grid path, or the eight nearest).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioManyLightsTest extends TestCase
{
    private const W = 240;
    private const H = 48;

    public function testEveryOfTwentyFourLightsLightsItsOwnPatch(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_model' => 6, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('wall', BoxMesh::generate(24.0, 3.0, 0.1));
        MaterialRegistry::register('w', new Material(albedo: new Color(0.8, 0.8, 0.8), roughness: 1.0, useEnvironmentMap: false));
        try {
            $renderer = new VioRenderer3D($ctx, self::W, self::H);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));
            $l = new RenderCommandList();
            $l->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 1.5, 10.0), new Vec3(0.0, 1.5, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::orthographic(-12.0, 12.0, -2.4, 2.4, 1.0, 30.0)));
            $l->add(new SetAmbientLight(new Color(0.0, 0.0, 0.0), 0.0));
            $l->add(new DrawMesh('wall', 'w', Mat4::translation(0.0, 1.5, 0.0)));
            for ($i = 0; $i < 24; $i++) {
                $l->add(new AddPointLight(new Vec3(-11.5 + $i, 1.5, 0.8), new Color(1.0, 1.0, 1.0), 0.8, 1.6));
            }
            $img = $renderer->renderToImage($l, self::W, self::H, new Color(0.0, 0.0, 0.0));
            $dark = 0;
            $lit = [];
            for ($i = 0; $i < 24; $i++) {
                // The pixel column of light $i (24 world units over 240 px) at mid height.
                $x = (int) round(($i + 0.5) * self::W / 24.0);
                $lit[$i] = ord($img[((self::H / 2) * self::W + $x) * 4]);
                if ($lit[$i] < 40) {
                    $dark++;
                }
            }
            fprintf(STDERR, "[lights] patch brightness by light index: %s\n", implode(' ', $lit));
            self::assertSame(0, $dark, 'every light of the 24 lights its patch (dark patches: ' . $dark . ')');
        } finally {
            vio_destroy($ctx);
        }
    }
}
