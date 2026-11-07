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
 * Ray-traced probe update (shader plan 10.1): a probe next to a red wall picks up
 * red bounce light through the acceleration structure; a probe far from it does not.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRtProbeBakerTest extends TestCase
{
    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(32, 32, ['shader_model' => 6, 'headless_hardware' => true, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!defined('VIO_FEATURE_RAY_QUERY') || !vio_supports_feature($ctx, VIO_FEATURE_RAY_QUERY)) {
            vio_destroy($ctx);
            $this->markTestSkipped('no inline ray tracing on this device');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(30.0, 0.1, 30.0));
        MeshRegistry::register('wall', BoxMesh::generate(0.2, 4.0, 6.0));
        MaterialRegistry::register('white', new Material(albedo: new Color(0.8, 0.8, 0.8), roughness: 1.0));
        MaterialRegistry::register('red', new Material(albedo: new Color(0.9, 0.05, 0.05), roughness: 1.0));
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

    public function testAProbeBesideTheRedWallTurnsRed(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, 32, 32);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
            rayTracedShadows: true,
        ));
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 3.0, 10.0), new Vec3(0.0, 1.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(1.1, 1.0, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.4));
        $list->add(new SetDirectionalLight(new Vec3(0.4, -1.0, -0.3), new Color(1.0, 1.0, 1.0), 1.2));
        $list->add(new DrawMesh('floor', 'white', Mat4::translation(0.0, -0.05, 0.0)));
        $list->add(new DrawMesh('wall', 'red', Mat4::translation(2.2, 2.0, 0.0)));
        $renderer->renderToImage($list, 32, 32);   // collects the scene
        $renderer->renderToImage($list, 32, 32);   // builds the acceleration structure

        // Two probes along x: (-6, 1, 0) far away and (1.5, 1, 0) beside the wall.
        $c = $renderer->bakeRayTracedProbes([-6.0, 1.0, 0.0], 2, 1, 1, 7.5, 128, [-0.4, 1.0, 0.3]);
        self::assertNotNull($c, 'the ray-traced bake runs');
        self::assertCount(24, $c);
        $redness = static fn (int $p): float => $c[$p * 12] / max(1e-4, $c[$p * 12 + 4]);   // DC red / DC green
        self::assertGreaterThan($redness(0) * 1.15, $redness(1), sprintf('the probe beside the wall gathers its red (far %.3f, near %.3f)', $redness(0), $redness(1)));
        self::assertGreaterThan(0.0, $c[0], 'probes receive light');
    }

    public function testTheSettingReplacesTheBakedField(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, 32, 32);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
            rayTracedProbes: true,
        ));
        $flat = str_repeat(chr(128), 4 * 4 * 4 * 4);
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 3.0, 10.0), new Vec3(0.0, 1.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(1.1, 1.0, 0.1, 100.0),
        ));
        $list->add(new \PHPolygon\Rendering\Command\SetFieldtracingProbes($flat, $flat, $flat, 4, 4, 4, new Vec3(-6.0, 0.5, -6.0), new Vec3(12.0, 6.0, 12.0), 3.0, 1));
        $list->add(new SetDirectionalLight(new Vec3(0.4, -1.0, -0.3), new Color(1.0, 1.0, 1.0), 1.2));
        $list->add(new DrawMesh('floor', 'white', Mat4::translation(0.0, -0.05, 0.0)));
        $list->add(new DrawMesh('wall', 'red', Mat4::translation(2.2, 2.0, 0.0)));
        $renderer->renderToImage($list, 32, 32);
        $tex = new \ReflectionProperty($renderer, 'probeTex');
        $baked = $tex->getValue($renderer);
        if ($baked === null) {
            self::markTestSkipped('no 3D textures on this backend');
        }
        $renderer->renderToImage($list, 32, 32);   // builds the scene, traces the probes
        self::assertNotSame($baked, $tex->getValue($renderer), 'the traced field replaces the baked one');
        $key = (new \ReflectionProperty($renderer, 'rtProbeKey'))->getValue($renderer);
        $renderer->renderToImage($list, 32, 32);
        self::assertSame($key, (new \ReflectionProperty($renderer, 'rtProbeKey'))->getValue($renderer), 'an unchanged scene is not traced again');
    }
}
