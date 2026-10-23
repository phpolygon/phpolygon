<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetWaveAnimation;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * On the MRT path the attachments' alpha is coverage for the composite. A
 * procedural branch that ends the fragment early (`frag_color =
 * outputColor(c, alpha); return;`) computes its own alpha - a water surface
 * fades to 0 at the shore - and that alpha must not leak into the coverage:
 *
 * - opaque water (material alpha 1, blending off) wrote alpha 0 into the
 *   attachments, and the composite showed the clear colour: a black patch
 *   wherever the water was the nearest surface;
 * - transparent water blended the sun and ambient attachments with the
 *   MATERIAL alpha set at the top of main(), so a surface the branch made
 *   invisible still wrote "no sun, no ambient" over the geometry behind it.
 *
 * The scene is the case that surfaced it: a wave-animated water plane (proc
 * mode 2, vertex waves) 5 cm under the top of an opaque stone slab. While a
 * crest lifts it through the slab, the water's fragments win the depth test -
 * in some frames after a camera jump and not in others, which is why it looked
 * like a settling problem. Every frame of a full wave cycle is checked.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioMrtEarlyReturnAlphaTest extends TestCase
{
    private const W = 96;
    private const H = 64;
    private const FRAMES = 64;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = @vio_create('auto', ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!in_array(vio_backend_name($ctx), ['d3d11', 'd3d12'], true)) {
            vio_destroy($ctx);
            $this->markTestSkipped('MRT scene path engages on Direct3D only (G-buffer consumers)');
        }
        if (!defined('VIO_FEATURE_MRT') || !vio_supports_feature($ctx, VIO_FEATURE_MRT)) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no multiple render targets');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();

        MeshRegistry::register('slab', BoxMesh::generate(12.0, 1.0, 12.0));
        MeshRegistry::register('water', PlaneMesh::generate(12.0, 12.0, 48));
        MaterialRegistry::register('stone', new Material(albedo: new Color(0.3, 0.29, 0.26), roughness: 0.9));
        MaterialRegistry::register('test_water', new Material(albedo: new Color(0.1, 0.4, 0.5), alpha: 0.9));
        MaterialRegistry::register('test_water_opaque', new Material(albedo: new Color(0.1, 0.4, 0.5)));
        ProcModeRegistry::map('test_water', 2);
        // A water branch that, like a shoreline fade, ends with alpha 0.
        ProcModeShaderRegistry::registerGlsl(
            2,
            '',
            "if (u_proc_mode == 2) {\n        frag_color = outputColor(vec3(0.02, 0.3, 0.4), 0.0);\n        return;\n    } else ",
        );
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testInvisibleTransparentWaterLeavesTheSlabUntouchedInEveryFrame(): void
    {
        $reference = $this->renderFrames(null, mrt: true);
        $frames = $this->renderFrames('test_water', mrt: true);

        $ref = self::px($reference[0], intdiv(self::W, 2), intdiv(self::H, 2) + 8);
        self::assertGreaterThan(60, $ref[0], 'the reference shows the lit stone slab');

        foreach ($frames as $i => $rgba) {
            $diff = self::meanDiff($reference[$i], $rgba);
            self::assertLessThan(1.0, $diff, sprintf('frame %d after the jump: mean |slab - slab with invisible water| = %.2f', $i + 1, $diff));
        }
    }

    public function testOpaqueWaterNeverShowsTheClearColourInEveryFrame(): void
    {
        $forward = $this->renderFrames('test_water_opaque', mrt: false);
        $mrt = $this->renderFrames('test_water_opaque', mrt: true);

        $wet = 0;
        foreach ($mrt as $i => $rgba) {
            $diff = self::meanDiff($forward[$i], $rgba);
            self::assertLessThan(3.0, $diff, sprintf('frame %d after the jump: mean |forward - mrt| = %.2f', $i + 1, $diff));
            $wet += self::waterPixels($rgba);
            self::assertSame(0, self::blackPixels($rgba), sprintf('frame %d after the jump: no clear-colour (black) pixels', $i + 1));
        }
        self::assertGreaterThan(0, $wet, 'the wave crests do cross the slab top in some frames');
    }

    /**
     * Two frames far away, then the jump to the slab and FRAMES frames there.
     * The wave phase sweeps a full cycle so crests cross the slab top.
     *
     * @return list<string> RGBA of each frame after the jump
     */
    private function renderFrames(?string $waterMaterial, bool $mrt): array
    {
        self::assertNotNull($this->ctx);
        putenv('PHPOLYGON_VIO_MRT=' . ($mrt ? '1' : '0'));
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Medium,
            bloom: false,
        ));

        $far = Mat4::lookAt(new Vec3(400.0, 30.0, 400.0), new Vec3(420.0, 0.0, 380.0), new Vec3(0, 1, 0));
        $near = Mat4::lookAt(new Vec3(0.0, 3.0, 5.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0, 1, 0));
        $out = [];
        for ($i = -2; $i < self::FRAMES; $i++) {
            $renderer->setAnimationTime(0.0);
            $list = new RenderCommandList();
            $list->add(new SetCamera(
                $i < 0 ? $far : $near,
                Mat4::perspective(deg2rad(55.0), self::W / self::H, 0.1, 500.0),
                cut: $i === 0,
            ));
            $list->add(new SetAmbientLight(new Color(0.5, 0.5, 0.55), 0.6));
            $list->add(new SetDirectionalLight(new Vec3(-0.3, -1.0, -0.4), new Color(1.0, 0.95, 0.9), 1.2));
            $list->add(new SetWaveAnimation(
                enabled: true,
                amplitude: 0.4,
                frequency: 0.5,
                phase: max(0, $i) * (2.0 * M_PI / self::FRAMES) / 0.7,
            ));
            // Slab top at y = 0; the water sits 5 cm below it.
            $list->add(new DrawMesh('slab', 'stone', Mat4::translation(0.0, -0.5, 0.0)));
            if ($waterMaterial !== null) {
                $list->add(new DrawMesh('water', $waterMaterial, Mat4::translation(0.0, -0.05, 0.0)));
            }
            $rgba = $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0, 1.0));
            if ($i >= 0) {
                self::assertSame($mrt, $renderer->mrtActive(), 'SSAO on: PHPOLYGON_VIO_MRT picks the path');
                self::assertSame(self::W * self::H * 4, strlen($rgba));
                $out[] = $rgba;
            }
        }
        return $out;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function px(string $rgba, int $x, int $y): array
    {
        $o = ($y * self::W + $x) * 4;
        return [ord($rgba[$o]), ord($rgba[$o + 1]), ord($rgba[$o + 2])];
    }

    private static function meanDiff(string $a, string $b): float
    {
        $n = self::W * self::H;
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $o = $i * 4;
            for ($c = 0; $c < 3; $c++) {
                $sum += abs(ord($a[$o + $c]) - ord($b[$o + $c]));
            }
        }
        return $sum / ($n * 3);
    }

    /** Pixels of the slab area (lower half) at the clear colour. */
    private static function blackPixels(string $rgba): int
    {
        $count = 0;
        for ($y = intdiv(self::H, 2); $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                [$r, $g, $b] = self::px($rgba, $x, $y);
                if ($r + $g + $b < 12) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /** Pixels showing the teal water colour (blue clearly above red). */
    private static function waterPixels(string $rgba): int
    {
        $count = 0;
        for ($y = intdiv(self::H, 2); $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                [$r, , $b] = self::px($rgba, $x, $y);
                if ($b > $r + 30) {
                    $count++;
                }
            }
        }
        return $count;
    }
}
