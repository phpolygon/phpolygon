<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\VatBaker;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Vertex animation textures (shader plan 6.3): a box whose baked frames sit 1.5 m above
 * its rest pose is drawn up there - the vertex stage reads the positions from the
 * texture instead of the mesh.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioVatTest extends TestCase
{
    private const SIZE = 64;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_model' => 6, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!function_exists('vio_texture_units') || vio_texture_units($ctx)['units'] <= 17) {
            vio_destroy($ctx);
            $this->markTestSkipped('needs more than 17 texture units');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        ProcModeShaderRegistry::clear();
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
        ProcModeShaderRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testTheBakedPositionsMoveTheMesh(): void
    {
        self::assertNotNull($this->ctx);
        $box = BoxMesh::generate(1.0, 1.0, 1.0);
        MeshRegistry::register('box', $box);
        $up = $box->vertices;
        for ($i = 1; $i < count($up); $i += 3) {
            $up[$i] += 1.5;
        }
        $frame = new MeshData($up, $box->normals, $box->uvs, $box->indices);
        $bake = VatBaker::bake([$frame, $frame]);
        $tex = vio_texture($this->ctx, ['data' => $bake['rgba'], 'width' => $bake['width'], 'height' => $bake['height'], 'filter' => VIO_FILTER_NEAREST]);
        self::assertNotFalse($tex);
        MaterialRegistry::register('vat', new Material(
            albedo: new Color(1.0, 1.0, 1.0),
            roughness: 1.0,
            useEnvironmentMap: false,
            vatTexture: 'vat_box',
            vatInfo: [2.0, 1.0, ...$bake['min'], ...$bake['extent']],
        ));

        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->registerGpuTexture('vat_box', $tex);
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, ambientOcclusion: ScreenSpaceAO::Off, antiAliasing: AntiAliasing::Off, bloom: false));
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.75, 8.0), new Vec3(0.0, 0.75, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::orthographic(-2.0, 2.0, -2.0, 2.0, 1.0, 20.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
        $list->add(new DrawMesh('box', 'vat', Mat4::identity()));
        $img = $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));

        // World y 0 (rest pose centre) and y 1.5 (baked centre) on the 64-pixel image: row = 32 - (y - 0.75) * 16.
        $at = static fn (int $row): int => ord($img[($row * self::SIZE + 32) * 4]);        self::assertGreaterThan(100, $at(20), 'the box is drawn at its baked height');
        self::assertLessThan(30, $at(46), 'nothing is left at the rest pose');
    }
}
