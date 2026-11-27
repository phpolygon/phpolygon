<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
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
 * The bindless table's `layout(set = 1, binding = N)` needs GL_ARB_shading_language_420pack under
 * #version 410. Only the light grid block added that extension, so a program without the grid
 * (PHPOLYGON_LIGHT_GRID=0, and the grid-less twin) failed to compile once bindless materials were on.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
#[Group('gpu')]
#[RunTestsInSeparateProcesses]
final class VioBindlessWithoutGridTest extends TestCase
{
    private const int W = 64;
    private const int H = 48;

    private ?\VioContext $ctx = null;

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_LIGHT_GRID');
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testBindlessMaterialsCompileWithoutTheGrid(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        putenv('PHPOLYGON_LIGHT_GRID=0');
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(2.0, 2.0, 2.0));
        MaterialRegistry::register('stone', new Material(albedo: new Color(0.7, 0.7, 0.7)));

        $renderer = new VioRenderer3D($ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false, bindlessMaterials: true));
        if (!$renderer->bindlessSupported()) {
            $this->markTestSkipped('this backend has no bindless materials');
        }
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 2.0, 6.0), new Vec3(0, 0, 0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(0.5, 0.5, 0.5), 1.0));
        $list->add(new DrawMesh('box', 'stone', Mat4::identity()));
        $image = $renderer->renderToImage($list, self::W, self::H, new Color(0.1, 0.2, 0.4, 1.0));

        self::assertTrue($renderer->bindlessMaterialsActive(), 'the bindless table was built');
        self::assertSame(self::W * self::H * 4, strlen($image));
        self::assertNotSame(str_repeat(chr(26) . chr(51) . chr(102) . chr(255), self::W * self::H), $image, 'the box is on screen');
    }
}
