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
use PHPolygon\Rendering\Command\SetGroundWetness;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/** Rain on walls (opt-in): a wall darkens and runs with vertical streaks; without the option it stays dry. */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioWallWetnessTest extends TestCase
{
    private const SIZE = 128;

    public function testRainWetsAWallInStreaks(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('wall', BoxMesh::generate(10.0, 10.0, 0.2));
        MaterialRegistry::register('plaster', new Material(albedo: new Color(0.7, 0.66, 0.6), roughness: 0.9, useEnvironmentMap: false));
        try {
            $renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));
            $shot = function (?float $walls) use ($renderer): string {
                $l = new RenderCommandList();
                $l->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.0, 9.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(40.0), 1.0, 0.1, 60.0)));
                $l->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.12));
                $l->add(new SetDirectionalLight((new Vec3(-0.3, -0.4, -1.0))->normalize(), new Color(1.0, 1.0, 1.0), 0.45));
                if ($walls !== null) {
                    $l->add(new SetGroundWetness(1.0, 0.0, null, $walls));
                }
                $l->add(new DrawMesh('wall', 'plaster', Mat4::identity()));

                return $renderer->renderToImage($l, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
            };
            $stats = static function (string $img): array {
                $cols = array_fill(0, self::SIZE, 0.0);
                $rows = array_fill(0, self::SIZE, 0.0);
                $sum = 0.0;
                for ($y = 20; $y < self::SIZE - 20; $y++) {
                    for ($x = 20; $x < self::SIZE - 20; $x++) {
                        $v = ord($img[($y * self::SIZE + $x) * 4]);
                        $cols[$x] += $v;
                        $rows[$y] += $v;
                        $sum += $v;
                    }
                }
                $n = (self::SIZE - 40);
                $spread = static function (array $a) use ($n): float {
                    $part = array_slice($a, 20, $n);
                    $m = array_sum($part) / $n;

                    return sqrt(array_sum(array_map(static fn (float $v): float => ($v - $m) ** 2, $part)) / $n) / $n;
                };

                return [$sum / ($n * $n), $spread($cols), $spread($rows)];
            };
            [$dryMean, $dryCols] = $stats($shot(null));
            [$ground, ] = $stats($shot(0.0));
            [$wetMean, $wetCols, $wetRows] = $stats($shot(1.0));
            fprintf(STDERR, "[wall wetness] mean dry %.1f, ground-only rain %.1f, wet %.1f; column spread dry %.2f, wet %.2f (row spread %.2f)\n", $dryMean, $ground, $wetMean, $dryCols, $wetCols, $wetRows);
            self::assertEqualsWithDelta($dryMean, $ground, 0.5, 'rain without wallWetness leaves the wall dry (the default)');
            self::assertLessThan($dryMean * 0.95, $wetMean, 'the wet wall is darker');
            self::assertGreaterThan($dryCols + 0.15, $wetCols, 'and runs in vertical streaks (the brightness varies across the wall)');
            self::assertGreaterThan($wetRows * 1.15, $wetCols, 'more across than along the streaks');
        } finally {
            vio_destroy($ctx);
        }
    }
}
