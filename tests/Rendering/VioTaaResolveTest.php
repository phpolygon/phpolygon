<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
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
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Quality\UpscaleQuality;
use PHPolygon\Rendering\Quality\Upscaler;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The temporal resolve (VioTaaPass) against 4x supersampled references of the
 * same frame: a static view converges to the supersampled image, temporal
 * upsampling from half resolution keeps up with a rotating camera, a moved
 * object leaves no trail after eight frames, and a cut starts over without
 * the old history. The resolve output is read back raw (FP16) and compared
 * by SSIM on luma.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioTaaResolveTest extends TestCase
{
    private const W = 256;
    private const H = 192;

    private ?\VioContext $ctx = null;
    private string $backend = '';

    /** @return iterable<string, array{0: string}> */
    public static function backends(): iterable
    {
        foreach (['d3d12', 'd3d11', 'vulkan', 'opengl'] as $backend) {
            yield $backend => [$backend];
        }
    }

    protected function tearDown(): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    #[DataProvider('backends')]
    public function testStaticViewConvergesToTheSupersampledReference(string $backend): void
    {
        $this->prepare($backend);
        $scene = self::scene(0.0, 1.0);
        $reference = $this->supersampled($scene);
        $this->open();
        $plain = $this->renderer(AntiAliasing::Off);
        $plain->renderToImage($scene, self::W, self::H);
        $aliased = self::luma($plain->renderToImage($scene, self::W, self::H));

        $taa = $this->renderer(AntiAliasing::Taa);
        for ($i = 0; $i < 24; $i++) {
            $this->frame($taa, $scene);
        }
        $resolved = $this->resolved($taa);

        $ssimTaa = self::ssim($resolved, $reference);
        $ssimOff = self::ssim($aliased, $reference);
        fprintf(STDERR, "[taa %s] static SSIM taa %.4f, no AA %.4f\n", $backend, $ssimTaa, $ssimOff);
        $this->assertGreaterThanOrEqual(0.98, $ssimTaa, 'TAA vs 4x SSAA');
        $this->assertGreaterThan($ssimOff, $ssimTaa, 'TAA is closer to the reference than no AA');
    }

    #[DataProvider('backends')]
    public function testHalfResolutionUpsamplingFollowsARotatingCamera(string $backend): void
    {
        $this->prepare($backend);
        $reference = $this->supersampled(self::scene(29 * 0.4, 1.0));
        $this->open();
        $taau = $this->renderer(AntiAliasing::Off, Upscaler::Taau, UpscaleQuality::Performance);
        for ($i = 0; $i < 30; $i++) {
            $this->frame($taau, self::scene($i * 0.4, 1.0));
        }
        $frame = $taau->temporalFrame();
        $this->assertNotNull($frame);
        $this->assertSame(intdiv(self::W, 2), $frame->renderWidth, 'renders at half resolution');
        $this->assertSame(self::W, $frame->displayWidth, 'resolves at display resolution');

        $ssim = self::ssim($this->resolved($taau), $reference);
        fprintf(STDERR, "[taa %s] TAAU 0.5 rotating SSIM %.4f\n", $backend, $ssim);
        $this->assertGreaterThanOrEqual(0.95, $ssim);
    }

    #[DataProvider('backends')]
    public function testMovedObjectLeavesNoTrailAfterEightFrames(string $backend): void
    {
        $this->prepare($backend);
        [$before] = $this->supersampledRgb(self::scene(0.0, 1.0));
        [$after, $afterRgb] = $this->supersampledRgb(self::scene(0.0, -1.6));
        [$empty] = $this->supersampledRgb(self::scene(0.0, 99.0));
        $this->open();
        $taa = $this->renderer(AntiAliasing::Taa);
        for ($i = 0; $i < 16; $i++) {
            $this->frame($taa, self::scene(0.0, 1.0));
        }
        $this->frame($taa, self::scene(0.0, -1.6, prevBoxX: 1.0));
        for ($i = 0; $i < 7; $i++) {
            $this->frame($taa, self::scene(0.0, -1.6));
        }
        $resolvedRgb = $this->resolvedRgb($taa);

        // Pixels the box left: it covered them before, now they show what is
        // behind it. A trail is the box's orange (R high, B low) still in
        // them; how well the revealed background is anti-aliased after eight
        // frames is not ghosting.
        $sum = 0.0;
        $n = 0;
        foreach ($after as $i => $v) {
            if (abs($before[$i] - $v) > 30.0 && abs($empty[$i] - $v) < 4.0) {
                [$r, , $b] = $resolvedRgb[$i];
                [$er, , $eb] = $afterRgb[$i];
                $sum += max(0.0, ($r - $b) - ($er - $eb));
                $n++;
            }
        }
        $this->assertGreaterThan(100, $n, 'the box vacated a region');
        $ghost = $sum / $n / 255.0;
        fprintf(STDERR, "[taa %s] ghosting after 8 frames %.3f%% over %d px\n", $backend, $ghost * 100.0, $n);
        $this->assertLessThan(0.02, $ghost);
    }

    #[DataProvider('backends')]
    public function testCutStartsOverWithoutHistory(string $backend): void
    {
        $this->prepare($backend);
        $this->open();
        $taa = $this->renderer(AntiAliasing::Taa);
        for ($i = 0; $i < 10; $i++) {
            $this->frame($taa, self::scene(0.0, 1.0));
        }
        $this->frame($taa, self::scene(50.0, 1.0, cut: true));
        $frame = $taa->temporalFrame();
        $this->assertNotNull($frame);
        $this->assertFalse($frame->historyValid);
        $afterCut = $this->resolved($taa);

        // The same frame without any history: a renderer that cuts on every
        // frame reaches the same jitter phase but never accumulates.
        $fresh = $this->renderer(AntiAliasing::Taa);
        for ($i = 0; $i < 11; $i++) {
            $this->frame($fresh, self::scene(50.0, 1.0, cut: true));
        }
        $expected = $this->resolved($fresh);
        $diff = 0.0;
        foreach ($expected as $i => $v) {
            $diff = max($diff, abs($afterCut[$i] - $v));
        }
        fprintf(STDERR, "[taa %s] cut frame vs history-free frame: max luma diff %.3f
", $backend, $diff);
        $this->assertLessThan(1.0, $diff, 'the cut frame is a first frame, not a blend with the old view');
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** Skip when the backend is missing; register the scene's meshes and materials. */
    private function prepare(string $backend): void
    {
        $ctx = @vio_create($backend, ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false]);
        $ok = $ctx !== false && vio_backend_name($ctx) === $backend;
        if ($ctx !== false) {
            vio_destroy($ctx);
        }
        if (!$ok) {
            $this->markTestSkipped("$backend: no headless context on this machine");
        }
        $this->backend = $backend;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('ground', PlaneMesh::generate(80.0, 80.0, 4));
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MeshRegistry::register('pole', BoxMesh::generate(0.12, 3.0, 0.12));
        MeshRegistry::register('wall', BoxMesh::generate(80.0, 40.0, 0.2));
        MaterialRegistry::register('ground', new Material(albedo: new Color(0.25, 0.3, 0.22), roughness: 0.9));
        MaterialRegistry::register('white', new Material(albedo: new Color(0.9, 0.9, 0.88)));
        MaterialRegistry::register('orange', new Material(albedo: new Color(0.95, 0.45, 0.1)));
        MaterialRegistry::register('wall', new Material(albedo: new Color(0.35, 0.45, 0.6), roughness: 0.9));
    }

    /** The context the temporal renderers draw in (one context at a time: references come first). */
    private function open(): void
    {
        $ctx = vio_create($this->backend, ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false]);
        $this->assertNotFalse($ctx);
        $this->ctx = $ctx;
    }

    private function renderer(AntiAliasing $aa, Upscaler $upscaler = Upscaler::Off, UpscaleQuality $quality = UpscaleQuality::Custom): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        // Compile outside a frame: a cold renderer that compiles inside its
        // first frame drops that frame's geometry on OpenGL.
        $renderer->warmShaders();
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            bloom: false,
            hdr: false,
            antiAliasing: $aa,
            upscaler: $upscaler,
            upscaleQuality: $quality,
        ));
        return $renderer;
    }

    private function frame(VioRenderer3D $renderer, RenderCommandList $list): void
    {
        self::assertNotNull($this->ctx);
        vio_begin($this->ctx);
        $renderer->beginFrame();
        $renderer->render($list);
        $renderer->endFrame();
        vio_end($this->ctx);
    }

    private static function scene(float $yaw, float $boxX, ?float $prevBoxX = null, bool $cut = false): RenderCommandList
    {
        $list = new RenderCommandList();
        $eye = new Vec3(0.0, 1.8, 6.0);
        $r = deg2rad($yaw);
        $list->add(new SetCamera(
            Mat4::lookAt($eye, new Vec3(sin($r) * 6.0, 1.0, $eye->z - cos($r) * 6.0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(55.0), self::W / self::H, 0.1, 200.0),
            $cut,
        ));
        $list->add(new SetAmbientLight(new Color(0.55, 0.6, 0.7), 0.5));
        $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.5), new Color(1.0, 0.95, 0.85), 1.2));
        // No SetSky: the forward sky of the reference and the MRT sky of the
        // temporal path are not graded alike on every backend; a back wall
        // closes the view instead.
        $list->add(new DrawMesh('wall', 'wall', Mat4::translation(0.0, 0.0, -12.0)));
        $list->add(new DrawMesh('ground', 'ground', Mat4::identity()));
        for ($i = -5; $i <= 5; $i++) {
            $list->add(new DrawMesh('pole', 'white', Mat4::translation($i * 0.55, 1.5, -1.0)));
        }
        for ($i = 0; $i < 4; $i++) {
            $list->add(new DrawMesh('box', 'white', Mat4::translation(-3.0 + $i * 2.0, 0.5, -4.0)->multiply(Mat4::rotationY(0.5 + $i * 0.35))));
        }
        $prev = $prevBoxX !== null ? Mat4::translation($prevBoxX, 0.7, 1.5) : null;
        $list->add(new DrawMesh('box', 'orange', Mat4::translation($boxX, 0.7, 1.5), prevModelMatrix: $prev));
        return $list;
    }

    /** @return list<float> luma of the 4x supersampled frame (rendered at 2x2 size, box-filtered) */
    private function supersampled(RenderCommandList $scene): array
    {
        return $this->supersampledRgb($scene)[0];
    }

    /** @return array{0: list<float>, 1: list<array{0: float, 1: float, 2: float}>} luma and RGB (0..255) of the 4x supersampled frame */
    private function supersampledRgb(RenderCommandList $scene): array
    {
        // Its own context at twice the size: the renderer sizes its scene
        // viewport from the context's framebuffer.
        $ctx = vio_create($this->backend, ['width' => self::W * 2, 'height' => self::H * 2, 'headless' => true, 'vsync' => false]);
        $this->assertNotFalse($ctx);
        try {
            $renderer = new VioRenderer3D($ctx, self::W * 2, self::H * 2);
            $renderer->warmShaders();
            $renderer->applySettings(new GraphicsSettings(
                shadowQuality: ShadowQuality::Off,
                bloom: false,
                hdr: false,
                antiAliasing: AntiAliasing::Off,
            ));
            // The first image of a fresh renderer loses its geometry on
            // OpenGL; the second is the reference.
            $renderer->renderToImage($scene, self::W * 2, self::H * 2);
            $big = $renderer->renderToImage($scene, self::W * 2, self::H * 2);
        } finally {
            vio_destroy($ctx);
        }
        $this->assertSame(self::W * self::H * 16, strlen($big));
        $luma = [];
        $rgb = [];
        $row = self::W * 2 * 4;
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $o = 2 * $y * $row + 2 * $x * 4;
                $c = [0.0, 0.0, 0.0];
                foreach ([$o, $o + 4, $o + $row, $o + $row + 4] as $k) {
                    for ($ch = 0; $ch < 3; $ch++) {
                        $c[$ch] += ord($big[$k + $ch]) / 4.0;
                    }
                }
                $rgb[] = $c;
                $luma[] = 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
            }
        }
        return [$luma, $rgb];
    }

    /** @return list<float> luma (0..255) of the resolve output, top-down */
    private function resolved(VioRenderer3D $renderer): array
    {
        $out = [];
        foreach ($this->resolvedRgb($renderer) as [$r, $g, $b]) {
            $out[] = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        }
        return $out;
    }

    /** @return list<array{0: float, 1: float, 2: float}> RGB (0..255) of the resolve output, top-down */
    private function resolvedRgb(VioRenderer3D $renderer): array
    {
        $target = $renderer->temporalOutputTarget();
        $this->assertNotNull($target, 'the temporal resolve ran');
        $raw = vio_read_render_target($target, -1, 0, ['raw' => true]);
        $this->assertIsString($raw);
        $this->assertSame(self::W * self::H * 8, strlen($raw));
        $h = array_values(unpack('v*', $raw) ?: []);
        $out = [];
        for ($i = 0, $n = count($h); $i < $n; $i += 4) {
            $out[] = [
                min(1.0, max(0.0, self::half($h[$i]))) * 255.0,
                min(1.0, max(0.0, self::half($h[$i + 1]))) * 255.0,
                min(1.0, max(0.0, self::half($h[$i + 2]))) * 255.0,
            ];
        }
        return $out;
    }

    /** @return list<float> */
    private static function luma(string $rgba, int $w = self::W, int $h = self::H): array
    {
        $out = [];
        for ($i = 0, $n = $w * $h; $i < $n; $i++) {
            $o = $i * 4;
            $out[] = 0.2126 * ord($rgba[$o]) + 0.7152 * ord($rgba[$o + 1]) + 0.0722 * ord($rgba[$o + 2]);
        }
        return $out;
    }

    /**
     * Mean SSIM over 8x8 windows (stride 4) on 0..255 luma.
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function ssim(array $a, array $b): float
    {
        $c1 = (0.01 * 255) ** 2;
        $c2 = (0.03 * 255) ** 2;
        $sum = 0.0;
        $count = 0;
        for ($y = 0; $y + 8 <= self::H; $y += 4) {
            for ($x = 0; $x + 8 <= self::W; $x += 4) {
                $ma = $mb = $va = $vb = $cov = 0.0;
                for ($j = 0; $j < 8; $j++) {
                    for ($i = 0; $i < 8; $i++) {
                        $k = ($y + $j) * self::W + $x + $i;
                        $ma += $a[$k];
                        $mb += $b[$k];
                    }
                }
                $ma /= 64.0;
                $mb /= 64.0;
                for ($j = 0; $j < 8; $j++) {
                    for ($i = 0; $i < 8; $i++) {
                        $k = ($y + $j) * self::W + $x + $i;
                        $da = $a[$k] - $ma;
                        $db = $b[$k] - $mb;
                        $va += $da * $da;
                        $vb += $db * $db;
                        $cov += $da * $db;
                    }
                }
                $va /= 63.0;
                $vb /= 63.0;
                $cov /= 63.0;
                $sum += ((2 * $ma * $mb + $c1) * (2 * $cov + $c2)) / (($ma * $ma + $mb * $mb + $c1) * ($va + $vb + $c2));
                $count++;
            }
        }
        return $sum / $count;
    }

    private static function half(int $h): float
    {
        $sign = ($h & 0x8000) !== 0 ? -1.0 : 1.0;
        $exp = ($h >> 10) & 0x1f;
        $mant = $h & 0x3ff;
        if ($exp === 0) {
            return $sign * $mant * 2.0 ** -24;
        }
        if ($exp === 31) {
            return $mant === 0 ? $sign * INF : NAN;
        }
        return $sign * (1.0 + $mant / 1024.0) * 2.0 ** ($exp - 15);
    }
}
