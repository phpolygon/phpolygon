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
use PHPolygon\Rendering\Command\SetFieldtracing;
use PHPolygon\Rendering\Command\SetFieldtracingProbes;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\FieldtracingMode;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The probe field on the GPU, one texture with the R, G and B SH-L1 volumes stacked along
 * z: a white floor lit only by the ambient term of a field whose R block holds c0 = +1,
 * its B block c0 = +0.5 and its G block c0 = 0. The floor must come out red > blue > green;
 * a lookup that read the wrong block (or let filtering bleed across blocks) gives equal
 * channels or a swapped order.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioProbeFieldTest extends TestCase
{
    private const SIZE = 32;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!function_exists('vio_texture_3d')) {
            $this->markTestSkipped('no 3D textures');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(20.0, 0.1, 20.0));
        MaterialRegistry::register('floor', new Material(albedo: new Color(1.0, 1.0, 1.0), roughness: 1.0, useEnvironmentMap: false));
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

    public function testEachChannelReadsItsOwnBlock(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
            fieldtracing: FieldtracingMode::ProbesOnly,
        ));
        // 2 x 2 x 2 texels per block; c0 in R of each texel, c1..c3 encoded zero (128).
        $block = static fn (int $c0): string => str_repeat(pack('C4', $c0, 128, 128, 128), 8);
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 10.0, 0.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)),
            Mat4::orthographic(-5.0, 5.0, -5.0, 5.0, 1.0, 50.0),
        ));
        // Black ambient colour at intensity 1: the flat term vanishes, the probe term
        // (scaled by the intensity, not the colour) is all that lights the floor.
        $list->add(new SetAmbientLight(new Color(0.0, 0.0, 0.0), 1.0));
        $list->add(new SetFieldtracing(FieldtracingMode::ProbesOnly, 1.0));
        $list->add(new SetFieldtracingProbes(
            $block(255), $block(128), $block(191),
            2, 2, 2,
            new Vec3(-10.0, -5.0, -10.0), new Vec3(20.0, 10.0, 20.0),
            1.0, 1,
        ));
        $list->add(new DrawMesh('floor', 'floor', Mat4::identity()));
        $image = $renderer->renderToImage($list, self::SIZE, self::SIZE);

        $o = ((self::SIZE / 2) * self::SIZE + self::SIZE / 2) * 4;
        [$r, $g, $b] = [ord($image[$o]), ord($image[$o + 1]), ord($image[$o + 2])];
        self::assertGreaterThan($b, $r, "red block above blue ({$r}, {$g}, {$b})");
        self::assertGreaterThan($g, $b, "blue block above green ({$r}, {$g}, {$b})");
    }
}
