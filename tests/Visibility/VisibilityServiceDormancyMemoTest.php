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
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Visibility\VisibilityService;

/**
 * A latent bug the Phase 4 work surfaced (caught in review before it shipped):
 * World::setEntityDormant() fires no event and does not bump
 * SpatialIndex::version(), so with a camera held still across beginFrame()
 * calls (the realistic case once ActivitySystem toggles dormancy without
 * also moving the camera that same tick), the memo never reclassifies on a
 * pure dormancy change. shadowOnlyEntities() used to bake its dormancy
 * check into $shadowOnlySet at CLASSIFICATION time (only re-run on a memo
 * miss) instead of filtering fresh at read time like visibleEntities()
 * already did - this test would have failed against that version.
 */
final class VisibilityServiceDormancyMemoTest extends TestCase
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

    private static function camera(): SetCamera
    {
        return new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        );
    }

    public function testADormantCasterStopsGettingShadowOnlyDrawsImmediatelyEvenWithAStillCamera(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $world->createEntity()->attach(new DirectionalLight(
            direction: new Vec3(0.0, -1.0, 0.0),
            color: new Color(1.0, 1.0, 1.0),
            intensity: 1.0,
        ));
        $caster = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0))) // behind the camera, a valid caster
            ->attach(new MeshRenderer('box', 'm'));

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.0));
        self::assertSame([$caster->id], $service->shadowOnlyEntities());

        // The camera does NOT change this tick - only dormancy does. Without
        // the read-time filter, the stale $shadowOnlySet (built while the
        // entity was awake) would still list it.
        $world->setEntityDormant($caster->id, true);
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.0));

        self::assertSame([], $service->shadowOnlyEntities(), 'a dormant entity must never get a shadow-only draw, even from a stale memo');
    }

    public function testAWokenCasterCastsAgainAfterInvalidateEvenWithAStillCamera(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $world->createEntity()->attach(new DirectionalLight(
            direction: new Vec3(0.0, -1.0, 0.0),
            color: new Color(1.0, 1.0, 1.0),
            intensity: 1.0,
        ));
        $caster = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm'));
        $world->setEntityDormant($caster->id, true);

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.0));
        self::assertSame([], $service->shadowOnlyEntities(), 'dormant from the start - never classified as a caster');

        // Wakes up; the camera still does not change. Without invalidate(),
        // the memo would never re-run classifyCasters() and this entity -
        // never in $shadowOnlySet while asleep - would stay invisible to
        // shadowOnlyEntities() forever despite now being eligible.
        $world->setEntityDormant($caster->id, false);
        $service->invalidate();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.0));

        self::assertSame([$caster->id], $service->shadowOnlyEntities(), 'woken - casts again once the memo is invalidated');
    }

    public function testWithoutInvalidateAWokenCasterStaysMissingUntilSomethingElseChanges(): void
    {
        // Documents the limitation invalidate() exists to close: a camera
        // held still and nothing else changing in the index means the memo
        // alone will NOT pick up a wake. Whoever toggles dormancy (ActivitySystem)
        // must call invalidate() itself - see that class.
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $world->createEntity()->attach(new DirectionalLight(
            direction: new Vec3(0.0, -1.0, 0.0),
            color: new Color(1.0, 1.0, 1.0),
            intensity: 1.0,
        ));
        $caster = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm'));
        $world->setEntityDormant($caster->id, true);

        $camera = self::camera();
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.0));

        $world->setEntityDormant($caster->id, false);
        // No invalidate() this time.
        $service->beginFrame($camera->viewMatrix, $camera->projectionMatrix, new Vec3(0.0, -1.0, 0.0));

        self::assertSame([], $service->shadowOnlyEntities(), 'without invalidate(), a held-still camera serves the stale memo - the documented limitation');
    }
}
