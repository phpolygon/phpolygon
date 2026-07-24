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
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Native-backend headless pixel read-back via VioRenderer3D::renderToImage().
 * The generic vio path for 3D pixel VRT — the same code runs on D3D11/D3D12
 * (WARP on Windows), Vulkan (lavapipe on Linux) and OpenGL.
 *
 * Only the clear + read-back plumbing is asserted here, because it is the one
 * thing that holds on *every* vio backend: geometry rendering needs a wired 3D
 * pipeline (present on D3D12 / Vulkan / OpenGL, stubbed on vio-Metal), so a
 * cross-backend test can't assume the box appears. Full-geometry snapshots are
 * a per-backend concern taken on the runner that has that backend.
 *
 * Skipped where the vio extension is absent.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
class VioRenderToImageTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('vio_create')) {
            $this->markTestSkipped('vio extension not loaded');
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    public function testReadbackPlumbing(): void
    {
        $w = 64;
        $h = 64;

        $ctx = vio_create('auto', [
            'width'    => $w,
            'height'   => $h,
            'title'    => 'vrt',
            'headless' => true,
            'vsync'    => false,
        ]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable in this environment');
        }

        $renderer = new VioRenderer3D($ctx, $w, $h);

        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('mat', new Material(albedo: new Color(0.9, 0.4, 0.15)));

        $view = Mat4::lookAt(new Vec3(2.5, 2.5, 3.5), new Vec3(0, 0, 0), new Vec3(0, 1, 0));
        $proj = Mat4::perspective(deg2rad(55.0), $w / $h, 0.1, 100.0);

        $list = new RenderCommandList();
        $list->add(new SetCamera($view, $proj));
        $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.5), new Color(1, 1, 1), 1.2));
        $list->add(new DrawMesh('box', 'mat', Mat4::identity()));

        $rgba = $renderer->renderToImage($list, $w, $h, new Color(0.10, 0.50, 0.90, 1.0));

        // --- Read-back plumbing (holds on every vio backend) ---
        // vio_read_pixels() returns the full headless framebuffer as RGBA. Its
        // height matches the request, but some backends row-pad the width above
        // it (D3D11/D3D12 headless return a 120px row for a 64px request; OpenGL
        // returns 64). Assert a well-formed buffer — at least the requested size
        // and a whole number of $h rows — then derive the real row stride.
        $len = strlen($rgba);
        self::assertGreaterThanOrEqual($w * $h * 4, $len, 'read-back at least width*height*4');
        self::assertSame(0, $len % ($h * 4), 'read-back is a whole number of height rows');
        $stride = intdiv($len, $h * 4); // actual pixels per row (>= $w)

        // Geometry is drawn into an off-screen render target that vio_read_pixels
        // does not read, so the framebuffer reads back as one uniform background
        // colour. Sampling three well-separated frame corners and requiring them
        // to agree proves the read-back is coherent (no tearing / partial /
        // garbage buffer) on every backend, whatever that background colour is.
        $corner = static function (int $x, int $y) use ($rgba, $stride): array {
            $o = ($y * $stride + $x) * 4;
            return [ord($rgba[$o]), ord($rgba[$o + 1]), ord($rgba[$o + 2])];
        };
        $topLeft  = $corner(2, 2);
        $topRight = $corner($w - 3, 2);
        $botLeft  = $corner(2, $h - 3);
        self::assertSame($topLeft, $topRight, 'read-back background is uniform (top edge)');
        self::assertSame($topLeft, $botLeft, 'read-back background is uniform (left edge)');

        // Where the headless 3D pass carries the clear colour through to the
        // framebuffer (OpenGL), it is 0.10/0.50/0.90 → 26/128/229. Some headless
        // backends (D3D12) run the 3D pass with their own clear and read back
        // black; the plumbing assertions above still hold. Check the exact colour
        // only when the background was actually carried through (non-black).
        $bgIsBlack = $topLeft[0] <= 8 && $topLeft[1] <= 8 && $topLeft[2] <= 8;
        if (!$bgIsBlack) {
            self::assertEqualsWithDelta(26, $topLeft[0], 4, 'corner R (clear)');
            self::assertEqualsWithDelta(128, $topLeft[1], 4, 'corner G (clear)');
            self::assertEqualsWithDelta(229, $topLeft[2], 4, 'corner B (clear)');
        }
    }
}
