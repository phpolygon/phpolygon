<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Shadow;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Shadow\PointShadowPlanner;

final class PointShadowPlannerTest extends TestCase
{
    private static function light(float $x, bool $casts = true, int $priority = 0, int $res = 0, float $radius = 5.0, float $intensity = 1.0): AddPointLight
    {
        return new AddPointLight(new Vec3($x, 1.0, 0.0), Color::white(), $intensity, $radius, $casts, $priority, $res);
    }

    /** @param list<\PHPolygon\Rendering\Shadow\PointShadowSlot> $slots @return list<float> */
    private static function xs(array $slots): array
    {
        return array_map(static fn($s) => $s->light->position->x, $slots);
    }

    public function testOnlyEligibleLightsGetSlots(): void
    {
        $p = new PointShadowPlanner(4);
        $slots = $p->plan([
            self::light(1.0, casts: false),
            self::light(2.0, radius: 0.1),
            self::light(3.0, intensity: 0.0),
            self::light(4.0),
        ], new Vec3(0, 0, 0), 512);
        self::assertSame([4.0], self::xs($slots));
        self::assertSame(0, $slots[0]->slot);
    }

    public function testRankIsTheOrderTheSingleCubeAndTheBudgetShare(): void
    {
        $lights = [self::light(30.0), self::light(10.0, casts: false), self::light(20.0), self::light(40.0, priority: 2), self::light(20.0)];
        $ranked = PointShadowPlanner::rank($lights, new Vec3(0, 1, 0));
        self::assertSame([40.0, 20.0, 30.0], array_map(static fn($l) => $l->position->x, $ranked));
        self::assertSame([], PointShadowPlanner::rank([], new Vec3(0, 0, 0)));
    }

    public function testBudgetOneKeepsTheNearestLight(): void
    {
        $p = new PointShadowPlanner(1);
        $slots = $p->plan([self::light(30.0), self::light(10.0), self::light(20.0)], new Vec3(0, 1, 0), 512);
        self::assertSame([10.0], self::xs($slots));
    }

    public function testPriorityBeatsDistance(): void
    {
        $p = new PointShadowPlanner(1);
        $slots = $p->plan([self::light(10.0), self::light(30.0, priority: 5)], new Vec3(0, 1, 0), 512);
        self::assertSame([30.0], self::xs($slots));
    }

    public function testSphereDistanceBeforeCentreDistance(): void
    {
        // the eye is inside the big light's sphere (distance 0) although its centre is farther
        $p = new PointShadowPlanner(1);
        $slots = $p->plan([self::light(4.0, radius: 1.0), self::light(8.0, radius: 20.0)], new Vec3(0, 1, 0), 512);
        self::assertSame([8.0], self::xs($slots));
    }

    public function testBudgetCapsTheCount(): void
    {
        $p = new PointShadowPlanner(3);
        $lights = [];
        for ($i = 1; $i <= 10; $i++) {
            $lights[] = self::light((float) $i * 10.0);
        }
        self::assertSame([10.0, 20.0, 30.0], self::xs($p->plan($lights, new Vec3(0, 1, 0), 512)));
    }

    public function testZeroBudgetPlansNothing(): void
    {
        $p = new PointShadowPlanner(0);
        self::assertSame([], $p->plan([self::light(1.0)], new Vec3(0, 0, 0), 512));
    }

    public function testASurvivingLightKeepsItsSlotWhenTheRankingChanges(): void
    {
        $p = new PointShadowPlanner(2);
        $a = self::light(10.0);
        $b = self::light(20.0);
        $first = $p->plan([$a, $b], new Vec3(0, 1, 0), 512);
        self::assertSame([10.0, 20.0], self::xs($first));
        // the camera moves next to b: b now ranks first but both keep their slot
        $second = $p->plan([$a, $b], new Vec3(25, 1, 0), 512);
        self::assertSame([10.0, 20.0], self::xs($second));
        self::assertSame([0, 1], [$second[0]->slot, $second[1]->slot]);
    }

    public function testAnEvictedLightsSlotGoesToTheNewcomerAndItsCacheIsGone(): void
    {
        $p = new PointShadowPlanner(2);
        $a = self::light(10.0);
        $b = self::light(20.0);
        $c = self::light(5.0, priority: 9);
        $p->plan([$a, $b], new Vec3(0, 1, 0), 512);
        $p->markRendered(0, 'sigA');
        $p->markRendered(1, 'sigB');
        // c outranks both, a (the better of the old two by distance) stays, b makes way
        $slots = $p->plan([$a, $b, $c], new Vec3(0, 1, 0), 512);
        self::assertSame([0, 1], array_map(static fn($s) => $s->slot, $slots));
        self::assertSame([10.0, 5.0], self::xs($slots));
        self::assertTrue($p->isCurrent(0, 'sigA'), 'the kept light keeps its cache entry');
        self::assertFalse($p->isCurrent(1, 'sigB'), 'the newcomer starts with an empty slot');
    }

    public function testCacheSignature(): void
    {
        $p = new PointShadowPlanner(1);
        $p->plan([self::light(1.0)], new Vec3(0, 0, 0), 512);
        self::assertFalse($p->isCurrent(0, 'x'));
        $p->markRendered(0, 'x');
        self::assertTrue($p->isCurrent(0, 'x'));
        self::assertFalse($p->isCurrent(0, 'y'));
        $p->plan([self::light(1.0)], new Vec3(0, 0, 0), 512);
        self::assertTrue($p->isCurrent(0, 'x'), 'a plain next frame keeps it');
        $p->invalidate();
        $p->plan([self::light(1.0)], new Vec3(0, 0, 0), 512);
        self::assertFalse($p->isCurrent(0, 'x'));
    }

    public function testMovingLightIsANewLightAndLosesItsCache(): void
    {
        $p = new PointShadowPlanner(1);
        $p->plan([self::light(1.0)], new Vec3(0, 0, 0), 512);
        $p->markRendered(0, 'x');
        $p->plan([self::light(1.5)], new Vec3(0, 0, 0), 512);
        self::assertFalse($p->isCurrent(0, 'x'));
    }

    public function testChangingTheBudgetEmptiesTheSlots(): void
    {
        $p = new PointShadowPlanner(2);
        $p->plan([self::light(1.0)], new Vec3(0, 0, 0), 512);
        $p->markRendered(0, 'x');
        $p->setBudget(2);
        self::assertTrue($p->isCurrent(0, 'x'));
        $p->setBudget(4);
        self::assertFalse($p->isCurrent(0, 'x'));
        self::assertSame(4, $p->budget());
    }

    public function testTwoLightsAtTheSamePositionShareOneSlot(): void
    {
        $p = new PointShadowPlanner(4);
        self::assertCount(1, $p->plan([self::light(1.0), self::light(1.0)], new Vec3(0, 0, 0), 512));
    }

    public function testFaceSizeOnlyLowersToAPowerOfTwo(): void
    {
        self::assertSame(512, PointShadowPlanner::faceSize(0, 512));
        self::assertSame(512, PointShadowPlanner::faceSize(2048, 512));
        self::assertSame(256, PointShadowPlanner::faceSize(300, 512));
        self::assertSame(128, PointShadowPlanner::faceSize(128, 512));
        self::assertSame(64, PointShadowPlanner::faceSize(10, 512));
        self::assertSame(64, PointShadowPlanner::faceSize(10, 64));
        $p = new PointShadowPlanner(1);
        self::assertSame(128, $p->plan([self::light(1.0, res: 200)], new Vec3(0, 0, 0), 512)[0]->size);
    }
}
