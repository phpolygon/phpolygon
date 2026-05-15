<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Character\DNA;

use PHPolygon\Character\DNA\CharacterDNA;
use PHPUnit\Framework\TestCase;

class MutationCrossoverTest extends TestCase
{
    public function testSingleBitFlipChangesAtMostOneBit(): void
    {
        $rand = new \Random\Randomizer(new \Random\Engine\Mt19937(1));
        $original = CharacterDNA::random($rand);

        for ($i = 0; $i < 30; $i++) {
            $mutant = $original->mutate(1, $rand);
            $this->assertLessThanOrEqual(1, $this->hammingDistance($original->bytes, $mutant->bytes));
        }
    }

    public function testMutateZeroFlipsReturnsIdenticalStrand(): void
    {
        $rand = new \Random\Randomizer(new \Random\Engine\Mt19937(99));
        $original = CharacterDNA::random($rand);
        $mutant = $original->mutate(0, $rand);
        $this->assertSame($original->bytes, $mutant->bytes);
    }

    public function testEightBitFlipsAverageApproachesEight(): void
    {
        $rand = new \Random\Randomizer(new \Random\Engine\Mt19937(2024));
        $original = CharacterDNA::random($rand);

        $total = 0;
        $runs = 100;
        for ($i = 0; $i < $runs; $i++) {
            $mutant = $original->mutate(8, $rand);
            $total += $this->hammingDistance($original->bytes, $mutant->bytes);
        }
        $avg = $total / $runs;

        // 8 flips with possible bit collisions: expected ~7.7 (8 * (1 - 7/144)) - allow generous tolerance.
        $this->assertGreaterThanOrEqual(6.5, $avg, "Average Hamming was {$avg}");
        $this->assertLessThanOrEqual(8.0, $avg, "Average Hamming was {$avg}");
    }

    public function testCrossoverAtNineSplitsBytes(): void
    {
        $a = new CharacterDNA(str_repeat("\xAA", 18));
        $b = new CharacterDNA(str_repeat("\x55", 18));

        $child = CharacterDNA::crossover($a, $b, 9);

        $this->assertSame(str_repeat("\xAA", 9), substr($child->bytes, 0, 9));
        $this->assertSame(str_repeat("\x55", 9), substr($child->bytes, 9));
    }

    public function testCrossoverAtZeroEqualsParentB(): void
    {
        $rand = new \Random\Randomizer(new \Random\Engine\Mt19937(11));
        $a = CharacterDNA::random($rand);
        $b = CharacterDNA::random($rand);

        $child = CharacterDNA::crossover($a, $b, 0);
        $this->assertSame($b->bytes, $child->bytes);
    }

    public function testCrossoverAtStrandBytesEqualsParentA(): void
    {
        $rand = new \Random\Randomizer(new \Random\Engine\Mt19937(12));
        $a = CharacterDNA::random($rand);
        $b = CharacterDNA::random($rand);

        $child = CharacterDNA::crossover($a, $b, CharacterDNA::STRAND_BYTES);
        $this->assertSame($a->bytes, $child->bytes);
    }

    public function testCrossoverRejectsNegativeBytePoint(): void
    {
        $dna = new CharacterDNA(str_repeat("\x00", 18));
        $this->expectException(\OutOfRangeException::class);
        CharacterDNA::crossover($dna, $dna, -1);
    }

    public function testCrossoverRejectsBytePointBeyondStrand(): void
    {
        $dna = new CharacterDNA(str_repeat("\x00", 18));
        $this->expectException(\OutOfRangeException::class);
        CharacterDNA::crossover($dna, $dna, 19);
    }

    public function testMutateAcceptsExternalRandomizer(): void
    {
        $original = new CharacterDNA(str_repeat("\x00", 18));
        $rand1 = new \Random\Randomizer(new \Random\Engine\Mt19937(42));
        $rand2 = new \Random\Randomizer(new \Random\Engine\Mt19937(42));

        $a = $original->mutate(5, $rand1);
        $b = $original->mutate(5, $rand2);

        $this->assertSame($a->bytes, $b->bytes);
    }

    private function hammingDistance(string $a, string $b): int
    {
        $this->assertSame(strlen($a), strlen($b));
        $dist = 0;
        for ($i = 0, $len = strlen($a); $i < $len; $i++) {
            $xor = ord($a[$i]) ^ ord($b[$i]);
            while ($xor !== 0) {
                $dist += $xor & 1;
                $xor >>= 1;
            }
        }
        return $dist;
    }
}
