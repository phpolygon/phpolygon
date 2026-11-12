<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\MetricMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The lit-window look's grid (Material::$windowCell / $windowOffset / $windowUv):
 * cell borders are dark frames, so moving the grid moves the dark line.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioWindowGridRenderTest extends TestCase
{
    private const int W = 128;
    private const int H = 128;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!vio_supports_feature($ctx, VIO_FEATURE_3D_PIPELINE)) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no 3D pipeline');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        // 8 x 8 m wall, metric UVs: u = x + 4 (left to right), v = y + 4 on the +Z face.
        MeshRegistry::register('wall', MetricMesh::box(8.0, 8.0, 0.1));
    }

    protected function tearDown(): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    /** @param array<string, mixed> $grid */
    private function wall(array $grid): Material
    {
        // Every window lit (fraction 1.0), the emission is the only light (black albedo, no ambient).
        return new Material(...($grid + [
            'albedo' => new Color(0.0, 0.0, 0.0),
            'emission' => new Color(1.0, 1.0, 1.0),
            'useEnvironmentMap' => false,
            'windowLights' => 1.0,
        ]));
    }

    /** Brightness of the row y = 64 as a list over x (sum of channels). */
    private function row(Material $material): array
    {
        self::assertNotNull($this->ctx);
        MaterialRegistry::register('w', $material);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 10.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(40.0), 1.0, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.0));
        $list->add(new DrawMesh('wall', 'w', Mat4::identity()));
        $image = $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0));
        $row = [];
        for ($x = 0; $x < self::W; $x++) {
            $o = (64 * self::W + $x) * 4;
            $row[] = ord($image[$o]) + ord($image[$o + 1]) + ord($image[$o + 2]);
        }
        return $row;
    }

    /** Index of the darkest pixel in the x range [from, to). */
    private static function darkest(array $row, int $from, int $to): int
    {
        $best = $from;
        for ($x = $from; $x < $to; $x++) {
            if ($row[$x] < $row[$best]) {
                $best = $x;
            }
        }
        return $best;
    }

    public function testUvGridPutsTheFrameWhereTheOffsetSays(): void
    {
        // Cells 4 m wide in UV mode: frames at u = offset + 4n. The wall spans world x -4..4
        // (u 0..8), the camera sees about +-3.6 m at the wall plane, so px 64 is x = 0 (u = 4).
        $centred = $this->row($this->wall(['windowCell' => [4.0, 4.0], 'windowUv' => true]));
        $shifted = $this->row($this->wall(['windowCell' => [4.0, 4.0], 'windowOffset' => [2.0, 0.0], 'windowUv' => true]));

        // Offset 0: a border (u = 4) runs through the middle; offset 2: the middle is a cell centre.
        self::assertLessThan($centred[48] * 0.7, $centred[64], 'dark frame in the middle with offset 0');
        self::assertGreaterThan($shifted[48] * 0.7, $shifted[64], 'bright cell in the middle with offset 2');
        // With offset 2 the frame moves to u = 2 and u = 6 (world x = -2 and 2).
        // The view spans about +-3.64 m at the wall plane over 128 px: x = -2 m is px ~29, +2 m is px ~99.
        $left = self::darkest($shifted, 10, 64);
        $right = self::darkest($shifted, 65, 118);
        self::assertEqualsWithDelta(29, $left, 5, 'frame at u = 2');
        self::assertEqualsWithDelta(99, $right, 5, 'frame at u = 6');
    }

    public function testCellSizeChangesTheGrid(): void
    {
        $small = $this->row($this->wall(['windowCell' => [1.0, 1.0], 'windowUv' => true]));
        $large = $this->row($this->wall(['windowCell' => [8.0, 8.0], 'windowUv' => true]));
        $dark = static function (array $row): int {
            $part = array_slice($row, 20, 88);
            $limit = max($part) * 0.85;
            return count(array_filter($part, static fn(int $v): bool => $v < $limit));
        };
        self::assertGreaterThan($dark($large) + 3, $dark($small), 'more frames (dark pixels) with small cells');
    }
}
