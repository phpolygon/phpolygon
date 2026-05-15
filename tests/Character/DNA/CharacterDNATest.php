<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Character\DNA;

use PHPolygon\Character\DNA\CharacterDNA;
use PHPUnit\Framework\TestCase;

class CharacterDNATest extends TestCase
{
    public function testRandomProducesExactly18Bytes(): void
    {
        $dna = CharacterDNA::random();
        $this->assertSame(CharacterDNA::STRAND_BYTES, strlen($dna->bytes));
        $this->assertSame(18, strlen($dna->bytes));
    }

    public function testConstructorRejectsWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CharacterDNA(str_repeat("\x00", 17));
    }

    public function testConstructorRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CharacterDNA('');
    }

    public function testAcgtRoundtripPreservesBytes(): void
    {
        $rand = new \Random\Randomizer(new \Random\Engine\Mt19937(42));
        for ($i = 0; $i < 50; $i++) {
            $dna = CharacterDNA::random($rand);
            $roundtripped = CharacterDNA::fromAcgt($dna->toAcgt());
            $this->assertSame($dna->bytes, $roundtripped->bytes, "Iteration {$i}");
        }
    }

    public function testToAcgtProducesExactly72Chars(): void
    {
        $dna = CharacterDNA::random();
        $acgt = $dna->toAcgt();
        $this->assertSame(72, strlen($acgt));
        $this->assertMatchesRegularExpression('/^[ACGT]{72}$/', $acgt);
    }

    public function testFromAcgtRejectsWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CharacterDNA::fromAcgt('ACGT');
    }

    public function testFromAcgtRejectsInvalidCharacter(): void
    {
        $bad = str_repeat('A', 71) . 'X';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid ACGT character 'X'");
        CharacterDNA::fromAcgt($bad);
    }

    public function testBaseReturnsValueInRange(): void
    {
        $dna = CharacterDNA::random();
        for ($i = 0; $i < CharacterDNA::STRAND_BASES; $i++) {
            $b = $dna->base($i);
            $this->assertGreaterThanOrEqual(0, $b);
            $this->assertLessThanOrEqual(3, $b);
        }
    }

    public function testBaseRejectsNegativeIndex(): void
    {
        $this->expectException(\OutOfRangeException::class);
        CharacterDNA::random()->base(-1);
    }

    public function testBaseRejectsIndexAtUpperBound(): void
    {
        $this->expectException(\OutOfRangeException::class);
        CharacterDNA::random()->base(72);
    }

    public function testCodonReturnsValueInRange(): void
    {
        $dna = CharacterDNA::random();
        for ($l = 0; $l < CharacterDNA::STRAND_CODONS; $l++) {
            $c = $dna->codon($l);
            $this->assertGreaterThanOrEqual(0, $c);
            $this->assertLessThanOrEqual(63, $c);
        }
    }

    public function testCodonRejectsNegativeLocus(): void
    {
        $this->expectException(\OutOfRangeException::class);
        CharacterDNA::random()->codon(-1);
    }

    public function testCodonRejectsLocusAtUpperBound(): void
    {
        $this->expectException(\OutOfRangeException::class);
        CharacterDNA::random()->codon(24);
    }

    public function testKnownBytesProduceExpectedCodons(): void
    {
        // Construct a known byte sequence:
        //   Byte 0 = 0b11100100 = 0xE4 -> bases A, C, G, T (0,1,2,3)
        //   Byte 1 = 0b00000000 = 0x00 -> bases A, A, A, A (0,0,0,0)
        //   Byte 2 = 0xFF             -> bases T, T, T, T (3,3,3,3)
        // Codon 0 = bases 0..2 = A,C,G = 0 | (1<<2) | (2<<4) = 36
        // Codon 1 = base 3 (T=3) + bases 4,5 (A,A) = 3 | 0 | 0 = 3
        // Codon 2 = bases 6,7,8 = A, A, T = 0 | 0 | (3<<4) = 48
        $bytes = chr(0xE4) . chr(0x00) . chr(0xFF) . str_repeat("\x00", 15);
        $dna = new CharacterDNA($bytes);

        $this->assertSame(36, $dna->codon(0));
        $this->assertSame(3, $dna->codon(1));
        $this->assertSame(48, $dna->codon(2));
    }

    public function testAllZeroBytesProduceAllZeroCodons(): void
    {
        $dna = new CharacterDNA(str_repeat("\x00", 18));
        for ($l = 0; $l < CharacterDNA::STRAND_CODONS; $l++) {
            $this->assertSame(0, $dna->codon($l));
        }
        $this->assertSame(str_repeat('A', 72), $dna->toAcgt());
    }

    public function testAllOneBytesProduceMaxCodons(): void
    {
        $dna = new CharacterDNA(str_repeat("\xFF", 18));
        for ($l = 0; $l < CharacterDNA::STRAND_CODONS; $l++) {
            $this->assertSame(63, $dna->codon($l));
        }
        $this->assertSame(str_repeat('T', 72), $dna->toAcgt());
    }
}
