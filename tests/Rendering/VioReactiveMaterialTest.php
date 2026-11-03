<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Geometry\QuadMesh;
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
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * An opaque surface whose content moves while the surface stands still (scrolling
 * scanlines) has no motion vector, so temporal AA keeps blending last frame's stripes
 * into this frame's: streaks. Material::$reactive writes the reactive mask for it, the
 * resolve trusts the history less there, and a surface in front takes the mask away
 * again whatever the draw order.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioReactiveMaterialTest extends TestCase
{
    private const W = 160;
    private const H = 112;
    private const FRAMES = 24;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MeshRegistry::register('ground', PlaneMesh::generate(40.0, 40.0, 4));
        MeshRegistry::register('quad', QuadMesh::generate(1.0, 1.0));
        MeshRegistry::register('post', BoxMesh::generate(0.5, 3.0, 0.5));
        MaterialRegistry::register('ground', new Material(albedo: new Color(0.3, 0.32, 0.28), roughness: 0.9));
        // Ids chosen so the screen sorts BEFORE the post in front of it: the post is
        // drawn later and must take the mask away.
        MaterialRegistry::register('screen', new Material(albedo: new Color(0.2, 0.9, 0.7)));
        MaterialRegistry::register('screen_reactive', (new Material(albedo: new Color(0.2, 0.9, 0.7)))->withReactive(1.0));
        MaterialRegistry::register('z_post', new Material(albedo: new Color(0.8, 0.5, 0.3)));
        ProcModeRegistry::map('screen', 12);
        ProcModeRegistry::map('screen_reactive', 12);
        // Unlit scanlines, 6 per screen height, scrolling about 1.5 pixels per frame.
        ProcModeShaderRegistry::registerGlsl(12, '', "    if (u_proc_mode == 12) {\n"
            . "        float s = 0.5 + 0.5 * sin(v_uv.y * 37.7 - u_time * 72.0);\n"
            . "        frag_color = outputColor(u_albedo * (0.1 + 0.9 * s), alpha);\n"
            . "        return;\n");
    }

    protected function tearDown(): void
    {
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testTheMaskCoversTheReactiveSurfaceOnlyWhereItIsSeen(): void
    {
        $plain = $this->frames('screen');
        self::assertSame(0, max($plain['mask']), 'without the material value the opaque pass writes no mask');

        $reactive = $this->frames('screen_reactive');
        $mask = $reactive['mask'];
        // Centre of the screen, on the post in front of it, on the ground.
        self::assertGreaterThan(250, $mask[$this->index(60, 50)], 'screen');
        self::assertSame(0, $mask[$this->index(98, 50)], 'the post in front, drawn after the screen');
        self::assertSame(0, $mask[$this->index(80, 105)], 'ground');
    }

    public function testReactiveScanlinesStreakLessUnderTaa(): void
    {
        $reactive = $this->frames('screen_reactive');
        $plain = $this->frames('screen');
        $pixels = [];
        foreach ($reactive['mask'] as $i => $v) {
            if ($v > 250) {
                $pixels[] = $i;
            }
        }
        self::assertGreaterThan(500, count($pixels), 'the screen covers a region');

        $errPlain = self::meanError($plain['taa'], $plain['reference'], $pixels);
        $errReactive = self::meanError($reactive['taa'], $reactive['reference'], $pixels);
        $contrastRef = self::contrast($plain['reference'], $pixels);
        $contrastPlain = self::contrast($plain['taa'], $pixels);
        $contrastReactive = self::contrast($reactive['taa'], $pixels);
        fprintf(STDERR, "[reactive %s] streaks: mean |TAA - frame| %.2f -> %.2f (0..255), stripe contrast %.1f / %.1f of %.1f\n",
            $this->backendName(), $errPlain, $errReactive, $contrastPlain, $contrastReactive, $contrastRef);
        self::assertLessThan($errPlain * 0.8, $errReactive, 'the history smears the moving stripes less');
        self::assertGreaterThan($contrastPlain, $contrastReactive, 'the stripes keep more of their contrast');
    }

    /** @return array{taa: list<float>, reference: list<float>, mask: list<int>} */
    private function frames(string $screen): array
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->warmShaders();
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, bloom: false, hdr: false, antiAliasing: AntiAliasing::Taa));
        $t = 0.0;
        for ($i = 0; $i < self::FRAMES; $i++) {
            $t = $i / 60.0;
            $renderer->setAnimationTime($t);
            vio_begin($this->ctx);
            $renderer->beginFrame();
            $renderer->render(self::scene($screen));
            $renderer->endFrame();
            vio_end($this->ctx);
        }
        $mrt = $renderer->motionVectorTarget();
        $out = $renderer->temporalOutputTarget();
        self::assertNotNull($mrt, 'the temporal path ran');
        self::assertNotNull($out);
        $maskRaw = vio_read_render_target($mrt, -1, VioRenderer3D::REACTIVE_ATTACHMENT, ['raw' => true]);
        self::assertIsString($maskRaw);
        self::assertSame(self::W * self::H, strlen($maskRaw));
        $taaRaw = vio_read_render_target($out, -1, 0, ['raw' => true]);
        self::assertIsString($taaRaw);

        // This frame without any history, same time.
        $still = new VioRenderer3D($this->ctx, self::W, self::H);
        $still->warmShaders();
        $still->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, bloom: false, hdr: false, antiAliasing: AntiAliasing::Off));
        $still->setAnimationTime($t);
        $still->renderToImage(self::scene($screen), self::W, self::H);
        $reference = $still->renderToImage(self::scene($screen), self::W, self::H);

        return [
            'taa' => self::lumaHalf($taaRaw),
            'reference' => self::luma($reference),
            'mask' => array_values((array) unpack('C*', $maskRaw)),
        ];
    }

    private static function scene(string $screen): RenderCommandList
    {
        $l = new RenderCommandList();
        $l->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 1.5, 5.0), new Vec3(0.0, 1.5, 0.0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 100.0),
        ));
        $l->add(new SetAmbientLight(new Color(0.5, 0.55, 0.6), 0.5));
        $l->add(new SetDirectionalLight(new Vec3(-0.3, -1.0, -0.4), new Color(1.0, 0.95, 0.9), 1.0));
        $l->add(new DrawMesh('ground', 'ground', Mat4::identity()));
        $l->add(new DrawMesh('quad', $screen, Mat4::translation(0.0, 1.6, 0.0)->multiply(Mat4::scaling(3.2, 2.0, 1.0))));
        $l->add(new DrawMesh('post', 'z_post', Mat4::translation(0.6, 1.5, 1.0)));
        return $l;
    }

    private function index(int $x, int $y): int
    {
        return $y * self::W + $x;
    }

    private function backendName(): string
    {
        return $this->ctx !== null ? vio_backend_name($this->ctx) : '';
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     * @param list<int> $pixels
     */
    private static function meanError(array $a, array $b, array $pixels): float
    {
        $sum = 0.0;
        foreach ($pixels as $i) {
            $sum += abs($a[$i] - $b[$i]);
        }
        return $sum / count($pixels);
    }

    /**
     * Standard deviation of the luma: how much stripe is left.
     *
     * @param list<float> $a
     * @param list<int> $pixels
     */
    private static function contrast(array $a, array $pixels): float
    {
        $mean = 0.0;
        foreach ($pixels as $i) {
            $mean += $a[$i];
        }
        $mean /= count($pixels);
        $var = 0.0;
        foreach ($pixels as $i) {
            $var += ($a[$i] - $mean) ** 2;
        }
        return sqrt($var / count($pixels));
    }

    /** @return list<float> */
    private static function luma(string $rgba): array
    {
        $out = [];
        for ($i = 0, $n = strlen($rgba); $i < $n; $i += 4) {
            $out[] = 0.2126 * ord($rgba[$i]) + 0.7152 * ord($rgba[$i + 1]) + 0.0722 * ord($rgba[$i + 2]);
        }
        return $out;
    }

    /** @return list<float> luma 0..255 of an RGBA16F readback */
    private static function lumaHalf(string $raw): array
    {
        $h = array_values((array) unpack('v*', $raw));
        $out = [];
        for ($i = 0, $n = count($h); $i < $n; $i += 4) {
            $out[] = 255.0 * (0.2126 * self::unit($h[$i]) + 0.7152 * self::unit($h[$i + 1]) + 0.0722 * self::unit($h[$i + 2]));
        }
        return $out;
    }

    private static function unit(int $v): float
    {
        $e = ($v >> 10) & 31;
        $m = $v & 1023;
        $f = $e === 0 ? $m / 1024 * 2 ** -14 : (1 + $m / 1024) * 2 ** ($e - 15);
        return max(0.0, min(1.0, ($v >> 15) & 1 ? -$f : $f));
    }
}
