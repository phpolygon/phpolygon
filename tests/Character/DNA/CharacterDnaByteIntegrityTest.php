<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Character\DNA;

use PHPUnit\Framework\TestCase;
use PHPolygon\Character\DNA\CharacterDNA;
use Random\Randomizer;
use Random\Engine\Mt19937;

/**
 * Guards the byte-range invariant of the DNA codec: every byte produced by the
 * ACGT decoder and by mutate() must stay within 0x00..0xFF. Exercises the
 * `& 0xFF` masking in fromAcgt()/mutate().
 */
class CharacterDnaByteIntegrityTest extends TestCase
{
    private function assertAllBytesInBounds(string $bytes): void
    {
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($bytes[$i]);
            $this->assertGreaterThanOrEqual(0, $b);
            $this->assertLessThanOrEqual(0xFF, $b);
        }
    }

    public function testAcgtDecodeProducesInBoundsBytes(): void
    {
        $dna = CharacterDNA::random();
        $acgt = $dna->toAcgt();
        $rebuilt = CharacterDNA::fromAcgt($acgt);
        $this->assertSame($dna->bytes, $rebuilt->bytes);
        $this->assertAllBytesInBounds($rebuilt->bytes);
    }

    public function testMutateKeepsBytesInBounds(): void
    {
        $dna = CharacterDNA::random();
        $mutated = $dna->mutate(8, new Randomizer(new Mt19937(42)));
        $this->assertSame(strlen($dna->bytes), strlen($mutated->bytes));
        $this->assertAllBytesInBounds($mutated->bytes);
    }

    public function testMutateIsDeterministicForAFixedSeed(): void
    {
        $dna = CharacterDNA::random();
        $a = $dna->mutate(5, new Randomizer(new Mt19937(1234)));
        $b = $dna->mutate(5, new Randomizer(new Mt19937(1234)));
        $this->assertSame($a->bytes, $b->bytes);
    }
}
