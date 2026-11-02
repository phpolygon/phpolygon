<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Fieldtracing\Bake\SdfVolumeBaker;
use PHPolygon\Fieldtracing\Sdf\BoxSdf;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Math\Vec4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFieldtracingVolume;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\FieldtracingMode;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ScreenSpaceReflections;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The G-buffer effects on every backend that runs them, measured against Direct3D 12,
 * where they always ran. A box rests on a floor in the upper half of the frame; each
 * effect is the difference between a frame with it and one without (SSAO High against
 * Low, SSR High against Off on a mirror floor, SDF occlusion against probes only). Its
 * centre and size must be those of D3D12: a Y flip moves the centre to the lower half,
 * a missing pass leaves nothing, a wrong depth range spreads it. Forward and MRT.
 * PHPOLYGON_TEST_VIO_BACKEND / PHPOLYGON_TEST_VIO_HARDWARE pick the context.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioScreenSpaceParityTest extends TestCase
{
    private const W = 160;
    private const H = 120;

    /**
     * Centre column, centre row and pixel count of each effect on D3D12 (WARP), the
     * reference. Measured with this test on 2026-11-02, php-vio 2.39.0.
     *
     * @var array<string, array{0: float, 1: float, 2: int}>
     */
    private const D3D12 = [
        // D3D12 on the RTX 2080 measured 81.0/32.7, 727 px (SSAO) and the same SSR.
        'ssao-0' => [80.8, 32.0, 765],
        'ssao-1' => [80.8, 32.0, 767],
        'ssr-0' => [79.5, 48.3, 236],
        'ssr-1' => [79.5, 48.3, 236],
        // This volume darkens nothing on D3D12 either, so it pins only that no backend
        // invents an effect; SDF-AO itself is checked in a game's comparison images.
        'sdfao-0' => [-1.0, -1.0, 0],
        'sdfao-1' => [-1.0, -1.0, 0],
    ];

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (vio_backend_name($ctx) === 'opengl') {
            vio_destroy($ctx);
            $this->markTestSkipped('the OpenGL backend keeps the forward surrogates');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(20.0, 0.2, 20.0));
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('floor_matte', new Material(albedo: new Color(0.7, 0.7, 0.7), roughness: 0.9, useEnvironmentMap: false));
        MaterialRegistry::register('floor_mirror', new Material(albedo: new Color(0.2, 0.2, 0.2), roughness: 0.05, metallic: 1.0, useEnvironmentMap: false));
        MaterialRegistry::register('box_grey', new Material(albedo: new Color(0.6, 0.6, 0.6), roughness: 0.9));
        MaterialRegistry::register('box_red', new Material(albedo: new Color(0.9, 0.05, 0.05), emission: new Color(3.0, 0.0, 0.0)));
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        putenv('PHPOLYGON_VIO_PARITY');
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    private static function camera(): array
    {
        return [
            Mat4::lookAt(new Vec3(0.0, 1.2, 4.0), new Vec3(0.0, -2.4, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 100.0),
        ];
    }

    private static function scene(string $floor, string $box, bool $volume = false): RenderCommandList
    {
        [$view, $proj] = self::camera();
        $list = new RenderCommandList();
        $list->add(new SetCamera($view, $proj));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.12));
        $list->add(new SetDirectionalLight(new Vec3(0.0, -1.0, 0.0), new Color(1.0, 1.0, 1.0), 0.1));
        $list->add(new DrawMesh('floor', $floor, Mat4::translation(0.0, -1.1, 0.0)));
        $list->add(new DrawMesh('box', $box, Mat4::translation(0.0, -0.5, 0.0)));
        if ($volume) {
            $vol = SdfVolumeBaker::bake(new BoxSdf(new Vec3(0.0, -0.5, 0.0), new Vec3(1.0, 1.0, 1.0)), new Vec3(-3.0, -1.2, -3.0), new Vec3(3.0, 1.5, 3.0), 32);
            $max = $vol->max();
            $list->add(new SetFieldtracingVolume(
                $vol->toRgba8(4.0), $vol->nx, $vol->ny, $vol->nz, $vol->origin,
                new Vec3($max->x - $vol->origin->x, $max->y - $vol->origin->y, $max->z - $vol->origin->z), 4.0, 1,
            ));
        }
        return $list;
    }

    /** @return list<float> luminance per pixel of the presented frame */
    private function frame(GraphicsSettings $settings, RenderCommandList $list, int $channel = -1): array
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings($settings);
        for ($i = 0; $i < 3; $i++) {
            vio_begin($this->ctx);
            $renderer->beginFrame();
            $renderer->clear(new Color(0.0, 0.0, 0.0, 1.0));
            $renderer->render($list);
            $renderer->endFrame();
            vio_end($this->ctx);
        }
        $rgba = (string) vio_read_pixels($this->ctx);
        self::assertSame(self::W * self::H * 4, strlen($rgba));
        $out = [];
        foreach (array_chunk(array_values((array) unpack('C*', $rgba)), 4) as $p) {
            $out[] = $channel >= 0 ? (float) $p[$channel] : 0.2126 * $p[0] + 0.7152 * $p[1] + 0.0722 * $p[2];
        }
        return $out;
    }

    /**
     * Centre (column, row) of the pixels where $b falls below $a by more than $min (or
     * rises above it, $sign = -1), and how many there are.
     *
     * @param list<float> $a
     * @param list<float> $b
     * @return array{0: float, 1: float, 2: int}
     */
    private static function centroid(array $a, array $b, float $min, int $sign = 1): array
    {
        $sx = 0.0;
        $sy = 0.0;
        $n = 0;
        foreach ($a as $i => $v) {
            if ($sign * ($v - $b[$i]) > $min) {
                $sx += $i % self::W;
                $sy += intdiv($i, self::W);
                $n++;
            }
        }
        return $n > 0 ? [$sx / $n, $sy / $n, $n] : [-1.0, -1.0, 0];
    }

    /**
     * The effect as on D3D12. Without a reference (D3D12 itself, before the numbers
     * were written down) the measured values are reported instead.
     *
     * @param array{0: float, 1: float, 2: int} $m
     */
    private function assertLikeDirect3D(string $key, array $m): void
    {
        $label = sprintf('%s: centre %.1f/%.1f, %d px', $key, $m[0], $m[1], $m[2]);
        $ref = self::D3D12[$key] ?? null;
        if ($ref === null) {
            $this->markTestIncomplete("no D3D12 reference, measured {$label}");
        }
        if ($ref[2] < 20) {
            // D3D12 shows nothing here either: parity is nothing.
            self::assertLessThan(20, $m[2], "{$label}: as on D3D12, no visible change");
            return;
        }
        self::assertGreaterThan(20, $m[2], "{$label}: the effect changes the frame");
        self::assertEqualsWithDelta($ref[0], $m[0], 3.0, "{$label}: column as on D3D12 ({$ref[0]})");
        self::assertEqualsWithDelta($ref[1], $m[1], 3.0, "{$label}: row as on D3D12 ({$ref[1]}), not mirrored");
        self::assertEqualsWithDelta($ref[2], $m[2], max(15.0, $ref[2] * 0.25), "{$label}: size as on D3D12 ({$ref[2]})");
    }

    private static function settings(ScreenSpaceAO $ao = ScreenSpaceAO::Off, ScreenSpaceReflections $ssr = ScreenSpaceReflections::Off, FieldtracingMode $ft = FieldtracingMode::Off): GraphicsSettings
    {
        return new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            // Bloom keeps the offscreen HDR target; FXAA without bloom reads a stale
            // texture on D3D12 and Metal (a known defect), so it stays out of the measurement.
            antiAliasing: AntiAliasing::Off,
            bloom: true,
            ambientOcclusion: $ao,
            ssr: $ssr,
            fieldtracing: $ft,
        );
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['forward' => ['0'], 'mrt' => ['1']];
    }

    #[DataProvider('paths')]
    public function testSsaoDarkensTheFoot(string $mrt): void
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        $list = self::scene('floor_matte', 'box_grey');
        $off = $this->frame(self::settings(ScreenSpaceAO::Low), $list);
        $on = $this->frame(self::settings(ScreenSpaceAO::High), $list);
        $this->assertLikeDirect3D("ssao-{$mrt}", self::centroid($off, $on, 6.0));
    }

    #[DataProvider('paths')]
    public function testSsrPutsTheBoxIntoTheFloor(string $mrt): void
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        $list = self::scene('floor_mirror', 'box_red');
        $off = $this->frame(self::settings(), $list, 0);
        $on = $this->frame(self::settings(ssr: ScreenSpaceReflections::High), $list, 0);
        $this->assertLikeDirect3D("ssr-{$mrt}", self::centroid($off, $on, 10.0, -1));
    }

    #[DataProvider('paths')]
    public function testSdfAoDarkensTheFoot(string $mrt): void
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        $list = self::scene('floor_matte', 'box_grey', volume: true);
        $off = $this->frame(self::settings(ft: FieldtracingMode::ProbesOnly), $list);
        $on = $this->frame(self::settings(ft: FieldtracingMode::SdfOcclusion), $list);
        $this->assertLikeDirect3D("sdfao-{$mrt}", self::centroid($off, $on, 4.0));
    }

    /** PHPOLYGON_VIO_PARITY=0: the former Direct3D-only gate, for A/B images. */
    public function testTheEscapeHatchRestoresTheFormerGate(): void
    {
        self::assertNotNull($this->ctx);
        if (in_array(vio_backend_name($this->ctx), ['d3d11', 'd3d12'], true)) {
            $this->markTestSkipped('Direct3D always had the passes');
        }
        putenv('PHPOLYGON_VIO_PARITY=0');
        $list = self::scene('floor_matte', 'box_grey');
        $off = $this->frame(self::settings(ScreenSpaceAO::Low), $list);
        $on = $this->frame(self::settings(ScreenSpaceAO::High), $list);
        self::assertLessThan(20, self::centroid($off, $on, 6.0)[2], 'no real SSAO without the parity gate');
    }
}
