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
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * A renderer and the GPU objects it owns go with its last variable, not later with
 * the cycle collector. A renderer kept alive by a reference cycle outlived the
 * context it was made for: destroyed context, programs still referenced, and the
 * next OpenGL context received stale entries of php-vio's per-program caches
 * (access violation in the next context's first draw).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRendererLifetimeTest extends TestCase
{
    private const SIZE = 32;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('plain', new Material(albedo: new Color(0.6, 0.6, 0.6)));
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

    public function testTheRendererIsFreedWithItsLastReference(): void
    {
        self::assertNotNull($this->ctx);
        // Default settings: the most passes and helpers a frame builds.
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings());
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.0, 3.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(0.9, 1.0, 0.1, 50.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
        $list->add(new DrawMesh('box', 'plain', Mat4::identity()));
        $renderer->renderToImage($list, self::SIZE, self::SIZE);

        $weak = \WeakReference::create($renderer);
        $wasEnabled = gc_enabled();
        gc_disable();
        try {
            unset($renderer);
            self::assertNull($weak->get(), 'nothing but the variable holds the renderer');
        } finally {
            if ($wasEnabled) {
                gc_enable();
            }
        }
    }
}
