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
use PHPolygon\Rendering\VioRenderer3D;

/** SetSkyAmbient: a grey sphere in ambient light only is blue on top and warm below; without it, uniform. */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSkyAmbientTest extends TestCase
{
    private const SIZE = 96;

    public function testTheAmbientFollowsTheSky(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('ball', SphereMesh::generate(1.0, 32, 48));
        MaterialRegistry::register('grey', new Material(albedo: new Color(0.6, 0.6, 0.6), roughness: 1.0, useEnvironmentMap: false));
        try {
            $renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));
            $shot = function (bool $skyAmbient) use ($renderer): string {
                $l = new RenderCommandList();
                $l->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.0, 5.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(30.0), 1.0, 0.1, 50.0)));
                $l->add(new SetAmbientLight(new Color(0.5, 0.5, 0.5), 0.6));
                $l->add(new SetSky(new Vec3(0.0, 1.0, 0.0), new Color(1.0, 1.0, 1.0), 0.0, new Color(0.15, 0.35, 1.0), new Color(0.7, 0.75, 0.9), new Color(0.9, 0.5, 0.15)));
                if ($skyAmbient) {
                    $l->add(new SetSkyAmbient(0.6, 1.0));
                }
                $l->add(new DrawMesh('ball', 'grey', Mat4::identity()));

                return $renderer->renderToImage($l, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
            };
            $px = static fn (string $img, int $row): array => [ord($img[($row * self::SIZE + 48) * 4]), ord($img[($row * self::SIZE + 48) * 4 + 2])];
            $flat = $shot(false);
            $sky = $shot(true);
            [$topR, $topB] = $px($sky, 30);
            [$botR, $botB] = $px($sky, 66);
            [$fTopR, $fTopB] = $px($flat, 30);
            [$fBotR, $fBotB] = $px($flat, 66);
            fprintf(STDERR, "[sky ambient] top r/b %d/%d, bottom %d/%d; flat top %d/%d, bottom %d/%d\n", $topR, $topB, $botR, $botB, $fTopR, $fTopB, $fBotR, $fBotB);
            self::assertEqualsWithDelta($fTopR - $fTopB, $fBotR - $fBotB, 4, 'the flat ambient has the same tint above and below');
            self::assertGreaterThan($topR + 15, $topB, 'the top takes the blue of the zenith');
            self::assertGreaterThan($botB + 5, $botR, 'the underside takes the warm ground bounce');
        } finally {
            vio_destroy($ctx);
        }
    }
}
