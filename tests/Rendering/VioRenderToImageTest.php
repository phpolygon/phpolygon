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

        self::assertSame($w * $h * 4, strlen($rgba), 'read-back size must be width*height*4');

        // A background corner is the clear colour on every backend (geometry does
        // not cover the frame edges). 0.10/0.50/0.90 → 26/128/229.
        $i = (2 * $w + 2) * 4;
        self::assertEqualsWithDelta(26, ord($rgba[$i]), 4, 'corner R (clear)');
        self::assertEqualsWithDelta(128, ord($rgba[$i + 1]), 4, 'corner G (clear)');
        self::assertEqualsWithDelta(229, ord($rgba[$i + 2]), 4, 'corner B (clear)');
    }
}
