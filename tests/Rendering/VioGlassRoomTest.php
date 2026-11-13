<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\MetricMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/** Glass: the sky in the pane (opt-in) and a furnished room behind every window cell (opt-in). */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioGlassRoomTest extends TestCase
{
    private const SIZE = 128;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    private function renderer(): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $r = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $r->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));

        return $r;
    }

    private static function sum(string $img): int
    {
        $s = 0;
        for ($i = 0; $i < strlen($img); $i += 4) {
            $s += ord($img[$i]) + ord($img[$i + 1]) + ord($img[$i + 2]);
        }

        return $s;
    }

    public function testThePaneReflectsTheSky(): void
    {
        MeshRegistry::register('pane', BoxMesh::generate(6.0, 6.0, 0.02));
        MeshRegistry::register('back', BoxMesh::generate(30.0, 30.0, 0.1));
        MaterialRegistry::register('black', new Material(albedo: new Color(0.0, 0.0, 0.0), roughness: 1.0, useEnvironmentMap: false));
        $clear = new Material(albedo: new Color(0.9, 0.95, 1.0), roughness: 0.05, alpha: 0.08, useEnvironmentMap: false, glass: 1.0);
        MaterialRegistry::register('clear', $clear);
        MaterialRegistry::register('mirror', $clear->withGlassReflection(1.0));
        $renderer = $this->renderer();
        $shot = function (string $material) use ($renderer): string {
            $l = new RenderCommandList();
            // The pane stands upright; the camera looks at it at a grazing angle (about 75 degrees from its normal).
            $l->add(new SetCamera(Mat4::lookAt(new Vec3(9.0, 1.0, 2.5), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(30.0), 1.0, 0.1, 60.0)));
            $l->add(new SetAmbientLight(new Color(0.2, 0.2, 0.2), 0.2));
            $l->add(new SetSky(new Vec3(0.0, 1.0, 0.0), new Color(1.0, 1.0, 1.0), 0.0, new Color(0.7, 0.9, 1.6), new Color(1.2, 1.2, 1.3), new Color(0.2, 0.2, 0.2)));
            $l->add(new DrawMesh('back', 'black', Mat4::translation(0.0, 0.0, -0.6)));
            $l->add(new DrawMesh('pane', $material, Mat4::identity()));

            return $renderer->renderToImage($l, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
        };
        $region = static function (string $img): int {
            $s = 0;
            for ($y = 44; $y < 84; $y++) {
                for ($x = 52; $x < 76; $x++) {
                    $o = ($y * self::SIZE + $x) * 4;
                    $s += ord($img[$o]) + ord($img[$o + 1]) + ord($img[$o + 2]);
                }
            }

            return $s;
        };
        $off = $region($shot('clear'));
        $on = $region($shot('mirror'));
        fprintf(STDERR, "[glass] pane region brightness: plain %d, with reflection %d\n", $off, $on);
        self::assertGreaterThan($off * 1.5 + 500, $on, 'the sky shows in the pane');
    }

    public function testEveryCellGetsItsOwnRoomWithParallax(): void
    {
        MeshRegistry::register('wall', MetricMesh::box(8.0, 8.0, 0.1));
        $base = new Material(
            albedo: new Color(0.02, 0.02, 0.02), emission: new Color(1.0, 1.0, 1.0), roughness: 0.9, useEnvironmentMap: false,
            windowLights: 0.6, windowCell: [2.0, 2.0], windowUv: true,
        );
        MaterialRegistry::register('flat', $base);
        MaterialRegistry::register('room', $base->withWindowRoom());
        $renderer = $this->renderer();
        $shot = function (string $material, float $camX) use ($renderer): string {
            $l = new RenderCommandList();
            $l->add(new SetCamera(Mat4::lookAt(new Vec3($camX, 0.0, 9.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(40.0), 1.0, 0.1, 60.0)));
            $l->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.02));
            $l->add(new DrawMesh('wall', $material, Mat4::identity()));

            return $renderer->renderToImage($l, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
        };
        $lum = static fn (string $i, int $x, int $y): float => ord($i[($y * self::SIZE + $x) * 4]) * 0.3 + ord($i[($y * self::SIZE + $x) * 4 + 1]) * 0.59 + ord($i[($y * self::SIZE + $x) * 4 + 2]) * 0.11;
        // The 4 x 4 cells of the wall; each cell's mean luminance and the spread inside the brightest one.
        $cells = static function (string $img) use ($lum): array {
            $out = [];
            $w = intdiv(self::SIZE, 4);
            for ($cy = 0; $cy < 4; $cy++) {
                for ($cx = 0; $cx < 4; $cx++) {
                    $vals = [];
                    for ($y = $cy * $w + 8; $y < ($cy + 1) * $w - 8; $y++) {
                        for ($x = $cx * $w + 8; $x < ($cx + 1) * $w - 8; $x++) {
                            $vals[] = $lum($img, $x, $y);
                        }
                    }
                    $mean = array_sum($vals) / count($vals);
                    $var = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $vals)) / count($vals);
                    $out[] = [$mean, sqrt($var)];
                }
            }

            return $out;
        };
        $flat = $cells($shot('flat', 0.0));
        $room = $cells($shot('room', 0.0));
        $roomSide = $shot('room', 3.0);
        $roomFront = $shot('room', 0.0);
        $means = array_map(static fn (array $c): float => $c[0], $room);
        $top = max($means);
        $lit = array_filter($means, static fn (float $m): bool => $m > $top * 0.3);
        $dark = array_filter($means, static fn (float $m): bool => $m < $top * 0.1);
        $spreadRoom = max(array_map(static fn (array $c): float => $c[1], $room));
        $spreadFlat = max(array_map(static fn (array $c): float => $c[1], $flat));
        $parallax = 0.0;
        for ($i = 0; $i < strlen($roomSide); $i += 4) {
            $parallax += abs(ord($roomSide[$i]) - ord($roomFront[$i]));
        }
        fprintf(STDERR, "[room] means %s\n", implode(" ", array_map(static fn (float $m): string => (string) round($m, 1), $means)));
        fprintf(STDERR, "[room] cells lit %d dark %d; spread inside a cell: room %.1f, flat %.1f\n", count($lit), count($dark), $spreadRoom, $spreadFlat);
        self::assertGreaterThan(2, count($lit), 'some rooms are lit');
        self::assertGreaterThan(2, count($dark), 'and some are dark');
        self::assertGreaterThan($spreadFlat + 8.0, $spreadRoom, 'a lit room has structure (walls, floor, ceiling, furniture)');
        self::assertGreaterThan(0.0, $parallax);
    }
}
