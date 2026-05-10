<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPUnit\Framework\TestCase;

final class MeshRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
    }

    public function testEagerRegisterIsImmediatelyRetrievable(): void
    {
        $mesh = $this->stubMesh();
        MeshRegistry::register('crate', $mesh);

        $this->assertTrue(MeshRegistry::has('crate'));
        $this->assertSame($mesh, MeshRegistry::get('crate'));
        $this->assertSame(1, MeshRegistry::materialisedCount());
        $this->assertSame(0, MeshRegistry::pendingCount());
    }

    public function testLazyRegisterDoesNotRunFactoryYet(): void
    {
        $invoked = 0;
        MeshRegistry::registerLazy('crate', function () use (&$invoked): MeshData {
            $invoked++;
            return $this->stubMesh();
        });

        $this->assertTrue(MeshRegistry::has('crate'));
        $this->assertSame(0, $invoked, 'Factory must not run on registration');
        $this->assertSame(0, MeshRegistry::materialisedCount());
        $this->assertSame(1, MeshRegistry::pendingCount());
    }

    public function testLazyMaterialisesOnFirstGet(): void
    {
        $invoked = 0;
        MeshRegistry::registerLazy('crate', function () use (&$invoked): MeshData {
            $invoked++;
            return $this->stubMesh();
        });

        $a = MeshRegistry::get('crate');
        $b = MeshRegistry::get('crate');

        $this->assertSame(1, $invoked, 'Factory runs exactly once across multiple get() calls');
        $this->assertSame($a, $b);
        $this->assertSame(1, MeshRegistry::materialisedCount());
        $this->assertSame(0, MeshRegistry::pendingCount());
    }

    public function testPrefetchMaterialisesAndReturnsTrue(): void
    {
        $invoked = 0;
        MeshRegistry::registerLazy('crate', function () use (&$invoked): MeshData {
            $invoked++;
            return $this->stubMesh();
        });

        $this->assertTrue(MeshRegistry::prefetch('crate'));
        $this->assertSame(1, $invoked);

        // Second prefetch is a no-op (already materialised) and returns false.
        $this->assertFalse(MeshRegistry::prefetch('crate'));
        $this->assertSame(1, $invoked);
    }

    public function testPrefetchUnknownIdReturnsFalse(): void
    {
        $this->assertFalse(MeshRegistry::prefetch('does_not_exist'));
    }

    public function testIdsListsPendingAndMaterialised(): void
    {
        MeshRegistry::register('eager', $this->stubMesh());
        MeshRegistry::registerLazy('lazy', fn(): MeshData => $this->stubMesh());

        $ids = MeshRegistry::ids();
        sort($ids);
        $this->assertSame(['eager', 'lazy'], $ids);
        $this->assertSame(['lazy'], MeshRegistry::pendingIds());
    }

    public function testRegisterAfterRegisterLazyReplacesFactory(): void
    {
        $factoryRan = false;
        MeshRegistry::registerLazy('crate', function () use (&$factoryRan): MeshData {
            $factoryRan = true;
            return $this->stubMesh();
        });

        $direct = $this->stubMesh();
        MeshRegistry::register('crate', $direct);

        $this->assertSame($direct, MeshRegistry::get('crate'));
        $this->assertFalse($factoryRan, 'Factory must not run after eager register supersedes it');
    }

    public function testRegisterLazyAfterEagerReplacesData(): void
    {
        MeshRegistry::register('crate', $this->stubMesh());
        $this->assertSame(1, MeshRegistry::materialisedCount());

        $newMesh = $this->stubMesh();
        MeshRegistry::registerLazy('crate', fn(): MeshData => $newMesh);

        $this->assertSame(0, MeshRegistry::materialisedCount());
        $this->assertSame(1, MeshRegistry::pendingCount());
        $this->assertSame($newMesh, MeshRegistry::get('crate'));
    }

    public function testVersionIncrementsOnMaterialisation(): void
    {
        $this->assertSame(0, MeshRegistry::version('crate'));

        MeshRegistry::registerLazy('crate', fn(): MeshData => $this->stubMesh());
        $this->assertSame(0, MeshRegistry::version('crate'), 'Version unchanged before materialisation');

        MeshRegistry::get('crate');
        $this->assertSame(1, MeshRegistry::version('crate'));

        MeshRegistry::register('crate', $this->stubMesh());
        $this->assertSame(2, MeshRegistry::version('crate'));
    }

    public function testPrefetchAllReportsProgressAndMaterialisesAll(): void
    {
        MeshRegistry::registerLazy('a', fn(): MeshData => $this->stubMesh());
        MeshRegistry::registerLazy('b', fn(): MeshData => $this->stubMesh());
        MeshRegistry::registerLazy('c', fn(): MeshData => $this->stubMesh());

        $events = [];
        $count = MeshRegistry::prefetchAll(function (int $done, int $total, string $id) use (&$events) {
            $events[] = [$done, $total, $id];
        });

        $this->assertSame(3, $count);
        $this->assertCount(3, $events);
        $this->assertSame([1, 3], [$events[0][0], $events[0][1]]);
        $this->assertSame([3, 3], [$events[2][0], $events[2][1]]);
        $this->assertSame(3, MeshRegistry::materialisedCount());
        $this->assertSame(0, MeshRegistry::pendingCount());
    }

    public function testPrefetchAllSkipsAlreadyMaterialised(): void
    {
        MeshRegistry::register('eager', $this->stubMesh());
        MeshRegistry::registerLazy('lazy', fn(): MeshData => $this->stubMesh());

        $count = MeshRegistry::prefetchAll();
        $this->assertSame(1, $count, 'Only the lazy one needs materialising');
        $this->assertSame(2, MeshRegistry::materialisedCount());
    }

    public function testPrefetchAllWithoutCallbackRunsCleanly(): void
    {
        MeshRegistry::registerLazy('a', fn(): MeshData => $this->stubMesh());
        MeshRegistry::registerLazy('b', fn(): MeshData => $this->stubMesh());
        $this->assertSame(2, MeshRegistry::prefetchAll());
        $this->assertSame(0, MeshRegistry::pendingCount());
    }

    private function stubMesh(): MeshData
    {
        return new MeshData(
            vertices: [0.0, 0.0, 0.0],
            normals:  [0.0, 1.0, 0.0],
            uvs:      [0.0, 0.0],
            indices:  [0],
        );
    }
}
