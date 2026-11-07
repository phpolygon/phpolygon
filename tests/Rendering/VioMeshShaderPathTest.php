<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
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
 * Mesh-shader path (shader plan 10.1, GraphicsSettings::$meshShaders): an instanced
 * batch drawn as frustum-culled meshlets looks the same as through the vertex path -
 * same vertex code, nothing visible culled away - with and without the temporal
 * (motion-vector) program, and it really took the mesh path.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioMeshShaderPathTest extends TestCase
{
    private const W = 160;
    private const H = 100;
    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_model' => 6, 'headless_hardware' => true, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!defined('VIO_FEATURE_MESH_SHADER') || !vio_supports_feature($ctx, VIO_FEATURE_MESH_SHADER)) {
            vio_destroy($ctx);
            $this->markTestSkipped('no mesh shaders on this device');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(40.0, 0.1, 40.0));
        MeshRegistry::register('ball', SphereMesh::generate(0.45, 24, 32));
        MaterialRegistry::register('floor', new Material(albedo: new Color(0.5, 0.5, 0.5), roughness: 1.0));
        MaterialRegistry::register('ball', new Material(albedo: new Color(0.8, 0.3, 0.1), roughness: 0.5));
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

    public function testTheMeshletPathMatchesTheVertexPath(): void
    {
        [$vertexImage] = $this->render(false, AntiAliasing::Off);
        [$meshImage, $draws] = $this->render(true, AntiAliasing::Off);
        self::assertSame(1, $draws, 'the ball batch went through the mesh-shader path');
        $this->assertSameImage($vertexImage, $meshImage);
    }

    public function testTheMeshletPathMatchesWithMotionVectors(): void
    {
        [$vertexImage] = $this->render(false, AntiAliasing::Taa);
        [$meshImage, $draws] = $this->render(true, AntiAliasing::Taa);
        self::assertSame(1, $draws);
        $this->assertSameImage($vertexImage, $meshImage);
    }

    /** @return array{0: string, 1: int} */
    private function render(bool $meshShaders, AntiAliasing $aa): array
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: $aa,
            bloom: false,
            meshShaders: $meshShaders,
        ));
        // A 21 x 21 grid of balls, most of it outside the view.
        $matrices = [];
        for ($x = -10; $x <= 10; $x++) {
            for ($z = -10; $z <= 10; $z++) {
                $matrices[] = Mat4::translation($x * 1.5, 0.45, $z * 1.5);
            }
        }
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 2.5, 6.0), new Vec3(0.0, 0.5, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(55.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(0.8, 0.85, 1.0), 0.5));
        $list->add(new SetDirectionalLight((new Vec3(-0.4, -1.0, -0.3))->normalize(), new Color(1.0, 0.95, 0.85), 1.6));
        $list->add(new DrawMesh('floor', 'floor', Mat4::translation(0.0, -0.05, 0.0)));
        $list->add(new DrawMeshInstanced('ball', 'ball', $matrices));
        $image = '';
        // Temporal AA converges over a few frames; the same frames on both paths.
        for ($frame = 0; $frame < ($aa === AntiAliasing::Off ? 1 : 4); $frame++) {
            $image = $renderer->renderToImage($list, self::W, self::H, new Color(0.5, 0.6, 0.75));
        }
        return [$image, $renderer->meshletDrawCount()];
    }

    private function assertSameImage(string $a, string $b): void
    {
        self::assertSame(strlen($a), strlen($b));
        $off = 0;
        $max = 0;
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            if (($i & 3) === 3) {
                continue;
            }
            $d = abs(ord($a[$i]) - ord($b[$i]));
            $max = max($max, $d);
            if ($d > 3) {
                $off++;
            }
        }
        // The same vertex code on both paths: at most a rare rounding difference.
        self::assertLessThan(self::W * self::H * 3 / 1000, $off, "{$off} channels differ by more than 3 (max {$max})");
    }
}
