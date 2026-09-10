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
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * A storage-buffer instanced draw whose instance count lives in a GPU-written
 * argument record: the renderer issues vio_draw_indirect(), so a record with
 * instanceCount 1 paints the box and a record with instanceCount 0 paints
 * nothing — the command's own instance count (the fallback bound) is not
 * consulted. That is the contract a GPU culling / compaction pass relies on.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRendererIndirectDrawTest extends TestCase
{
    public function testArgumentRecordDecidesWhatIsDrawn(): void
    {
        if (!function_exists('vio_draw_indirect')) {
            $this->markTestSkipped('php-vio without vio_draw_indirect (< 2.17)');
        }
        $w = 64;
        $h = 64;
        $ctx = @vio_create('auto', ['width' => $w, 'height' => $h, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!defined('VIO_FEATURE_INDIRECT_DRAW') || !vio_supports_feature($ctx, VIO_FEATURE_INDIRECT_DRAW)
            || !defined('VIO_FEATURE_VERTEX_STORAGE') || !vio_supports_feature($ctx, VIO_FEATURE_VERTEX_STORAGE)
        ) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no indirect draws / vertex storage buffers');
        }

        MeshRegistry::clear();
        MaterialRegistry::clear();
        $box = BoxMesh::generate(1.0, 1.0, 1.0);
        MeshRegistry::register('box', $box);
        MaterialRegistry::register('mat', new Material(albedo: new Color(0.9, 0.4, 0.15)));

        $renderer = new VioRenderer3D($ctx, $w, $h);
        self::assertTrue($renderer->indirectDrawAvailable());

        // One identity instance matrix (column-major) in a storage buffer, and
        // two argument records: {indexCount, instanceCount, firstIndex, baseVertex, firstInstance}.
        $matrices = vio_storage_buffer($ctx, ['data' => pack('f*', ...Mat4::identity()->toArray()), 'stride' => 4]);
        $indexCount = count($box->indices);
        $drawOne = vio_storage_buffer($ctx, ['data' => pack('V5', $indexCount, 1, 0, 0, 0), 'stride' => 4, 'indirect' => true]);
        $drawNone = vio_storage_buffer($ctx, ['data' => pack('V5', $indexCount, 0, 0, 0, 0), 'stride' => 4, 'indirect' => true]);
        self::assertNotFalse($matrices);
        self::assertNotFalse($drawOne);
        self::assertNotFalse($drawNone);

        $clear = new Color(0.1, 0.5, 0.9, 1.0);
        $centreWithOne = $this->centre($this->render($renderer, $matrices, $drawOne, $w, $h, $clear), $w, $h);
        $centreWithNone = $this->centre($this->render($renderer, $matrices, $drawNone, $w, $h, $clear), $w, $h);
        vio_destroy($ctx);

        // instanceCount 0 leaves the clear colour; instanceCount 1 paints the box
        // although both commands claim 1 instance.
        self::assertEqualsWithDelta(26, $centreWithNone[0], 6, 'nothing drawn: R is the clear colour');
        self::assertEqualsWithDelta(128, $centreWithNone[1], 6, 'nothing drawn: G is the clear colour');
        self::assertEqualsWithDelta(229, $centreWithNone[2], 6, 'nothing drawn: B is the clear colour');
        self::assertNotSame($centreWithNone, $centreWithOne, 'the record with instanceCount 1 draws the box');
        self::assertGreaterThan($centreWithOne[2], $centreWithOne[0], 'the box is the orange material, not the blue clear');
    }

    /**
     * The matrices of a storage-buffer draw must reach the vertex stage: an
     * instance translated left renders left of centre, one translated right
     * renders right. The regular programs read instance matrices from vertex
     * attributes, which a storage draw never binds - every instance then
     * collapsed onto the origin, which a count-only test cannot see.
     */
    public function testStorageInstancesLandWhereTheirMatricesPutThem(): void
    {
        $w = 64;
        $h = 64;
        $ctx = @vio_create('auto', ['width' => $w, 'height' => $h, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!function_exists('vio_draw_instanced_from_buffer')
            || !defined('VIO_FEATURE_VERTEX_STORAGE') || !vio_supports_feature($ctx, VIO_FEATURE_VERTEX_STORAGE)
        ) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no vertex storage buffers');
        }

        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('mat', new Material(albedo: new Color(0.9, 0.4, 0.15)));
        $renderer = new VioRenderer3D($ctx, $w, $h);
        self::assertTrue($renderer->warmStorageInstancing(), 'storage-instance programs compile');

        $clear = new Color(0.1, 0.5, 0.9, 1.0);
        $render = static function (float $tx) use ($renderer, $ctx, $w, $h, $clear): string {
            $matrices = vio_storage_buffer($ctx, ['data' => pack('f*', ...Mat4::translation($tx, 0.0, 0.0)->toArray()), 'stride' => 4]);
            self::assertNotFalse($matrices);
            $list = new RenderCommandList();
            $list->add(new SetCamera(
                Mat4::lookAt(new Vec3(0.0, 0.0, 6.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
                Mat4::perspective(deg2rad(40.0), $w / $h, 0.1, 100.0),
            ));
            $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.5), new Color(1, 1, 1), 1.2));
            $list->add(DrawMeshInstanced::fromStorageBuffer('box', 'mat', $matrices, 1));
            return $renderer->renderToImage($list, $w, $h, $clear);
        };

        $left = $render(-1.2);
        $right = $render(1.2);
        vio_destroy($ctx);

        $leftX = intdiv($w, 4);
        $rightX = intdiv($w * 3, 4);
        $y = intdiv($h, 2);
        self::assertTrue(self::isBox(self::px($left, $leftX, $y, $w, $h)), 'x -1.2: the box is left of centre');
        self::assertFalse(self::isBox(self::px($left, $rightX, $y, $w, $h)), 'x -1.2: nothing right of centre');
        self::assertTrue(self::isBox(self::px($right, $rightX, $y, $w, $h)), 'x +1.2: the box is right of centre');
        self::assertFalse(self::isBox(self::px($right, intdiv($w, 2), $y, $w, $h)), 'x +1.2: not collapsed onto the origin');
    }

    /** @param array{0:int,1:int,2:int} $px */
    private static function isBox(array $px): bool
    {
        return $px[0] > $px[2];
    }

    /** @return array{0:int,1:int,2:int} */
    private static function px(string $rgba, int $x, int $y, int $w, int $h): array
    {
        $stride = intdiv(strlen($rgba), $h * 4);
        $o = ($y * $stride + $x) * 4;
        return [ord($rgba[$o]), ord($rgba[$o + 1]), ord($rgba[$o + 2])];
    }

    private function render(VioRenderer3D $renderer, \VioBuffer $matrices, \VioBuffer $args, int $w, int $h, Color $clear): string
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(2.5, 2.5, 3.5), new Vec3(0, 0, 0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(55.0), $w / $h, 0.1, 100.0),
        ));
        $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.5), new Color(1, 1, 1), 1.2));
        $list->add(DrawMeshInstanced::fromStorageBuffer('box', 'mat', $matrices, 1, indirectArgs: $args));
        return $renderer->renderToImage($list, $w, $h, $clear);
    }

    /** @return array{0:int,1:int,2:int} */
    private function centre(string $rgba, int $w, int $h): array
    {
        $stride = intdiv(strlen($rgba), $h * 4);
        $o = (intdiv($h, 2) * $stride + intdiv($w, 2)) * 4;
        return [ord($rgba[$o]), ord($rgba[$o + 1]), ord($rgba[$o + 2])];
    }
}
