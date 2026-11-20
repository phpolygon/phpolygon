<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Visibility;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Visibility\VisibilityService;

/**
 * A fog change between two calls with the same camera re-applies the fog cap to
 * the memoized classification instead of classifying the index again - and the
 * result is exactly what a service that never saw the first call computes.
 * A game's Camera3DSystem calls beginFrameFrom() before SetFog is queued
 * (fogFar null) and Renderer3DSystem again after (fogFar set): the two calls
 * used to cost two full classifications per frame.
 */
final class VisibilityServiceFogMemoTest extends TestCase
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

    /** @return array{0: Mat4, 1: Mat4} */
    private static function camera(Vec3 $eye, float $yaw): array
    {
        $target = new Vec3($eye->x + sin($yaw), $eye->y, $eye->z - cos($yaw));
        return [
            Mat4::lookAt($eye, $target, new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(70.0), 1.6, 0.1, 400.0),
        ];
    }

    /** @return list<int> entity ids */
    private static function scatter(World $world, int $seed, int $count): array
    {
        mt_srand($seed);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $e = $world->createEntity()
                ->attach(new Transform3D(new Vec3(mt_rand(-300, 300), mt_rand(0, 20), mt_rand(-300, 300))))
                ->attach(new MeshRenderer($i % 17 === 0 ? 'unregistered' : 'box', 'm'));
            $ids[] = $e->id;
        }
        return $ids;
    }

    public function testAFogChangeOnlyRefiltersTheMemoizedClassification(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        self::scatter($world, 7, 300);
        [$view, $projection] = self::camera(new Vec3(0, 5, 0), 0.4);
        $sun = new Vec3(0.2, -1.0, 0.1);

        $service->beginFrame($view, $projection, $sun, null);
        $first = $service->memoStats();
        // A copy of the same camera and a fog the first call did not know yet.
        $service->beginFrame(new Mat4($view->toArray()), new Mat4($projection->toArray()), $sun, 120.0);
        $second = $service->memoStats();

        self::assertSame($first['viewMisses'], $second['viewMisses'], 'no second classification');
        self::assertSame($first['casterMisses'], $second['casterMisses'], 'no second caster classification');
        self::assertSame($first['fogRefilters'] + 1, $second['fogRefilters']);
    }

    /**
     * Differential: a service that saw (camera, no fog) first and (camera copy, fog)
     * second must report what a fresh service reports for (camera, fog) alone -
     * visible set, shadow-only set and order - over random scenes and fog values.
     */
    public function testRefilteredResultEqualsAFreshClassification(): void
    {
        foreach ([3, 11, 42, 99] as $seed) {
            foreach ([null, 15.0, 80.0, 200.0, 1000.0] as $fog) {
                $a = new World();
                $b = new World();
                $idsA = self::scatter($a, $seed, 400);
                self::scatter($b, $seed, 400);
                [$view, $projection] = self::camera(new Vec3(mt_rand(-50, 50), 4, mt_rand(-50, 50)), $seed * 0.3);
                $sun = new Vec3(0.3, -1.0, -0.2);

                $warm = VisibilityService::forWorld($a);
                $warm->beginFrame($view, $projection, $sun, null);
                $warm->beginFrame(new Mat4($view->toArray()), new Mat4($projection->toArray()), $sun, $fog);

                $fresh = VisibilityService::forWorld($b);
                $fresh->beginFrame($view, $projection, $sun, $fog);

                self::assertSame($fresh->visibleEntities(), $warm->visibleEntities(), "visible, seed {$seed}, fog " . var_export($fog, true));
                self::assertSame($fresh->shadowOnlyEntities(), $warm->shadowOnlyEntities(), "shadow-only, seed {$seed}, fog " . var_export($fog, true));
                self::assertSame(count($idsA), count(array_unique($idsA)));
            }
        }
    }

    /**
     * The sun moves between the frame's first and later beginFrame() calls: only
     * the last request is classified, once, when the set is read - and it is the
     * set a service that only ever saw the final light computes.
     */
    public function testCastersAreClassifiedOnceForTheLastLightOfTheFrame(): void
    {
        $a = new World();
        $b = new World();
        self::scatter($a, 21, 400);
        self::scatter($b, 21, 400);
        [$view, $projection] = self::camera(new Vec3(0, 4, 0), 0.9);
        $early = new Vec3(0.1, -1.0, 0.0);
        $late = new Vec3(0.35, -0.9, 0.2);

        $lazy = VisibilityService::forWorld($a);
        $lazy->beginFrame($view, $projection, $early, null);
        $lazy->beginFrame(new Mat4($view->toArray()), new Mat4($projection->toArray()), $late, 90.0);
        self::assertSame(0, $lazy->memoStats()['casterMisses'], 'nothing is classified before the set is read');
        $got = $lazy->shadowOnlyEntities();
        self::assertSame(1, $lazy->memoStats()['casterMisses'], 'one classification, for the last light');
        self::assertSame($got, $lazy->shadowOnlyEntities(), 'reading again does not classify again');
        self::assertSame(1, $lazy->memoStats()['casterMisses']);

        $fresh = VisibilityService::forWorld($b);
        $fresh->beginFrame($view, $projection, $late, 90.0);
        self::assertSame($fresh->shadowOnlyEntities(), $got);
    }

    public function testFogThenNoFogIsAlsoExact(): void
    {
        $a = new World();
        $b = new World();
        self::scatter($a, 5, 300);
        self::scatter($b, 5, 300);
        [$view, $projection] = self::camera(new Vec3(0, 3, 0), 1.1);
        $sun = new Vec3(0.0, -1.0, 0.3);

        $warm = VisibilityService::forWorld($a);
        $warm->beginFrame($view, $projection, $sun, 40.0);
        $warm->beginFrame($view, $projection, $sun, null);
        $fresh = VisibilityService::forWorld($b);
        $fresh->beginFrame($view, $projection, $sun, null);

        self::assertSame($fresh->visibleEntities(), $warm->visibleEntities());
        self::assertSame($fresh->shadowOnlyEntities(), $warm->shadowOnlyEntities());
    }
}
