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
 * Skin has two specular lobes: on a black sphere (specular only) the highlight of a skin material is broader
 * than the single lobe of the same roughness, with a comparable total.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSkinSpecularTest extends TestCase
{
    private const SIZE = 128;

    public function testSkinHighlightHasABroaderSecondLobe(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('ball', SphereMesh::generate(1.0, 48, 64));
        MaterialRegistry::register('plain', new Material(albedo: new Color(0.0, 0.0, 0.0), roughness: 0.35, useEnvironmentMap: false));
        MaterialRegistry::register('skin', new Material(albedo: new Color(0.0, 0.0, 0.0), roughness: 0.35, useEnvironmentMap: false, subsurfaceColor: new Color(0.8, 0.2, 0.1), subsurfaceStrength: 0.01));
        try {
            $renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));
            $profile = function (string $material) use ($renderer): array {
                $l = new RenderCommandList();
                $l->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.0, 4.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(30.0), 1.0, 0.1, 50.0)));
                $l->add(new SetAmbientLight(new Color(0.0, 0.0, 0.0), 0.0));
                // Light from the upper right: the highlight sits off-centre on the sphere.
                $l->add(new SetDirectionalLight((new Vec3(-0.5, -0.4, -1.0))->normalize(), new Color(1.0, 1.0, 1.0), 0.6));
                $l->add(new DrawMesh('ball', $material, Mat4::identity()));
                $img = $renderer->renderToImage($l, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
                $vals = [];
                for ($i = 0; $i < self::SIZE * self::SIZE; $i++) {
                    $vals[] = ord($img[$i * 4]);
                }

                return [max($vals), array_sum($vals), count(array_filter($vals, static fn (int $v): bool => $v > 12))];
            };
            [$peakPlain, $sumPlain, $areaPlain] = $profile('plain');
            [$peakSkin, $sumSkin, $areaSkin] = $profile('skin');
            fprintf(STDERR, "[skin specular] plain peak %d, sum %d, area %d; skin peak %d, sum %d, area %d\n", $peakPlain, $sumPlain, $areaPlain, $peakSkin, $sumSkin, $areaSkin);
            self::assertGreaterThan(0, $peakPlain);
            self::assertGreaterThan($areaPlain * 1.15, $areaSkin, 'the skin highlight spreads over a larger area (the second, broad lobe)');
        } finally {
            vio_destroy($ctx);
        }
    }
}
