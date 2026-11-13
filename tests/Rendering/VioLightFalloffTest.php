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
use PHPolygon\Rendering\Command\SetLightFalloff;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/** SetLightFalloff: with the inverse square law a wall twice as far from the light gets a quarter of the light. */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioLightFalloffTest extends TestCase
{
    private const SIZE = 64;

    public function testInverseSquareFalloffIsOptIn(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('wall', BoxMesh::generate(6.0, 6.0, 0.1));
        MaterialRegistry::register('w', new Material(albedo: new Color(0.9, 0.9, 0.9), roughness: 1.0, useEnvironmentMap: false));
        try {
            $renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));
            // The wall faces +Z at z = 0; the light hovers at distance $d in front of its centre; the camera looks at the centre.
            $centre = function (float $d, bool $physical) use ($renderer): int {
                $l = new RenderCommandList();
                $l->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.0, 6.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(20.0), 1.0, 0.1, 50.0)));
                $l->add(new SetAmbientLight(new Color(0.0, 0.0, 0.0), 0.0));
                if ($physical) {
                    $l->add(new SetLightFalloff(true));
                }
                $l->add(new AddPointLight(new Vec3(0.0, 0.0, $d), new Color(1.0, 1.0, 1.0), $physical ? 0.5 : 1.0, 12.0));
                $l->add(new DrawMesh('wall', 'w', Mat4::identity()));
                $img = $renderer->renderToImage($l, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
                $o = (32 * self::SIZE + 32) * 4;

                return ord($img[$o]);
            };
            $legacyNear = $centre(1.5, false);
            $legacyFar = $centre(3.0, false);
            $physNear = $centre(1.5, true);
            $physFar = $centre(3.0, true);
            fprintf(STDERR, "[falloff] legacy %d -> %d, inverse square %d -> %d\n", $legacyNear, $legacyFar, $physNear, $physFar);
            self::assertGreaterThan(0, $legacyFar);
            self::assertLessThan(1.6, $legacyNear / max(1, $legacyFar), 'the classic window is flat over this range');
            self::assertGreaterThan(2.2, $physNear / max(1, $physFar), 'inverse square: half the distance, much more light');
        } finally {
            vio_destroy($ctx);
        }
    }
}
