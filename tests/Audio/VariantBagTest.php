<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use InvalidArgumentException;
use PHPolygon\Audio\VariantBag;
use PHPolygon\Audio\VariantPick;
use PHPUnit\Framework\TestCase;

final class VariantBagTest extends TestCase
{
    private const array CLIPS = ['a', 'b', 'c', 'd'];

    /** @return list<VariantPick> */
    private static function draw(VariantBag $bag, int $count): array
    {
        $picks = [];
        for ($i = 0; $i < $count; $i++) {
            $picks[] = $bag->next();
        }
        return $picks;
    }

    public function testNeverTheSameVariantTwiceInARow(): void
    {
        foreach ([2, 3, 4] as $count) {
            $bag = new VariantBag(array_slice(self::CLIPS, 0, $count), seed: 9);
            $last = null;
            foreach (self::draw($bag, 2000) as $pick) {
                $this->assertNotSame($last, $pick->id);
                $last = $pick->id;
            }
        }
    }

    public function testEveryVariantComesUpAboutEquallyOften(): void
    {
        $counts = array_count_values(array_map(
            static fn (VariantPick $p): string => $p->id,
            self::draw(new VariantBag(self::CLIPS, seed: 4), 4000),
        ));

        $this->assertCount(4, $counts);
        foreach ($counts as $count) {
            $this->assertEqualsWithDelta(1000, $count, 120);
        }
    }

    public function testTheSameSeedGivesTheSameSequence(): void
    {
        $a = new VariantBag(self::CLIPS, seed: 3, pitchJitter: 0.04, gainJitterDb: 1.0);
        $b = new VariantBag(self::CLIPS, seed: 3, pitchJitter: 0.04, gainJitterDb: 1.0);

        $this->assertEquals(self::draw($a, 200), self::draw($b, 200));
    }

    public function testAnotherSeedGivesAnotherSequence(): void
    {
        $a = new VariantBag(self::CLIPS, seed: 3);
        $b = new VariantBag(self::CLIPS, seed: 4);

        $this->assertNotEquals(self::draw($a, 50), self::draw($b, 50));
    }

    public function testJitterDoesNotChangeWhichVariantsAreDrawn(): void
    {
        $ids = static fn (VariantBag $bag): array => array_map(
            static fn (VariantPick $p): string => $p->id,
            self::draw($bag, 100),
        );

        $this->assertSame(
            $ids(new VariantBag(self::CLIPS, seed: 3)),
            $ids(new VariantBag(self::CLIPS, seed: 3, pitchJitter: 0.04, gainJitterDb: 1.0)),
        );
    }

    public function testJitterStaysInsideItsRangeAndUsesIt(): void
    {
        $picks = self::draw(new VariantBag(self::CLIPS, seed: 6, pitchJitter: 0.04, gainJitterDb: 1.0), 2000);
        $pitches = array_map(static fn (VariantPick $p): float => $p->pitch, $picks);
        $gains = array_map(static fn (VariantPick $p): float => 20.0 * log10($p->gain), $picks);

        $this->assertGreaterThanOrEqual(0.96, min($pitches));
        $this->assertLessThanOrEqual(1.04, max($pitches));
        $this->assertLessThan(0.965, min($pitches));
        $this->assertGreaterThan(1.035, max($pitches));
        $this->assertGreaterThanOrEqual(-1.0, min($gains));
        $this->assertLessThanOrEqual(1.0, max($gains));
        $this->assertLessThan(-0.9, min($gains));
        $this->assertGreaterThan(0.9, max($gains));
    }

    public function testWithoutJitterEveryPlayIsNeutral(): void
    {
        foreach (self::draw(new VariantBag(self::CLIPS), 20) as $pick) {
            $this->assertSame(1.0, $pick->pitch);
            $this->assertSame(1.0, $pick->gain);
        }
    }

    public function testASingleVariantStillJitters(): void
    {
        $picks = self::draw(new VariantBag(['only'], seed: 2, pitchJitter: 0.04), 20);

        foreach ($picks as $pick) {
            $this->assertSame('only', $pick->id);
        }
        $this->assertGreaterThan(1, count(array_unique(array_map(static fn (VariantPick $p): float => $p->pitch, $picks))));
    }

    public function testNoVariantsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VariantBag([]);
    }

    public function testNegativeJitterIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VariantBag(['a'], gainJitterDb: -1.0);
    }
}
