<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
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
use PHPolygon\Rendering\Ocean\OceanSpectrum;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Spectral ocean (shader plan 10.2): OceanSpectrum bakes a seamless loop of FFT wave
 * fields; a surface with Material::$oceanTexture is displaced and shaded by it - it
 * moves over time and repeats exactly after one loop.
 */
final class VioOceanTest extends TestCase
{
    public function testTheBakedSequenceLoopsAndTiles(): void
    {
        $o = OceanSpectrum::bake(32, 32.0, 8.0, 0.4, 8, 6.0);
        self::assertSame(32 * 32 * 8 * 4, strlen($o['data']));
        $n = 32;
        $d = $o['data'];
        $h = static fn (int $f, int $x, int $y): int => ord($d[(($f * $n + $y) * $n + $x) * 4]);
        $step = static function (int $a, int $b) use ($h, $n): float {
            $s = 0;
            for ($y = 0; $y < $n; $y++) {
                for ($x = 0; $x < $n; $x++) {
                    $s += abs($h($a, $x, $y) - $h($b, $x, $y));
                }
            }
            return $s / ($n * $n);
        };
        // The step from the last frame back to the first is no bigger than any other.
        self::assertLessThan($step(0, 1) * 1.5, $step(7, 0));
        self::assertGreaterThan(2.0, $step(0, 1), 'the waves move');
        // Across the tile edge the field continues like anywhere inside it.
        $edge = 0;
        $inner = 0;
        for ($y = 0; $y < $n; $y++) {
            $edge += abs($h(0, $n - 1, $y) - $h(0, 0, $y));
            $inner += abs($h(0, 10, $y) - $h(0, 11, $y));
        }
        self::assertLessThan($inner * 2.0 + $n * 4, $edge);
    }

    #[RequiresPhpExtension('vio')]
    #[Group('native-gpu')]
    public function testTheSurfaceMovesAndRepeatsAfterOneLoop(): void
    {
        $ctx = VioTestContext::create(128, 80, ['shader_model' => 6, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            MeshRegistry::clear();
            MaterialRegistry::clear();
            $renderer = new VioRenderer3D($ctx, 128, 80);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false));
            $renderer->warmShaders();
            if ((ProcModeShaderRegistry::textureUnits() ?? 0) <= 18) {
                $this->markTestSkipped('fewer than 19 texture units: no ocean volume in the vertex stage');
            }
            $tex = OceanSpectrum::upload($ctx, OceanSpectrum::bake(32, 32.0, 8.0, 0.4, 8, 6.0));
            self::assertNotNull($tex);
            $renderer->registerGpuTexture('ocean', $tex);
            MeshRegistry::register('sea', PlaneMesh::generate(60.0, 60.0, 120));
            MaterialRegistry::register('flat', new Material(albedo: new Color(0.05, 0.25, 0.35), roughness: 0.3));
            MaterialRegistry::register('ocean', new Material(albedo: new Color(0.05, 0.25, 0.35), roughness: 0.3, oceanTexture: 'ocean', oceanPatch: 32.0, oceanAmplitude: 1.2, oceanLoop: 6.0));
            $shot = function (string $material, float $t) use ($renderer): string {
                $renderer->setAnimationTime($t);
                $list = new RenderCommandList();
                $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 4.0, 18.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(50.0), 1.6, 0.1, 200.0)));
                $list->add(new SetAmbientLight(new Color(0.8, 0.85, 1.0), 0.5));
                $list->add(new SetDirectionalLight((new Vec3(-0.3, -0.6, -0.7))->normalize(), new Color(1.0, 0.95, 0.85), 1.6));
                $list->add(new DrawMesh('sea', $material, Mat4::identity()));
                return $renderer->renderToImage($list, 128, 80, new Color(0.6, 0.7, 0.85));
            };
            $diff = static function (string $a, string $b): float {
                $s = 0;
                for ($i = 0, $n = strlen($a); $i < $n; $i += 4) {
                    $s += abs(ord($a[$i]) - ord($b[$i])) + abs(ord($a[$i + 1]) - ord($b[$i + 1])) + abs(ord($a[$i + 2]) - ord($b[$i + 2]));
                }
                return $s / (strlen($a) / 4 * 3);
            };
            $flat = $shot('flat', 1.0);
            $t1 = $shot('ocean', 1.0);
            $t2 = $shot('ocean', 2.5);
            $loop = $shot('ocean', 7.0);
            fprintf(STDERR, "[ocean] flat vs waves %.2f, 1.0 s vs 2.5 s %.2f, 1.0 s vs one loop later %.2f\n", $diff($flat, $t1), $diff($t1, $t2), $diff($t1, $loop));
            self::assertGreaterThan(1.0, $diff($flat, $t1), 'the spectrum shapes the surface');
            self::assertGreaterThan(1.5, $diff($t1, $t2), 'the waves move');
            self::assertLessThan(0.3, $diff($t1, $loop), 'one loop later the sea looks the same');
        } finally {
            MaterialRegistry::clear();
            MeshRegistry::clear();
            vio_destroy($ctx);
        }
    }
}
