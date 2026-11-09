<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
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
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * An effect switched off in the graphics settings leaves the mesh programs: a snippet
 * paints white inside `#ifndef PHPOLYGON_EFFECT_OFF_TEST_GLOW` and black outside it.
 * With the effect on the wall is white, switched off (applySettings, next frame) it
 * is black, switched on again white - on the base program and on a shader variant.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioEffectSwitchTest extends TestCase
{
    private const SIZE = 32;

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
        CubemapRegistry::clear();
        MeshRegistry::register('wall', BoxMesh::generate(40.0, 40.0, 0.1));
        foreach (['glow_base' => 3, 'glow_variant' => 5] as $material => $mode) {
            MaterialRegistry::register($material, new Material(albedo: new Color(0.5, 0.5, 0.5)));
            ProcModeRegistry::map($material, $mode);
            ProcModeShaderRegistry::registerGlsl($mode, '', "    if (u_proc_mode == {$mode}) {\n"
                . "        vec3 glow = vec3(0.0);\n"
                . "#ifndef PHPOLYGON_EFFECT_OFF_TEST_GLOW\n"
                . "        glow = vec3(1.0);\n"
                . "#endif // PHPOLYGON_EFFECT_OFF_TEST_GLOW\n"
                . "        frag_color = outputColor(glow, alpha);\n        return;\n    } else");
        }
        ProcModeShaderRegistry::defineVariant('glowing', [5]);
    }

    protected function tearDown(): void
    {
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

    /** @return iterable<string, array{string}> */
    public static function programs(): iterable
    {
        yield 'base program' => ['glow_base'];
        yield 'shader variant' => ['glow_variant'];
    }

    #[DataProvider('programs')]
    public function testSwitchingAnEffectOffCompilesItOut(string $material): void
    {
        self::assertNotNull($this->ctx);
        putenv('PHPOLYGON_VIO_MRT=0');
        $on = new GraphicsSettings(shadowQuality: ShadowQuality::Off, bloom: false);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings($on);
        $backend = vio_backend_name($this->ctx);

        self::assertGreaterThan(200, $this->centre($renderer, $material), "{$backend}: effect on paints white");

        $renderer->applySettings($on->with(effects: ['test_glow' => false]));
        self::assertSame(['PHPOLYGON_EFFECT_OFF_TEST_GLOW'], $renderer->effectShaderDefines());
        self::assertLessThan(10, $this->centre($renderer, $material), "{$backend}: effect off is compiled out");

        $renderer->applySettings($on->with(effects: ['test_glow' => true]));
        self::assertSame([], $renderer->effectShaderDefines());
        self::assertGreaterThan(200, $this->centre($renderer, $material), "{$backend}: effect on again");
    }

    /** Red channel (0..255) of the centre pixel. */
    private function centre(VioRenderer3D $renderer, string $material): int
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::translation(0.0, 0.0, -3.0), Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 100.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.3));
        $list->add(new DrawMesh('wall', $material, Mat4::identity()));
        // The second frame is read: the OpenGL backend reads back black on a renderer's first.
        $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0, 1.0));
        $rgba = $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0, 1.0));
        $half = self::SIZE >> 1;
        return ord($rgba[($half * self::SIZE + $half) * 4]);
    }
}
