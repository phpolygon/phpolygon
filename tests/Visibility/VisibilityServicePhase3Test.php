<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Visibility;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Visibility\OcclusionSource;
use PHPolygon\Visibility\VisibilityService;

/**
 * Phase 3 additions to VisibilityService: beginFrameFrom() (the
 * order-independent entry point every render-phase system now calls),
 * memoization across repeated calls with the same inputs, shadowOnlyEntities(),
 * the fog-far cull cap, and the OcclusionSource stub.
 */
final class VisibilityServicePhase3Test extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
    }

    private static function camera(Vec3 $eye = new Vec3(0.0, 0.0, 0.0)): SetCamera
    {
        return new SetCamera(
            Mat4::lookAt($eye, new Vec3($eye->x, $eye->y, $eye->z - 1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        );
    }

    // --- beginFrameFrom() --------------------------------------------------

    public function testBeginFrameFromDerivesTheCameraFromTheCommandList(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0))) // behind the camera
            ->attach(new MeshRenderer('box', 'm'));

        $commands = new RenderCommandList();
        $commands->add(self::camera());
        $service->beginFrameFrom($world, $commands);

        self::assertNotNull($service->frame());
        self::assertSame([], $service->visibleEntities());
    }

    public function testBeginFrameFromWithoutACameraInTheListIsUnrestricted(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $service->beginFrameFrom($world, new RenderCommandList());

        self::assertNull($service->frame());
        self::assertSame([$entity->id], $service->visibleEntities());
    }

    public function testBeginFrameFromReadsTheFirstLitDirectionalLight(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $world->createEntity()->attach(new DirectionalLight(
            direction: new Vec3(0.0, -1.0, 0.0),
            color: new Color(1.0, 1.0, 1.0),
            intensity: 1.0,
        ));
        // Off-screen (behind the camera) but inside a straight-down sun's caster volume.
        $caster = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $commands = new RenderCommandList();
        $commands->add(self::camera());
        $service->beginFrameFrom($world, $commands);

        self::assertSame([], $service->visibleEntities());
        self::assertSame([$caster->id], $service->shadowOnlyEntities());
    }

    // --- Memoization ---------------------------------------------------

    public function testRepeatingTheSameCameraIsAMemoHitNotAReclassification(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix);
        $after1 = $service->memoStats();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix);
        $after2 = $service->memoStats();

        self::assertSame($after1['viewMisses'], $after2['viewMisses'], 'the second call with the identical camera must not reclassify');
        self::assertSame($after1['viewHits'] + 1, $after2['viewHits']);
    }

    public function testADifferentCameraInstanceIsAMiss(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);

        $a = self::camera();
        $service->beginFrame($a->viewMatrix, $a->projectionMatrix);
        $missesAfterA = $service->memoStats()['viewMisses'];

        // Same values, but new Mat4 instances - by the engine's own identity
        // convention (Transform3D worldMatrix, Renderer3DSystem's draw-matrix
        // cache) this must count as a change, not be value-compared away.
        $b = self::camera();
        $service->beginFrame($b->viewMatrix, $b->projectionMatrix);
        self::assertSame($missesAfterA + 1, $service->memoStats()['viewMisses']);
    }

    public function testAnIndexChangeBetweenCallsForcesReclassification(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $camera = self::camera();

        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix);
        $missesBefore = $service->memoStats()['viewMisses'];

        // Something changes in the index between two calls with the SAME
        // camera instances - a real production case: Camera3DSystem calls
        // beginFrameFrom() once, something attaches, Renderer3DSystem calls
        // it again later the same frame.
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix);
        self::assertSame($missesBefore + 1, $service->memoStats()['viewMisses'], 'a changed index must not be served from the memo');
    }

    public function testCasterMemoMissesWhenOnlyTheLightDirectionChangesButViewStaysAHit(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $camera = self::camera();

        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.0));
        $before = $service->memoStats();
        // Same camera instances (the view part should hit its memo), only the
        // light direction differs - the caster part alone must recompute.
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.1));
        $after = $service->memoStats();

        self::assertSame($before['viewMisses'], $after['viewMisses'], 'the view classification itself did not need to change');
        self::assertSame($before['viewHits'] + 1, $after['viewHits']);
        self::assertSame($before['casterMisses'] + 1, $after['casterMisses'], 'the caster volume depends on the light direction');
    }

    // --- Shadow casters --------------------------------------------------

    public function testShadowOnlyEntitiesEmptyWithoutADirectionalLight(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix);

        self::assertSame([], $service->shadowOnlyEntities());
    }

    public function testShadowOnlyEntitiesExcludesAlreadyVisibleOnes(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $inView = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.0));

        self::assertSame([$inView->id], $service->visibleEntities());
        self::assertSame([], $service->shadowOnlyEntities(), 'already drawn in the main pass - no extra shadow-only draw');
    }

    public function testShadowOnlyEntitiesExcludesNonShadowCastingMeshes(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm', castShadows: false));

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.0));

        self::assertSame([], $service->shadowOnlyEntities());
    }

    public function testShadowOnlyEntitiesExcludesDormantEntities(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $caster = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm'));
        $world->setEntityDormant($caster->id, true);

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.0));

        self::assertSame([], $service->shadowOnlyEntities());
    }

    public function testShadowOnlyEntitiesRespectsTheMinRadiusSieve(): void
    {
        MeshRegistry::register('tiny', BoxMesh::generate(0.05, 0.05, 0.05));
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $service->setMinShadowCasterRadius(1.0);
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('tiny', 'm'));

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.0));

        self::assertSame([], $service->shadowOnlyEntities(), 'a sub-threshold caster is sieved out');
    }

    public function testShadowOnlyEntitiesCoversATallCasterRegardlessOfDepth(): void
    {
        // "A tall caster toward the sun always stays in" - the caster prism
        // has no near/far plane ALONG THE LIGHT'S OWN AXIS, which for a
        // straight-down sun is world Y: a caster 1000 m up is not clipped,
        // even though the side planes (world X/Z here) stay bounded to the
        // cascade's ortho half-extent. x=0, z=10 (behind the camera, which
        // looks down -Z from the origin) stay safely inside that lateral box.
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $caster = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 1000.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.0));

        self::assertSame([$caster->id], $service->shadowOnlyEntities());
    }

    // --- Fog cap -----------------------------------------------------------

    public function testFogFarCapsTheVisibleSetButNotShadowCasters(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        // Depth 100 m: beyond the 50 m fog cap, but still within the default
        // 150 m caster prism's lateral (X/Z) half-extent for a straight-down
        // sun, so it is a legitimate shadow caster too.
        $beyondFog = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -100.0))) // in the frustum, far away
            ->attach(new MeshRenderer('box', 'm'));

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.0), fogFar: 50.0);

        self::assertSame([], $service->visibleEntities(), 'beyond the fog, culled from the visible set');
        self::assertSame([$beyondFog->id], $service->shadowOnlyEntities(), 'the fog cap never applies to shadow casters');
    }

    public function testWithoutSetFogThereIsNoDistanceCap(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $far = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -300.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix); // fogFar omitted = null

        self::assertSame([$far->id], $service->visibleEntities());
    }

    public function testUnregisteredMeshIsNeverCappedByFogEither(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $ghost = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -300.0)))
            ->attach(new MeshRenderer('ghost', 'm')); // never registered in MeshRegistry

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, fogFar: 10.0);

        self::assertSame([$ghost->id], $service->visibleEntities(), 'unknown extent - never culled, by fog either');
    }

    // --- Occlusion stub ------------------------------------------------

    public function testOcclusionSourceDropsAnOccludedSphereFromTheVisibleSet(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $occluder = new class implements OcclusionSource {
            public function occludes(float $x, float $y, float $z, float $radius): bool
            {
                return true; // occludes everything, for the test
            }
        };
        $service->setOcclusionSource($occluder);

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix);

        self::assertSame([], $service->visibleEntities());
    }

    public function testWithoutAnOcclusionSourceNothingIsOccluded(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix);

        self::assertSame([$entity->id], $service->visibleEntities());
    }
}
