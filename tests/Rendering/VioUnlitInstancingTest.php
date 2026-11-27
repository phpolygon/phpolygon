<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetShader;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Regression: the 'unlit' program read no instance matrices, so every instance of a batch
 * collapsed onto the origin (an instanced scene under ShaderQuality::Unlit lost its geometry).
 * Two instances far apart must both show.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
#[Group('gpu')]
#[RunTestsInSeparateProcesses]
final class VioUnlitInstancingTest extends TestCase
{
    private const int W = 128;
    private const int H = 96;

    private ?\VioContext $ctx = null;

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    /** @return array<string, array{0: string}> */
    public static function backends(): array
    {
        return ['d3d12' => ['d3d12'], 'd3d11' => ['d3d11'], 'vulkan' => ['vulkan'], 'opengl' => ['opengl'], 'metal' => ['metal']];
    }

    #[DataProvider('backends')]
    public function testUnlitDrawsEveryInstanceOfABatch(string $backend): void
    {
        $ctx = @vio_create($backend, ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === false || vio_backend_name($ctx) !== $backend) {
            $this->markTestSkipped("$backend unavailable");
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(2.0, 2.0, 2.0));
        MaterialRegistry::register('stone', new Material(albedo: new Color(0.7, 0.7, 0.7)));

        $list = new RenderCommandList();
        $list->add(new SetShader('unlit'));
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 12.0), new Vec3(0, 0, 0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
        $list->add(new DrawMeshInstanced('box', 'stone', [Mat4::translation(-3.0, 0.0, 0.0), Mat4::translation(3.0, 0.0, 0.0)]));
        $renderer = new VioRenderer3D($ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(bloom: false));
        $image = $renderer->renderToImage($list, self::W, self::H, new Color(0.1, 0.2, 0.4, 1.0));

        $clear = [26, 51, 102];
        $differs = static function (array $p) use ($clear): int {
            return abs($p[0] - $clear[0]) + abs($p[1] - $clear[1]) + abs($p[2] - $clear[2]);
        };
        // x = +-3 at distance 12 (50 degree field of view, 4:3) lands near columns 38 and 90; a box is about 18 px wide.
        foreach ([38, 90] as $x) {
            self::assertGreaterThan(30, $differs(self::px($image, $x, 48)), "$backend: an instance at pixel column $x");
        }
        self::assertLessThan(10, $differs(self::px($image, 64, 48)), "$backend: nothing at the origin, where the old program drew every instance");
    }

    /** @return list<int> */
    private static function px(string $rgba, int $x, int $y): array
    {
        return array_values((array) unpack('C4', substr($rgba, (($y * self::W) + $x) * 4, 4)));
    }
}
