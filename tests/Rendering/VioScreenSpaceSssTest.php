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
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Screen-space subsurface scattering (GraphicsSettings::$screenSpaceSss): across the edge
 * between a lit and an unlit face of a skin-coloured cube light bleeds into the dark
 * side - red further than blue, measured against each channel's own jump - while the
 * same cube without subsurface keeps its hard edge.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioScreenSpaceSssTest extends TestCase
{
    private const SIZE = 96;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('cube', BoxMesh::generate(0.12, 0.12, 0.12));
        MaterialRegistry::register('skin', new Material(albedo: Color::srgb('#d9a07f'), roughness: 0.6, useEnvironmentMap: false, subsurfaceColor: Color::srgb('#e0462e'), subsurfaceStrength: 0.8));
        MaterialRegistry::register('plain', new Material(albedo: Color::srgb('#d9a07f'), roughness: 0.6, useEnvironmentMap: false));
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
    }

    public function testLightBleedsAcrossTheEdgeOfSkinOnly(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            $renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
            $shot = function (string $material, bool $sss) use ($renderer): string {
                $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Medium, bloom: false, screenSpaceSss: $sss));
                $list = new RenderCommandList();
                // A vertical edge in the middle of the image: left face unlit, right face lit.
                $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.21, 0.0, 0.21), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(40.0), 1.0, 0.01, 10.0)));
                $list->add(new SetAmbientLight(new Color(0.6, 0.65, 0.75), 0.25));
                $list->add(new SetDirectionalLight(new Vec3(-1.0, -0.2, 0.05), new Color(1.0, 0.95, 0.9), 2.5));
                $list->add(new DrawMesh('cube', $material, Mat4::identity()));
                return $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
            };
            $skinOff = $shot('skin', false);
            $skinOn = $shot('skin', true);
            $plainOff = $shot('plain', false);
            $plainOn = $shot('plain', true);
            $plainDiff = 0;
            for ($i = 0, $n = strlen($plainOff); $i < $n; $i++) {
                $plainDiff = max($plainDiff, abs(ord($plainOff[$i]) - ord($plainOn[$i])));
            }
            // Across the edge on the middle row: each channel's bleed into the dark side
            // as a share of its jump.
            [$jumpR, $bleedR] = $this->bleed($skinOff, $skinOn, 0);
            [$jumpB, $bleedB] = $this->bleed($skinOff, $skinOn, 2);
            fprintf(STDERR, "[sss] red bleed %.1f of a %.0f jump, blue %.1f of %.0f; plain cube max change %d\n", $bleedR, $jumpR, $bleedB, $jumpB, $plainDiff);
            self::assertGreaterThan(3.0, $bleedR, 'light bleeds into the dark side');
            self::assertGreaterThan($bleedB / max($jumpB, 1.0), $bleedR / max($jumpR, 1.0), 'red reaches further than blue');
            self::assertLessThanOrEqual(1, $plainDiff, 'without subsurface the edge stays hard');
        } finally {
            vio_destroy($ctx);
        }
    }

    /**
     * On the middle row: the channel's largest rise from the blur (where light bled into
     * the dark side), and the channel's step across the edge next to it in the plain
     * picture - the largest neighbour difference within 8 pixels of that spot.
     *
     * @return array{0: float, 1: float}
     */
    private function bleed(string $off, string $on, int $channel): array
    {
        $y = intdiv(self::SIZE, 2);
        $at = fn (string $img, int $x): int => ord($img[($y * self::SIZE + $x) * 4 + $channel]);
        $rise = 0;
        $spot = intdiv(self::SIZE, 2);
        for ($x = 1; $x < self::SIZE - 1; $x++) {
            $d = $at($on, $x) - $at($off, $x);
            if ($d > $rise) {
                $rise = $d;
                $spot = $x;
            }
        }
        $jump = 0;
        for ($x = max(1, $spot - 8); $x <= min(self::SIZE - 1, $spot + 8); $x++) {
            $jump = max($jump, abs($at($off, $x) - $at($off, $x - 1)));
        }
        return [(float) $jump, (float) $rise];
    }
}
