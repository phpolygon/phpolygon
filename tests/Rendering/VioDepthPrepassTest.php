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
 * Depth prepass (shader plan 9.1): overlapping boxes drawn far-to-near look exactly
 * the same with the prepass as without - the prepass writes the same depth the
 * shading pass then meets with LEQUAL.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioDepthPrepassTest extends TestCase
{
    private const SIZE = 64;

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
        MeshRegistry::register('box', BoxMesh::generate(1.5, 1.5, 1.5));
        MaterialRegistry::register('a', new Material(albedo: new Color(0.8, 0.2, 0.1), roughness: 0.6, useEnvironmentMap: false));
        MaterialRegistry::register('b', new Material(albedo: new Color(0.1, 0.6, 0.9), roughness: 0.4, useEnvironmentMap: false));
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

    public function testThePrepassChangesNothingInTheImage(): void
    {
        $off = $this->render(false);
        $on = $this->render(true);
        $max = 0;
        for ($i = 0; $i < strlen($off); $i++) {
            $max = max($max, abs(ord($off[$i]) - ord($on[$i])));
        }
        self::assertLessThanOrEqual(2, $max, "prepass image differs by {$max}");
        self::assertNotSame(str_repeat("\0", strlen($off)), $off);
    }

    private function render(bool $prepass): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
            depthPrepass: $prepass,
        ));
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 2.0, 6.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(1.0, 1.0, 0.1, 50.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.4));
        $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.3), new Color(1.0, 1.0, 1.0), 1.0));
        $list->add(new DrawMesh('box', 'a', Mat4::translation(0.0, 0.0, -2.0)));
        $list->add(new DrawMesh('box', 'b', Mat4::translation(0.6, 0.2, 0.0)));
        $list->add(new DrawMesh('box', 'a', Mat4::translation(-0.6, -0.3, 1.0)));

        return $renderer->renderToImage($list, self::SIZE, self::SIZE);
    }
}
