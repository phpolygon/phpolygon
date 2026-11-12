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
use ReflectionProperty;

/**
 * Regression for the ray-traced scene collector's rebuild signature
 * ({@see VioRenderer3D::collectRayTracedScene()}): a keyed STATIC FLAT batch
 * (DrawMeshInstanced::flat(), isStatic: true, staticKey) that keeps its
 * staticKey and instance count but swaps its matrices underneath them - the
 * construction-state reveal that replaces N "ruin" instances with N
 * "finished" ones at the same count, reusing the same staticKey
 * (CodeRescue\System\InstancedRevealSystem) - must still make the next
 * {@see VioRenderer3D::updateRayTracedScene()} call rebuild the acceleration
 * structure. Before the fix the signature was `staticKey . count($flat)`
 * only, so a content change under an unchanged key and count left the
 * structure stale forever (never caught up, not just one frame behind).
 *
 * Asserts on $rtPendingKey / $rtBuiltKey only - both existed before the fix
 * too, so a run against the unfixed source fails this assertion instead of
 * throwing (ReflectionException on a property that doesn't exist yet would
 * make "fails without the fix" meaningless).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRtStaticBatchContentSwapTest extends TestCase
{
    private const SIZE = 32;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_model' => 6, 'headless_hardware' => true, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
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
        MeshRegistry::register('floor', BoxMesh::generate(10.0, 0.1, 10.0));
        MeshRegistry::register('box', BoxMesh::generate(1.5, 1.5, 1.5));
        MaterialRegistry::register('white', new Material(albedo: new Color(0.8, 0.8, 0.8), roughness: 1.0, useEnvironmentMap: false));
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

    public function testAContentSwapUnderTheSameStaticKeyAndCountRebuilds(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
            rayTracedShadows: true,
        ));

        $pendingKey = new ReflectionProperty(VioRenderer3D::class, 'rtPendingKey');
        $builtKey = new ReflectionProperty(VioRenderer3D::class, 'rtBuiltKey');
        $rtScene = new ReflectionProperty(VioRenderer3D::class, 'rtScene');

        // Reused across every frame (like VioRtGiTest's wall/floor) so the plain
        // DrawMesh's spl_object_id-based key segment stays stable and only the
        // instanced batch under test varies - a fresh Mat4 per frame would churn
        // that segment on its own and muddy the assertions below.
        $floorDraw = new DrawMesh('floor', 'white', Mat4::translation(0.0, -0.05, 0.0));

        // "Ruin" and "finished" content: the same staticKey, the same instance
        // count (1), only the transform differs - exactly the construction-state
        // swap InstancedRevealSystem sends under one key.
        $render = static fn (float $x): string => $renderer->renderToImage(self::sceneAt($floorDraw, $x), self::SIZE, self::SIZE);

        $render(-1.5); // frame 1: nothing collected yet, no structure built.
        $render(-1.5); // frame 2: builds from frame 1's "ruin" content.
        self::assertNotNull($rtScene->getValue($renderer), 'the acceleration structure was built');
        $keyRuin = $pendingKey->getValue($renderer);
        self::assertSame($keyRuin, $builtKey->getValue($renderer), 'built from the unchanged "ruin" content');

        // Re-sending the identical content keeps the signature - no spurious
        // rebuild cost for the common case (unchanged merge, re-sent every frame).
        $render(-1.5); // frame 3: same content again.
        self::assertSame($keyRuin, $pendingKey->getValue($renderer), 'identical content under the same key does not change the signature');

        // The construction-state swap: same staticKey, same instance count (1),
        // different transform ("finished" replaces "ruin").
        $render(1.5); // frame 4: collects the "finished" content.
        $keyFinished = $pendingKey->getValue($renderer);
        self::assertNotSame($keyRuin, $keyFinished, 'a content swap under the same staticKey and count changes the signature');
        self::assertSame($keyRuin, $builtKey->getValue($renderer), 'the structure has not rebuilt yet - one frame of lag is expected');

        $render(1.5); // frame 5: updateRayTracedScene() now sees the changed pending key.
        self::assertSame($keyFinished, $builtKey->getValue($renderer), 'the acceleration structure rebuilds for the "finished" content - not stuck on "ruin" forever');
    }

    /** @return RenderCommandList a floor plus one static flat caster at world X $x, staticKey 'ruin' every time. */
    private static function sceneAt(DrawMesh $floorDraw, float $x): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 9.0, 0.01), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)),
            Mat4::orthographic(-4.0, 4.0, -4.0, 4.0, 1.0, 30.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.15));
        $list->add(new SetDirectionalLight(new Vec3(0.0, -1.0, 0.0), new Color(1.0, 1.0, 1.0), 1.5));
        $list->add($floorDraw);
        $flat = Mat4::translation($x, 2.5, 0.0)->toArray();
        $list->add(DrawMeshInstanced::flat('box', 'white', $flat, 1, isStatic: true, staticKey: 'ruin'));
        return $list;
    }
}
