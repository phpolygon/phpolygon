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
use ReflectionMethod;
use ReflectionProperty;

/**
 * Regression for TWO bugs around a SECOND ray-traced-scene update (a real
 * construction-state content swap, following {@see VioRtStaticBatchContentSwapTest}'s
 * signature-only regression):
 *
 *  1. {@see VioRenderer3D::renderToImage()} used to open vio_begin() BEFORE
 *     beginFrame() - the reverse of Engine.php's documented-correct order.
 *     A second, content-driven vio_acceleration_structure_update() reached
 *     through it therefore ran INSIDE an already-open frame, where php-vio
 *     refuses it and returns false (vio_acceleration_structure_update: "call
 *     it outside vio_begin / vio_end").
 *  2. {@see VioRenderer3D::updateRayTracedScene()} discarded that return
 *     value and advanced $rtBuiltKey regardless, so a refused update was
 *     recorded as "built" - freezing the structure on its OLD content
 *     forever (not just one frame behind: the next frame's
 *     $rtBuiltKey === $rtPendingKey early-return never retries again).
 *
 * Bug 1 alone (with bug 2 unfixed) manifests as the shadow staying at the
 * OLD box position forever, never catching up to the new one - this test's
 * straight pixel assertions catch exactly that. {@see VioRtStaticBatchContentSwapTest}
 * uses a straight-down sun/top-down camera where the shadow sits exactly
 * under the box and is hidden by the box's own top face - any case of "the
 * shadow is simply gone, at neither position" during manual investigation of
 * this area is consistent with that self-occlusion, not a third bug; this
 * test tilts the light (like {@see VioRayTracedShadowTest}) so the shadow
 * falls beside the box and is actually visible to the top-down camera.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRtSecondUpdateAfterContentSwapTest extends TestCase
{
    private const SIZE = 64;

    private ?\VioContext $ctx = null;

    /**
     * Reused across every frame (like {@see VioRtStaticBatchContentSwapTest}):
     * a fresh DrawMesh/Mat4 per call would churn the plain DrawMesh's
     * spl_object_id-based key segment ({@see VioRenderer3D::collectRayTracedScene()})
     * on its own, so the rebuild signature would never actually settle - every
     * frame would look like a content change even when only the INSTANCED
     * batch under test is unchanged.
     */
    private ?DrawMesh $floorDraw = null;

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
        $this->floorDraw = new DrawMesh('floor', 'white', Mat4::translation(0.0, -0.05, 0.0));
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

    /**
     * A SECOND real content swap (same staticKey, same count, moved
     * transform - a 'refit') through the production renderToImage() path
     * must move the shadow to the new position and clear it from the old
     * one - not freeze it at the old position (bug 1+2) and not lose it
     * entirely.
     */
    public function testASecondContentSwapMovesTheShadowThroughRenderToImage(): void
    {
        $renderer = $this->makeRenderer();

        // Frames 1-3: box at x=-1.5 ("ruin"). Frame 2 builds the structure
        // from frame 1's content, frame 3 re-sends the identical content.
        $this->render($renderer, -1.5);
        $this->render($renderer, -1.5);
        $img = $this->render($renderer, -1.5);
        self::assertLessThan(200, $this->shadowPixel($img, -1.5), 'shadow present under the box at its first position');
        self::assertGreaterThan(200, $this->shadowPixel($img, 1.5), 'nothing at the not-yet-used second position');

        // Frame 4: box swaps to x=+1.5 ("finished") - one frame of lag is
        // expected (the structure still reflects frame 3's content).
        $img = $this->render($renderer, 1.5);
        self::assertLessThan(200, $this->shadowPixel($img, -1.5), 'one frame of lag: the shadow has not moved yet');

        // Frame 5: the SECOND real update - this is where bug 1+2 used to
        // freeze the structure on the "ruin" content forever.
        $img = $this->render($renderer, 1.5);
        self::assertGreaterThan(200, $this->shadowPixel($img, -1.5), 'the shadow left the old position');
        self::assertLessThan(200, $this->shadowPixel($img, 1.5), 'the shadow is at the new position, not gone');

        // Frame 6: stays there (not a one-off, re-sent content keeps it).
        $img = $this->render($renderer, 1.5);
        self::assertLessThan(200, $this->shadowPixel($img, 1.5), 'the shadow stays at the new position');
    }

    /**
     * A content-driven update that genuinely fails (forced here by calling
     * the private updateRayTracedScene() inside an open frame, the same
     * condition {@see VioRenderer3D::renderToImage()}'s ordering bug used to
     * create) must not advance $rtBuiltKey - otherwise the structure is
     * stuck on stale content permanently. The next, correctly-ordered frame
     * must still pick up the change.
     */
    public function testAFailedUpdateDoesNotFreezeTheStructureOnStaleContent(): void
    {
        $renderer = $this->makeRenderer();
        $this->render($renderer, -1.5);
        $this->render($renderer, -1.5); // builds the structure from "ruin"

        $builtKey = new ReflectionProperty(VioRenderer3D::class, 'rtBuiltKey');
        $pendingKey = new ReflectionProperty(VioRenderer3D::class, 'rtPendingKey');
        $keyBefore = $builtKey->getValue($renderer);

        $this->render($renderer, 1.5); // collects "finished" into rtPending, one frame of lag

        self::assertNotSame($keyBefore, $pendingKey->getValue($renderer), 'the swap is pending');
        self::assertSame($keyBefore, $builtKey->getValue($renderer), 'not built yet - expected lag');

        // Force a failing update: invoke the private method INSIDE an open
        // frame, exactly what renderToImage()'s ordering bug used to do.
        $method = new ReflectionMethod(VioRenderer3D::class, 'updateRayTracedScene');
        vio_begin($this->ctx);
        @$method->invoke($renderer);
        vio_end($this->ctx);

        self::assertSame($keyBefore, $builtKey->getValue($renderer), 'a refused update must not be recorded as built');

        // The next, correctly-ordered frame (through the fixed renderToImage())
        // must still recover and pick up the pending change.
        $img = $this->render($renderer, 1.5);
        self::assertNotSame($keyBefore, $builtKey->getValue($renderer), 'the next frame retries and succeeds');
        self::assertLessThan(200, $this->shadowPixel($img, 1.5), 'the shadow is now at the new position');
    }

    private function makeRenderer(): VioRenderer3D
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
        return $renderer;
    }

    private function render(VioRenderer3D $renderer, float $x): string
    {
        self::assertNotNull($this->ctx);
        self::assertNotNull($this->floorDraw);
        return $renderer->renderToImage($this->sceneAt($x), self::SIZE, self::SIZE);
    }

    /**
     * Darkest pixel in the screen column that corresponds to world X $x, at
     * the row where the tilted light's shadow falls (row 20, matching
     * {@see VioRayTracedShadowTest}'s z = -1.5 shadow row - independent of X).
     */
    private function shadowPixel(string $img, float $x): int
    {
        $col = (int) round($x * 8.0 + 32.0); // ortho [-4, 4] over 64px, same mapping as sceneAt()'s camera
        return ord($img[(20 * self::SIZE + $col) * 4]);
    }

    /** @return RenderCommandList a floor plus one static flat caster at world X $x, staticKey 'ruin' every time. */
    private function sceneAt(float $x): RenderCommandList
    {
        self::assertNotNull($this->floorDraw);
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 9.0, 0.01), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)),
            Mat4::orthographic(-4.0, 4.0, -4.0, 4.0, 1.0, 30.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.15));
        // Tilted (not straight down): the shadow falls beside the box, not
        // directly under it, so the box's own top face does not hide it from
        // this top-down camera - see the class docblock.
        $list->add(new SetDirectionalLight(new Vec3(0.0, -1.0, -0.6), new Color(1.0, 1.0, 1.0), 1.5));
        $list->add($this->floorDraw);
        $flat = Mat4::translation($x, 2.5, 0.0)->toArray();
        $list->add(DrawMeshInstanced::flat('box', 'white', $flat, 1, isStatic: true, staticKey: 'ruin'));
        return $list;
    }
}
