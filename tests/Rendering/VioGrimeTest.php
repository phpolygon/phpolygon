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
use PHPolygon\Rendering\Command\SetGrime;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/** Grime: only materials that opt in age, and they darken in creases and run in streaks below the ledge. */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioGrimeTest extends TestCase
{
    private const SIZE = 128;

    public function testGrimeIsOptInAndAgesTheSurface(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('wall', BoxMesh::generate(10.0, 10.0, 0.2));
        MeshRegistry::register('ledge', BoxMesh::generate(10.0, 0.25, 0.8));
        $clean = new Material(albedo: new Color(0.75, 0.7, 0.64), roughness: 0.8, useEnvironmentMap: false);
        MaterialRegistry::register('clean', $clean);
        MaterialRegistry::register('aged', $clean->withGrime(1.0));
        try {
            $renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));
            $shot = function (string $material, ?float $grime) use ($renderer): string {
                $l = new RenderCommandList();
                $l->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.0, 9.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(40.0), 1.0, 0.1, 60.0)));
                $l->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.08));
                $l->add(new SetDirectionalLight((new Vec3(-0.3, -0.4, -1.0))->normalize(), new Color(1.0, 1.0, 1.0), 0.3));
                if ($grime !== null) {
                    $l->add(new SetGrime($grime));
                }
                $l->add(new DrawMesh('wall', $material, Mat4::identity()));
                $l->add(new DrawMesh('ledge', $material, Mat4::translation(0.0, 1.0, 0.5)));

                return $renderer->renderToImage($l, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
            };
            $mean = static function (string $img): float {
                $s = 0;
                for ($i = 0; $i < strlen($img); $i += 4) {
                    $s += ord($img[$i]);
                }

                return $s / (strlen($img) / 4);
            };
            $base = $shot('clean', null);
            $cleanWith = $shot('clean', 1.0);
            $agedOff = $shot('aged', null);
            $aged = $shot('aged', 1.0);
            fprintf(STDERR, "[grime] mean: clean %.1f, aged %.1f\n", $mean($base), $mean($aged));
            self::assertSame($base, $cleanWith, 'a material that did not opt in ignores SetGrime');
            self::assertSame($base, $agedOff, 'without SetGrime nothing ages');
            self::assertLessThan($mean($base) * 0.95, $mean($aged), 'the aged wall is darker');
            // Streaks: the brightness varies more across the aged wall than across the clean one.
            $spread = static function (string $img): float {
                $cols = [];
                for ($x = 10; $x < self::SIZE - 10; $x++) {
                    $s = 0;
                    for ($y = 10; $y < 48; $y++) {
                        $s += ord($img[($y * self::SIZE + $x) * 4]);
                    }
                    $cols[] = $s / 38.0;
                }
                $m = array_sum($cols) / count($cols);

                return sqrt(array_sum(array_map(static fn (float $v): float => ($v - $m) ** 2, $cols)) / count($cols));
            };
            self::assertGreaterThan($spread($base) + 1.0, $spread($aged), 'dirt runs in streaks');
        } finally {
            vio_destroy($ctx);
        }
    }
}
