<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Activity;

use PHPUnit\Framework\TestCase;
use PHPolygon\Activity\ActivityService;
use PHPolygon\Activity\ActivitySystem;
use PHPolygon\Activity\ActivityTier;
use PHPolygon\Component\ActivityRegion;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Relevance;
use PHPolygon\Component\RelevanceMode;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Visibility\VisibilityService;

/**
 * ActivitySystem: the central backward-compatibility guarantee first (an
 * entity without Relevance, or a system that never looks at tiers at all,
 * must see EXACTLY what it saw before this phase existed), then the
 * behaviors the plan calls out by name: never-dormant-while-visible, whole
 * subtrees, hysteresis, forced-awake regions.
 */
final class ActivitySystemTest extends TestCase
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

    /** Pretends a render already classified the camera, as real play would before update() next runs. */
    private static function classify(World $world, Vec3 $eye, Vec3 $look): void
    {
        VisibilityService::forWorld($world)->beginFrame(
            Mat4::lookAt($eye, $look, new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 2000.0),
        );
    }

    // --- Backward compatibility -----------------------------------------

    public function testAnEntityWithoutRelevanceIsNeverTouched(): void
    {
        $world = new World();
        $world->addSystem(new ActivitySystem(new ActivityService()));
        $entity = $world->createEntity()->attach(new Transform3D(new Vec3(99999.0, 0.0, 99999.0)));
        self::classify($world, new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0));

        for ($i = 0; $i < 5; $i++) {
            $world->update(1.0 / 60.0);
        }

        self::assertSame(ActivityTier::Visible, $world->entityTier($entity->id));
        self::assertFalse($world->isDormant($entity->id));
    }

    public function testAFarTierEntityIsStillVisitedEveryTickByADefaultSystem(): void
    {
        $world = new World();
        $world->addSystem(new ActivitySystem(new ActivityService(), nearRadius: 1.0, farRadiusMultiplier: 1.0));
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(150.0, 0.0, 0.0)))
            ->attach(new Relevance(RelevanceMode::Auto));
        self::classify($world, new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0));

        $world->update(1.0 / 60.0);
        self::assertSame(ActivityTier::Far, $world->entityTier($entity->id), 'sanity: this scenario really does reach Far');

        // The defining guarantee: an unmodified system's query()/componentPool()
        // sees a Far entity exactly as it sees a Visible one.
        $seen = [];
        foreach ($world->query(Transform3D::class) as $e) {
            $seen[] = $e->id;
        }
        self::assertSame([$entity->id], $seen);
    }

    // --- Never dormant anything visible ----------------------------------

    public function testAVisibleEntityIsNeverSetDormantByDistanceAlone(): void
    {
        $world = new World();
        // Radii small enough that pure distance would push this entity to
        // Dormant - but it sits directly in front of the camera, so
        // VisibilityService considers it visible and the system must cap
        // the tier at Far instead.
        $world->addSystem(new ActivitySystem(new ActivityService(), nearRadius: 1.0, farRadiusMultiplier: 1.0));
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -50.0)))
            ->attach(new MeshRenderer('box', 'm'))
            ->attach(new Relevance(RelevanceMode::Auto));
        self::classify($world, new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0));

        $world->update(1.0 / 60.0);

        self::assertFalse($world->isDormant($entity->id));
        self::assertSame(ActivityTier::Far, $world->entityTier($entity->id));
    }

    public function testRelevanceNearModeNeverGoesFullyDormant(): void
    {
        $world = new World();
        $world->addSystem(new ActivitySystem(new ActivityService(), nearRadius: 1.0, farRadiusMultiplier: 1.0));
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(999999.0, 0.0, 0.0))) // absurdly far, no mesh - not visible
            ->attach(new Relevance(RelevanceMode::Near));
        self::classify($world, new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0));

        $world->update(1.0 / 60.0);

        self::assertFalse($world->isDormant($entity->id));
        self::assertSame(ActivityTier::Far, $world->entityTier($entity->id));
    }

    public function testRelevanceAlwaysModeIsNeverTouchedRegardlessOfDistance(): void
    {
        $world = new World();
        $world->addSystem(new ActivitySystem(new ActivityService(), nearRadius: 1.0, farRadiusMultiplier: 1.0));
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(999999.0, 0.0, 0.0)))
            ->attach(new Relevance(RelevanceMode::Always));
        self::classify($world, new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0));

        $world->update(1.0 / 60.0);

        self::assertSame(ActivityTier::Visible, $world->entityTier($entity->id));
    }

    // --- Subtree consistency ---------------------------------------------

    public function testTierIsAppliedToTheWholeSubtree(): void
    {
        $world = new World();
        $world->addSystem(new ActivitySystem(new ActivityService(), nearRadius: 1.0, farRadiusMultiplier: 1.0));
        $root = $world->createEntity()
            ->attach(new Transform3D(new Vec3(150.0, 0.0, 0.0)))
            ->attach(new Relevance(RelevanceMode::Auto));
        $child = $world->createEntity()->attach(new Transform3D(parentEntityId: $root->id));
        $root->get(Transform3D::class)->childEntityIds = [$child->id];
        self::classify($world, new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0));

        $world->update(1.0 / 60.0);

        self::assertSame($world->entityTier($root->id), $world->entityTier($child->id), 'parent and child never differ');
        self::assertSame(ActivityTier::Far, $world->entityTier($root->id));
    }

    // --- Hysteresis --------------------------------------------------------

    public function testHysteresisPreventsFlippingRightAtTheBoundary(): void
    {
        $world = new World();
        $world->addSystem(new ActivitySystem(new ActivityService(), nearRadius: 100.0, farRadiusMultiplier: 1.0));
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(100.0, 0.0, 0.0))) // exactly at the near radius
            ->attach(new Relevance(RelevanceMode::Auto));
        self::classify($world, new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0));

        $world->update(1.0 / 60.0);
        $first = $world->entityTier($entity->id);

        // Nudge back and forth within the hysteresis band (10% of 100 m = 10 m).
        $entity->get(Transform3D::class)->position = new Vec3(103.0, 0.0, 0.0);
        $world->update(1.0 / 60.0);
        self::assertSame($first, $world->entityTier($entity->id), 'small jitter inside the band must not flip the tier');

        $entity->get(Transform3D::class)->position = new Vec3(97.0, 0.0, 0.0);
        $world->update(1.0 / 60.0);
        self::assertSame($first, $world->entityTier($entity->id));
    }

    // --- Forced-awake regions ----------------------------------------------

    public function testRequestAwakeForcesARegionActiveRegardlessOfDistance(): void
    {
        $activity = new ActivityService();
        $world = new World();
        $world->addSystem(new ActivitySystem($activity, nearRadius: 1.0, farRadiusMultiplier: 1.0));
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(999999.0, 0.0, 0.0)))
            ->attach(new Relevance(RelevanceMode::Auto))
            ->attach(new ActivityRegion(tag: 'cutscene-room'));
        self::classify($world, new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0));

        $activity->requestAwake('cutscene-room', 'intro-cutscene');
        $world->update(1.0 / 60.0);
        self::assertFalse($world->isDormant($entity->id));
        self::assertFalse($world->isFar($entity->id));

        $activity->release('cutscene-room', 'intro-cutscene');
        $world->update(1.0 / 60.0);
        self::assertSame(ActivityTier::Dormant, $world->entityTier($entity->id), 'released - distance rules apply again');
    }

    public function testReleaseWithAnUnrelatedReasonDoesNotWakeTheRegion(): void
    {
        $activity = new ActivityService();
        $activity->requestAwake('zone', 'a');
        $activity->requestAwake('zone', 'b');
        $activity->release('zone', 'a');

        self::assertTrue($activity->isForcedAwake('zone'), 'reason "b" still holds it awake');

        $activity->release('zone', 'b');
        self::assertFalse($activity->isForcedAwake('zone'));
    }

    // --- Observer notification ---------------------------------------------

    public function testObserverFiresOnceOnARealTierChangeNotOnRepeats(): void
    {
        $activity = new ActivityService();
        $calls = [];
        $activity->addObserver(function (int $id, ActivityTier $from, ActivityTier $to) use (&$calls): void {
            $calls[] = [$id, $from, $to];
        });

        $world = new World();
        $world->addSystem(new ActivitySystem($activity, nearRadius: 1.0, farRadiusMultiplier: 1.0));
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(150.0, 0.0, 0.0)))
            ->attach(new Relevance(RelevanceMode::Auto));
        self::classify($world, new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0));

        $world->update(1.0 / 60.0);
        $world->update(1.0 / 60.0); // nothing moved - must not notify again

        self::assertCount(1, $calls);
        self::assertSame($entity->id, $calls[0][0]);
        self::assertSame(ActivityTier::Near, $calls[0][1]);
        self::assertSame(ActivityTier::Far, $calls[0][2]);
    }
}
