<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The offscreen pipeline (render scale, bloom, AA) presents the scene the same
 * way up as the direct path: a ground plane seen from above fills the lower
 * half of the frame on every backend. The fullscreen quad's texture V has to
 * follow the backend's render-target row order - flipped where row 0 is the
 * top (D3D, Metal, Vulkan), straight on OpenGL.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioOffscreenOrientationTest extends TestCase
{
    private const W = 64;
    private const H = 48;

    /** @return iterable<string, array{0: string, 1: float, 2: bool}> */
    public static function cases(): iterable
    {
        foreach (['d3d12', 'vulkan', 'opengl'] as $backend) {
            yield "$backend direct" => [$backend, 1.0, false];
            yield "$backend render scale" => [$backend, 0.5, false];
            yield "$backend bloom" => [$backend, 1.0, true];
        }
    }

    #[DataProvider('cases')]
    public function testGroundIsAtTheBottom(string $backend, float $renderScale, bool $bloom): void
    {
        $ctx = @vio_create($backend, ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false]);
        if ($ctx === false || vio_backend_name($ctx) !== $backend) {
            $this->markTestSkipped("$backend: no headless context on this machine");
        }
        try {
            MeshRegistry::clear();
            MaterialRegistry::clear();
            CubemapRegistry::clear();
            MeshRegistry::register('ground', PlaneMesh::generate(80.0, 80.0, 2));
            MaterialRegistry::register('ground', new Material(albedo: new Color(0.9, 0.1, 0.1)));
            $renderer = new VioRenderer3D($ctx, self::W, self::H);
            $renderer->applySettings(new GraphicsSettings(
                shadowQuality: ShadowQuality::Off,
                renderScale: $renderScale,
                bloom: $bloom,
                hdr: false,
                antiAliasing: AntiAliasing::Off,
            ));
            $list = new RenderCommandList();
            $list->add(new SetCamera(
                Mat4::lookAt(new Vec3(0.0, 2.0, 6.0), new Vec3(0.0, 1.5, 0.0), new Vec3(0, 1, 0)),
                Mat4::perspective(deg2rad(60.0), self::W / self::H, 0.1, 200.0),
            ));
            $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
            $list->add(new DrawMesh('ground', 'ground', Mat4::identity()));
            for ($i = 0; $i < 2; $i++) {
                vio_begin($ctx);
                $renderer->beginFrame();
                $renderer->render($list);
                $renderer->endFrame();
                vio_end($ctx);
            }
            $rgba = vio_read_pixels($ctx);
            $top = ord($rgba[(2 * self::W + intdiv(self::W, 2)) * 4]);
            $bottom = ord($rgba[((self::H - 3) * self::W + intdiv(self::W, 2)) * 4]);
            $this->assertGreaterThan(150, $bottom, 'red ground in the bottom rows');
            $this->assertLessThan(60, $top, 'no ground in the top rows');
        } finally {
            vio_destroy($ctx);
        }
    }
}
