<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\Command\SetSkyAmbient;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioEnvironmentCubemap;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * SetSkyAmbient(fromCubemap: true): the ambient comes from the harmonics of the sky cube the renderer draws, so a sky that is
 * blue above and warm below lights a grey sphere blue on top and warm underneath - read from the cube's pixels, with the
 * face orientation right on this backend (a flipped cube would light the sphere the other way round).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSkyShAmbientTest extends TestCase
{
    private const SIZE = 96;

    public function testTheAmbientFollowsTheCubeFacesTheRightWayUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!VioEnvironmentCubemap::isSupported($ctx)) {
            vio_destroy($ctx);
            $this->markTestSkipped('no render-target cubemaps on this backend');
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('ball', SphereMesh::generate(1.0, 32, 48));
        MaterialRegistry::register('grey', new Material(albedo: new Color(0.6, 0.6, 0.6), roughness: 1.0, useEnvironmentMap: false));
        try {
            $renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));
            $shot = function (bool $fromCube) use ($renderer): string {
                $img = '';
                for ($k = 0; $k < 2; $k++) {
                    $l = new RenderCommandList();
                    $l->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.0, 5.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(30.0), 1.0, 0.1, 50.0)));
                    $l->add(new SetAmbientLight(new Color(0.5, 0.5, 0.5), 0.6));
                    $l->add(new SetSky(new Vec3(0.0, -1.0, 0.0), new Color(1.0, 1.0, 1.0), 0.0, new Color(0.1, 0.3, 1.0), new Color(0.6, 0.65, 0.8), new Color(0.9, 0.45, 0.1)));
                    $l->add(new SetSkyAmbient(0.6, 1.0, $fromCube));
                    $l->add(new DrawMesh('ball', 'grey', Mat4::identity()));
                    $img = $renderer->renderToImage($l, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
                }

                return $img;
            };
            $px = static fn (string $img, int $row): array => [ord($img[($row * self::SIZE + 48) * 4]), ord($img[($row * self::SIZE + 48) * 4 + 2])];
            $cube = $shot(true);
            [$topR, $topB] = $px($cube, 30);
            [$botR, $botB] = $px($cube, 66);
            fprintf(STDERR, "[sky sh] top r/b %d/%d, bottom r/b %d/%d\n", $topR, $topB, $botR, $botB);
            self::assertGreaterThan($topR + 10, $topB, 'the top takes the blue of the sky cube');
            self::assertGreaterThan($botB, $botR, 'the underside takes the warm ground');
        } finally {
            vio_destroy($ctx);
        }
    }
}
