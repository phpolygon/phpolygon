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
use PHPolygon\Rendering\NormalPattern;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\SurfacePattern;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Pattern filtering (opt-in): a fine procedural pattern on a ground plane seen at a grazing angle aliases
 * into per-pixel noise in the distance; with filtering the far rows are smooth, the near rows keep the pattern,
 * and the frame is unchanged while the switch is off.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioPatternFilteringTest extends TestCase
{
    private const W = 192;
    private const H = 108;

    public function testFarPatternFadesOutAndNearKeepsItself(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('ground', BoxMesh::generate(400.0, 0.1, 400.0));
        MaterialRegistry::register('g', new Material(
            albedo: new Color(0.5, 0.5, 0.5), roughness: 0.6, useEnvironmentMap: false,
            normalPattern: NormalPattern::ORANGE_PEEL, normalScale: 8.0, normalIntensity: 1.5,
            surfacePattern: SurfacePattern::BRUSHED_METAL, surfaceScale: 8.0, surfaceIntensity: 1.0,
        ));
        try {
            $renderer = new VioRenderer3D($ctx, self::W, self::H);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));
            $shot = function (bool $filter) use ($renderer): string {
                $renderer->setPatternFiltering($filter);
                $l = new RenderCommandList();
                $l->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 1.6, 0.0), new Vec3(0.0, 1.0, -10.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(60.0), self::W / self::H, 0.1, 500.0)));
                $l->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.3));
                $l->add(new SetDirectionalLight((new Vec3(-0.3, -0.5, -0.6))->normalize(), new Color(1.0, 1.0, 1.0), 2.0));
                $l->add(new DrawMesh('ground', 'g', Mat4::identity()));

                return $renderer->renderToImage($l, self::W, self::H, new Color(0.0, 0.0, 0.0));
            };
            $lum = static fn (string $i, int $x, int $y): float => ord($i[($y * self::W + $x) * 4]) * 0.3 + ord($i[($y * self::W + $x) * 4 + 1]) * 0.59 + ord($i[($y * self::W + $x) * 4 + 2]) * 0.11;
            // Share of pixels that differ from their four neighbours by more than 4 levels in rows $r0..$r1.
            $noise = function (string $img, int $r0, int $r1) use ($lum): float {
                $n = 0;
                $hf = 0;
                for ($y = $r0; $y < $r1; $y++) {
                    for ($x = 4; $x < self::W - 4; $x++) {
                        $m = ($lum($img, $x - 1, $y) + $lum($img, $x + 1, $y) + $lum($img, $x, $y - 1) + $lum($img, $x, $y + 1)) / 4.0;
                        $hf += abs($lum($img, $x, $y) - $m) > 4.0 ? 1 : 0;
                        $n++;
                    }
                }

                return 100.0 * $hf / $n;
            };
            $off = $shot(false);
            $on = $shot(true);
            $again = $shot(false);
            // The horizon sits at row ~54; rows 58..66 are the far ground, rows 95..105 the near ground.
            $farOff = $noise($off, 58, 66);
            $farOn = $noise($on, 58, 66);
            $nearOff = $noise($off, 95, 105);
            $nearOn = $noise($on, 95, 105);
            fprintf(STDERR, "[pattern filter] far %.1f %% -> %.1f %%, near %.1f %% -> %.1f %%\n", $farOff, $farOn, $nearOff, $nearOn);
            self::assertSame($off, $again, 'with the switch off the frame is the old one');
            self::assertGreaterThan(8.0, $farOff, 'the far pattern aliases without filtering (the premise)');
            self::assertLessThan($farOff * 0.4, $farOn, 'filtering removes the far noise');
            self::assertGreaterThan($nearOff * 0.6, $nearOn, 'the near pattern is kept');
        } finally {
            vio_destroy($ctx);
        }
    }
}
