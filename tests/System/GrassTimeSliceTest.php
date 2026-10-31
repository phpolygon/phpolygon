<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\GrassField;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\GrassSystem;
use PHPolygon\Vegetation\GrassDetail;
use PHPolygon\Vegetation\GrassMask;

/**
 * Building grass cells over frames. The budget runs on a clock the mask advances:
 * a candidate costs exactly what the test says, so "over budget" is arithmetic,
 * not a race against the machine.
 */
final class GrassTimeSliceTest extends TestCase
{
    private const float DT = 1.0 / 60.0;
    private const float WALK = 5.0;

    private RenderCommandList $list;
    private World $world;
    private int $now = 0;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        $this->list = new RenderCommandList();
        $this->world = new World();
        $this->now = 0;
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    private function system(GrassDetail $detail): GrassSystem
    {
        return new GrassSystem($this->list, $detail, clock: fn(): int => $this->now);
    }

    /** A mask of which every candidate costs `$costNs`. */
    private function costlyMask(int $costNs): GrassMask
    {
        return (new GrassMask())->multiply(function (float $x, float $z) use ($costNs): float {
            $this->now += $costNs;
            return 1.0;
        });
    }

    private function field(GrassMask $mask, float $min = -150.0, float $max = 150.0, int $seed = 3): GrassField
    {
        $field = new GrassField($min, $min, $max, $max, static fn(float $x, float $z): float => 0.02 * $x, $mask, seed: $seed);
        $this->world->createEntity()->attach($field);
        return $field;
    }

    private function tick(GrassSystem $system, float $x, float $z, float $y = 1.7): int
    {
        $this->list->clear();
        $this->list->add(new SetCamera(
            Mat4::lookAt(new Vec3($x, $y, $z), new Vec3($x, $y - 0.3, $z - 1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(70.0), 16 / 9, 0.1, 2000.0),
        ));
        $start = $this->now;
        $system->render($this->world);
        return $this->now - $start;
    }

    /**
     * Walk along -Z and report the longest tick and the nearest cell ever missing.
     *
     * @return array{0: int, 1: float}
     */
    private function walk(GrassSystem $system, float $from, float $to, float $speed = self::WALK): array
    {
        $longest = 0;
        $nearest = INF;
        for ($z = $from; $z > $to; $z -= $speed * self::DT) {
            $longest = max($longest, $this->tick($system, 0.0, $z));
            $nearest = min($nearest, $system->lastMissingDistance);
        }
        return [$longest, $nearest];
    }

    /** A mask above the tile threshold, nothing in the area the tests look at. */
    private function crowded(): GrassMask
    {
        $mask = new GrassMask();
        for ($i = 0; $i < 20; $i++) {
            $mask->excludeCircle(500.0 + $i * 10.0, 500.0, 1.0);
        }
        return $mask;
    }

    /** @return list<float> x of every drawn tuft */
    private function tuftXs(): array
    {
        $xs = [];
        foreach (str_split(implode('', $this->drawn()) ?: str_repeat("\0", 64), 64) as $tuft) {
            $x = unpack('g', substr($tuft, 48, 4));
            self::assertIsArray($x);
            self::assertIsFloat($x[1]);
            $xs[] = $x[1];
        }
        return $this->drawn() === [] ? [] : $xs;
    }

    /** @return list<string> packed tufts per draw */
    private function drawn(): array
    {
        $out = [];
        foreach ($this->list->getCommands() as $cmd) {
            if ($cmd instanceof DrawMeshInstanced) {
                $out[] = $cmd->packedMatrices;
            }
        }
        return $out;
    }

    public function testNoTickLeavesTheBudgetWhileWalkingThroughACostlyMask(): void
    {
        // 10 µs per candidate, about 10 ms per cell per field - well beyond the
        // 2 ms budget - and two fields: before, every new cell was a 10 ms frame
        // and every pair of cells a 20 ms one.
        $cost = 10_000;
        $detail = new GrassDetail();
        $this->field($this->costlyMask($cost), seed: 1);
        $this->field($this->costlyMask($cost), seed: 2);
        $system = $this->system($detail);
        $system->prewarm($this->world, 0.0, 120.0);

        [$longest, $nearest] = $this->walk($system, 120.0, -120.0);

        self::assertLessThanOrEqual((int) ($detail->generateBudgetMs * 1e6) + 2 * $cost, $longest, 'budget + one candidate');
        self::assertGreaterThan($detail->fadeRange, $nearest, 'nothing in sight was ever missing');
        self::assertGreaterThan(100, $system->cachedCellCount());
    }

    public function testAMaskTooSlowForTheBudgetRaisesItRatherThanLeavingAHole(): void
    {
        // 60 µs per candidate: walking asks for more than 2 ms per frame.
        $cost = 60_000;
        $detail = new GrassDetail();
        $this->field($this->costlyMask($cost));
        $system = $this->system($detail);
        $system->prewarm($this->world, 0.0, 120.0);

        [$longest, $nearest] = $this->walk($system, 120.0, -120.0);

        self::assertGreaterThan((int) ($detail->generateBudgetMs * 1e6) + 2 * $cost, $longest, 'the plain budget did not suffice here');
        self::assertLessThanOrEqual((int) ($detail->generateBudgetMs * $detail->generateBurst * 1e6) + 2 * $cost, $longest, 'and the raised one holds');
        self::assertGreaterThan($detail->fullRange, $nearest, 'no hole at full density');
    }

    public function testWithoutBurstTheBudgetIsHardAndFieldsShareIt(): void
    {
        $cost = 20_000;
        $detail = new GrassDetail(generateBurst: 1.0);
        $this->field($this->costlyMask($cost), seed: 1);
        $this->field($this->costlyMask($cost), seed: 2);
        $this->field($this->costlyMask($cost), seed: 3);
        $system = $this->system($detail);

        for ($i = 0; $i < 300; $i++) {
            self::assertLessThanOrEqual((int) ($detail->generateBudgetMs * 1e6) + 2 * $cost, $this->tick($system, 0.0, 0.0), "tick {$i}");
        }
        self::assertGreaterThan(0, $system->cachedCellCount());
    }

    public function testASlicedCellIsTheSameCellWhateverTheBudget(): void
    {
        $detail = new GrassDetail(tuftsPerSquareMetre: 2.0, generateBudgetMs: 2.0);
        $this->field($this->costlyMask(0), -20.0, 20.0, seed: 5);
        $all = $this->system($detail);
        $all->prewarm($this->world, 0.0, 0.0, 200.0);
        $this->tick($all, 0.0, 0.0);
        $expected = $this->drawn();
        self::assertNotSame([], $expected);

        foreach ([0.0, 0.001, 0.05, 2.0] as $budget) {
            $this->world = new World();
            $this->field($this->costlyMask(1_000), -20.0, 20.0, seed: 5);
            $system = $this->system($detail->with(generateBudgetMs: $budget));
            $ticks = 0;
            do {
                $this->tick($system, 0.0, 0.0);
            } while ($system->lastPendingCells > 0 && ++$ticks < 50000);
            self::assertSame(0, $system->lastPendingCells);
            self::assertSame($expected, $this->drawn(), "budget {$budget} ms");
        }
    }

    public function testNothingOfACellShowsBeforeItIsWhole(): void
    {
        $detail = new GrassDetail(generateBudgetMs: 0.05);
        $this->field($this->costlyMask(1_000), 1.0, 11.0);
        $system = $this->system($detail);
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $this->tick($system, 5.0, 8.0);
            $seen[$system->lastInstanceCount] = true;
            if ($system->lastPendingCells === 0) {
                break;
            }
        }
        self::assertGreaterThan(1, $i, 'the cell took several frames');
        self::assertCount(2, $seen, 'no tuft, then all of them: ' . implode(', ', array_keys($seen)));
        self::assertArrayHasKey(0, $seen);
    }

    public function testTheQueueWorksOffEverythingInReachAndComesToRest(): void
    {
        $this->field($this->costlyMask(10_000));
        $system = $this->system(new GrassDetail());

        $this->tick($system, 0.0, 0.0);
        self::assertGreaterThan(0, $system->lastPendingCells);
        for ($i = 0; $i < 20000 && $system->lastPendingCells > 0; $i++) {
            $this->tick($system, 0.0, 0.0);
        }

        self::assertSame(0, $system->lastPendingCells, 'everything in reach is built eventually');
        self::assertSame(INF, $system->lastMissingDistance);
        self::assertGreaterThan(100, $system->cachedCellCount());
        $cells = $system->cachedCellCount();
        self::assertSame(0, $this->tick($system, 0.0, 0.0), 'at rest nothing asks the mask');
        self::assertSame($cells, $system->cachedCellCount());
    }

    public function testNearestCellOfAnyFieldGoesFirst(): void
    {
        $order = [];
        $far = (new GrassMask())->multiply(function (float $x, float $z) use (&$order): float {
            $order[] = 'far';
            return 1.0;
        });
        $near = (new GrassMask())->multiply(function (float $x, float $z) use (&$order): float {
            $order[] = 'near';
            return 1.0;
        });
        // The far field comes first in the world.
        $this->world->createEntity()->attach(new GrassField(30.0, -10.0, 50.0, 10.0, null, $far));
        $this->world->createEntity()->attach(new GrassField(-10.0, -10.0, 10.0, 10.0, null, $near));
        $system = $this->system(new GrassDetail());

        $this->tick($system, 0.0, 0.0);

        self::assertSame('near', $order[0] ?? null);
    }

    public function testAnEditDuringACellDropsTheHalfBuiltWork(): void
    {
        $detail = new GrassDetail(generateBudgetMs: 0.05);
        $mask = $this->costlyMask(1_000);
        $this->field($mask, 1.0, 11.0);
        $system = $this->system($detail);
        $this->tick($system, 5.0, 8.0);
        self::assertSame(0, $system->lastGeneratedCells, 'still on the first cell');

        $mask->excludeRect(0.0, 0.0, 6.0, 12.0, 0.0);
        do {
            $this->tick($system, 5.0, 8.0);
        } while ($system->lastPendingCells > 0);

        $bytes = implode('', $this->drawn());
        self::assertNotSame('', $bytes);
        foreach (str_split($bytes, 64) as $tuft) {
            $x = unpack('g', substr($tuft, 48, 4));
            self::assertIsArray($x);
            self::assertGreaterThanOrEqual(6.0, $x[1], 'no tuft from before the edit');
        }
    }

    public function testAnEditOfASharedMaskRebuildsFinishedCells(): void
    {
        $shared = $this->crowded();
        $field = $this->field((new GrassMask())->multiplyMask($shared), 1.0, 11.0);
        $system = $this->system(new GrassDetail());
        $system->prewarm($this->world, 5.0, 8.0);
        $this->tick($system, 5.0, 8.0);
        self::assertNotSame([], $this->tuftXs());

        $shared->excludeRect(0.0, 0.0, 6.0, 12.0, 0.0);
        $system->prewarm($this->world, 5.0, 8.0);
        $this->tick($system, 5.0, 8.0);

        self::assertNotSame([], $this->tuftXs());
        self::assertGreaterThanOrEqual(6.0, min($this->tuftXs()), 'no tuft of the old mask stays');
        self::assertSame(1.0, $field->density);
    }

    public function testAnEditOfASharedMaskDropsAHalfBuiltCell(): void
    {
        $shared = $this->crowded();
        $mask = (new GrassMask())
            ->multiply(function (float $x, float $z): float {
                $this->now += 1_000;
                return 1.0;
            })
            ->multiplyMask($shared);
        $this->field($mask, 1.0, 11.0);
        $system = $this->system(new GrassDetail(generateBudgetMs: 0.05, generateBurst: 1.0));
        $this->tick($system, 5.0, 8.0);
        self::assertSame(0, $system->lastGeneratedCells, 'still on the first cell');

        $shared->excludeRect(0.0, 0.0, 6.0, 12.0, 0.0);
        do {
            $this->tick($system, 5.0, 8.0);
        } while ($system->lastPendingCells > 0);

        self::assertNotSame([], $this->tuftXs());
        self::assertGreaterThanOrEqual(6.0, min($this->tuftXs()), 'no mix of the old and the new mask');
    }

    public function testACameraFarAboveTheFieldPaysNoBurst(): void
    {
        $cost = 20_000;
        $detail = new GrassDetail();
        $this->field($this->costlyMask($cost));
        $system = $this->system($detail);
        $budget = (int) ($detail->generateBudgetMs * 1e6);

        // Nothing is known of the heights until a cell is finished.
        for ($i = 0; $i < 100 && $system->cachedCellCount() === 0; $i++) {
            $this->tick($system, 0.0, 0.0, 500.0);
        }
        self::assertGreaterThan(0, $system->cachedCellCount());
        for ($i = 0; $i < 200; $i++) {
            self::assertLessThanOrEqual($budget + 2 * $cost, $this->tick($system, 0.0, 0.0, 500.0), "tick {$i}");
            self::assertGreaterThan($detail->fadeRange, $system->lastMissingDistance, 'nothing of it in sight');
        }
    }

    public function testACameraBelowTheFieldPaysNoBurstEither(): void
    {
        $cost = 20_000;
        $detail = new GrassDetail();
        $this->field($this->costlyMask($cost));
        $system = $this->system($detail);
        $system->prewarm($this->world, 0.0, 0.0, 20.0);

        for ($i = 0; $i < 100; $i++) {
            self::assertLessThanOrEqual((int) ($detail->generateBudgetMs * 1e6) + 2 * $cost, $this->tick($system, 0.0, 0.0, -300.0));
        }
    }

    public function testACameraOverTheFieldStillRaisesTheBudgetForACellInSight(): void
    {
        $cost = 20_000;
        $detail = new GrassDetail();
        $this->field($this->costlyMask($cost));
        $system = $this->system($detail);
        $system->prewarm($this->world, 0.0, 0.0, 20.0);

        $this->tick($system, 200.0, 0.0, 1.7); // somewhere new, the same height
        $longest = $this->tick($system, 200.0, 0.0, 1.7);

        self::assertGreaterThan((int) ($detail->generateBudgetMs * 2e6), $longest);
    }

    public function testAThrowingGroundDropsTheHalfBuiltCellAndNothingCountsTwice(): void
    {
        $detail = new GrassDetail(tuftsPerSquareMetre: 2.0, generateBudgetMs: 0.05, generateBurst: 1.0);
        $calls = 0;
        $failAt = 20;
        $field = new GrassField(1.0, 1.0, 11.0, 11.0, function (float $x, float $z) use (&$calls, &$failAt): float {
            if (++$calls === $failAt) {
                throw new \RuntimeException('ground not ready');
            }
            return 0.0;
        }, $this->costlyMask(1_000), seed: 8);
        $this->world->createEntity()->attach($field);
        $failAt = 0;
        $reference = $this->system($detail);
        $reference->prewarm($this->world, 5.0, 8.0);
        $this->tick($reference, 5.0, 8.0);
        $expected = $this->drawn();
        self::assertNotSame([], $expected);

        $calls = 0;
        $failAt = 20;
        $system = $this->system($detail);
        $thrown = 0;
        for ($i = 0; $i < 2000; $i++) {
            try {
                $this->tick($system, 5.0, 8.0);
            } catch (\RuntimeException) {
                $thrown++;
                continue;
            }
            if ($system->lastPendingCells === 0) {
                break;
            }
        }

        self::assertSame(1, $thrown);
        self::assertSame($expected, $this->drawn(), 'no candidate built twice');
    }

    public function testAThrowingMaskDuringPrewarmDropsTheHalfBuiltCellToo(): void
    {
        $detail = new GrassDetail(tuftsPerSquareMetre: 2.0, generateBudgetMs: 0.05, generateBurst: 1.0);
        $armed = false;
        $calls = 0;
        $mask = (new GrassMask())->multiply(function (float $x, float $z) use (&$armed, &$calls): float {
            $this->now += 1_000;
            if ($armed && ++$calls === 10) {
                throw new \RuntimeException('mask not ready');
            }
            return 1.0;
        });
        $this->field($mask, 1.0, 11.0, seed: 8);
        $reference = $this->system($detail);
        $reference->prewarm($this->world, 5.0, 8.0);
        $this->tick($reference, 5.0, 8.0);
        $expected = $this->drawn();

        $system = $this->system($detail);
        $this->tick($system, 5.0, 8.0);
        self::assertSame(0, $system->lastGeneratedCells);
        $armed = true;
        try {
            $system->prewarm($this->world, 5.0, 8.0);
            self::fail('the mask threw');
        } catch (\RuntimeException) {
            $armed = false;
        }
        $system->prewarm($this->world, 5.0, 8.0);
        $this->tick($system, 5.0, 8.0);

        self::assertSame($expected, $this->drawn());
    }

    public function testPrewarmFinishesAHalfBuiltCell(): void
    {
        $detail = new GrassDetail(tuftsPerSquareMetre: 2.0, generateBudgetMs: 0.05, generateBurst: 1.0);
        $this->field($this->costlyMask(1_000), 1.0, 11.0, seed: 8);
        $reference = $this->system($detail);
        $reference->prewarm($this->world, 5.0, 8.0);
        $this->tick($reference, 5.0, 8.0);
        $expected = $this->drawn();

        $system = $this->system($detail);
        $this->tick($system, 5.0, 8.0);
        self::assertGreaterThan(0, $system->lastPendingCells);
        self::assertSame(0, $system->cachedCellCount());
        $made = $system->prewarm($this->world, 5.0, 8.0);
        self::assertGreaterThan(0, $made);
        $this->tick($system, 5.0, 8.0);

        self::assertSame(0, $system->lastPendingCells);
        self::assertSame($expected, $this->drawn());
    }

    public function testAnEntityRemovedDuringACellLeavesNothingBehind(): void
    {
        $detail = new GrassDetail(generateBudgetMs: 0.05, generateBurst: 1.0);
        $entity = $this->world->createEntity();
        $entity->attach(new GrassField(1.0, 1.0, 11.0, 11.0, null, $this->costlyMask(1_000)));
        $other = $this->field($this->costlyMask(0), 1.0, 11.0);
        $system = $this->system($detail);
        $this->tick($system, 5.0, 8.0);
        self::assertGreaterThan(0, $system->lastPendingCells);

        $this->world->destroyEntity($entity->id);
        do {
            $this->tick($system, 5.0, 8.0);
        } while ($system->lastPendingCells > 0);

        $state = (new \ReflectionProperty($system, 'state'))->getValue($system);
        self::assertIsArray($state);
        self::assertCount(1, $state, 'the removed field and its builder are gone');
        self::assertNotSame([], $this->drawn());
        self::assertSame(1.0, $other->density);
    }

    public function testComingBackToAHalfBuiltCellFinishesTheSameCell(): void
    {
        $detail = new GrassDetail(tuftsPerSquareMetre: 2.0, generateBudgetMs: 0.05, generateBurst: 1.0);
        $this->field($this->costlyMask(1_000), 1.0, 11.0, seed: 8);
        $reference = $this->system($detail);
        $reference->prewarm($this->world, 5.0, 8.0);
        $this->tick($reference, 5.0, 8.0);
        $expected = $this->drawn();

        $system = $this->system($detail);
        $this->tick($system, 5.0, 8.0);
        for ($i = 0; $i < 5; $i++) {
            $this->tick($system, 900.0, 900.0); // out of reach: the work is dropped
        }
        do {
            $this->tick($system, 5.0, 8.0);
        } while ($system->lastPendingCells > 0);

        self::assertSame($expected, $this->drawn());
    }
}
