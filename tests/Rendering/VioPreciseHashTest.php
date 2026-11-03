<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
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
 * Value noise of the vio mesh shader is continuous across its cells. noise() reads
 * each lattice point from both cells beside it; the sine hash turns one ulp of its
 * dot product into a different value, so a compiler that rounds the two reads
 * differently breaks the noise at every cell edge. On Vulkan (RTX 2080, php-vio 2.40.3)
 * it did, and the bump / noise normal patterns read as per-pixel grain on wide ground.
 * A snippet paints noise() as grey over a wall, about 18 px per cell, and the test
 * counts the jumps between neighbouring pixels that smooth noise cannot make.
 * Measured 2026-11-03 (php-vio 2.40.3), jumps with the plain / pinned hash: Vulkan
 * RTX 2080 283 / 0, D3D12 RTX 2080 55 / 0, D3D12 WARP 0 / 0.
 */
final class VioPreciseHashTest extends TestCase
{
    private const SIZE = 128;

    /** Grey step between neighbours that smooth noise at ~18 px per cell stays under. */
    private const JUMP = 40;

    private ?\VioContext $ctx = null;

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_PRECISE_HASH');
        putenv('PHPOLYGON_VIO_MRT');
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testThePinnedHashIsOnForVulkanOnly(): void
    {
        self::assertTrue(VioRenderer3D::preciseHashOn('vulkan'));
        self::assertFalse(VioRenderer3D::preciseHashOn('d3d12'), 'Direct3D keeps its frames byte for byte');
        self::assertFalse(VioRenderer3D::preciseHashOn('d3d11'));
        self::assertFalse(VioRenderer3D::preciseHashOn('metal'), 'unmeasured');
    }

    public function testTheOverrideSwitchesItForEveryBackend(): void
    {
        putenv('PHPOLYGON_PRECISE_HASH=1');
        self::assertTrue(VioRenderer3D::preciseHashOn('d3d12'));
        self::assertTrue(VioRenderer3D::preciseHashOn('metal'));
        putenv('PHPOLYGON_PRECISE_HASH=0');
        self::assertFalse(VioRenderer3D::preciseHashOn('vulkan'));
    }

    #[RequiresPhpExtension('vio')]
    #[Group('native-gpu')]
    public function testValueNoiseHasNoJumpsAtCellEdges(): void
    {
        $this->openContext();
        self::assertNotNull($this->ctx);
        $backend = vio_backend_name($this->ctx);
        $frames = [];
        foreach (['default' => null, 'pinned' => '1', 'plain' => '0'] as $case => $env) {
            putenv($env === null ? 'PHPOLYGON_PRECISE_HASH' : "PHPOLYGON_PRECISE_HASH={$env}");
            $frames[$case] = $this->noiseFrame();
        }
        putenv('PHPOLYGON_PRECISE_HASH');
        $plain = self::jumps($frames['plain']);
        self::assertGreaterThan(1000, self::spread($frames['pinned']), "{$backend}: the wall shows the noise");
        self::assertSame(0, self::jumps($frames['pinned']), "{$backend}: pinned hash, no jump at a cell edge (plain hash: {$plain})");
        if (VioRenderer3D::preciseHashOn($backend)) {
            self::assertSame(0, self::jumps($frames['default']), "{$backend}: the gate pins the hash");
        } else {
            self::assertSame(md5($frames['plain']), md5($frames['default']), "{$backend}: without the gate the frame is the plain hash's");
        }
    }

    private function openContext(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (vio_backend_name($ctx) === 'opengl') {
            vio_destroy($ctx);
            $this->markTestSkipped('the OpenGL family compiles its own mesh shader');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('wall', BoxMesh::generate(40.0, 40.0, 0.1));
        MaterialRegistry::register('noise_grey', new Material(albedo: new Color(0.5, 0.5, 0.5)));
        ProcModeRegistry::map('noise_grey', 3);
        // Far from the origin, as a world of a few hundred metres puts its ground: the
        // dot product inside the hash lies near 1e6 there.
        ProcModeShaderRegistry::registerGlsl(3, '', "    if (u_proc_mode == 3) {\n"
            . "        frag_color = outputColor(vec3(noise(v_worldPos.xy * 2.0 + vec2(1500.0, 1500.0))), alpha);\n"
            . "        return;\n    } else");
    }

    /** RGBA of the wall, painted with the snippet's noise, on a fresh renderer (new compile). */
    private function noiseFrame(): string
    {
        self::assertNotNull($this->ctx);
        putenv('PHPOLYGON_VIO_MRT=0');
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false));
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::translation(0.0, 0.0, -3.0), Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 100.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.3));
        $list->add(new DrawMesh('wall', 'noise_grey', Mat4::identity()));
        // The second frame is read: the OpenGL backend reads back black on a renderer's first.
        $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0, 1.0));
        return $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0, 1.0));
    }

    /** Sum of |red - mean red| over the frame: zero for a flat wall. */
    private static function spread(string $rgba): float
    {
        $reds = [];
        for ($i = 0, $n = strlen($rgba); $i < $n; $i += 4) {
            $reds[] = ord($rgba[$i]);
        }
        $mean = array_sum($reds) / count($reds);
        return array_sum(array_map(static fn (int $r): float => abs($r - $mean), $reds));
    }

    /** Neighbouring pixel pairs (right and below) whose red channel differs by more than JUMP. */
    private static function jumps(string $rgba): int
    {
        $n = 0;
        for ($y = 0; $y < self::SIZE - 1; $y++) {
            for ($x = 0; $x < self::SIZE - 1; $x++) {
                $v = ord($rgba[($y * self::SIZE + $x) * 4]);
                $right = ord($rgba[($y * self::SIZE + $x + 1) * 4]);
                $below = ord($rgba[(($y + 1) * self::SIZE + $x) * 4]);
                $n += (int) (abs($v - $right) > self::JUMP) + (int) (abs($v - $below) > self::JUMP);
            }
        }
        return $n;
    }
}
