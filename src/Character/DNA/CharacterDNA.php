<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA;

/**
 * Immutable 18-byte (72-base, 24-codon) DNA strand for procedural characters.
 * Supports ACGT serialisation, codon access, mutation (bit-flips) and crossover (byte-split).
 */
final readonly class CharacterDNA
{
    public const int STRAND_BYTES = 18;
    public const int STRAND_BASES = 72;
    public const int STRAND_CODONS = 24;

    /**
     * @param string $bytes Exactly 18 raw bytes encoding 72 bases (4 bases per byte, 2 bits each).
     */
    public function __construct(public string $bytes)
    {
        if (strlen($bytes) !== self::STRAND_BYTES) {
            throw new \InvalidArgumentException(
                sprintf('CharacterDNA requires exactly %d bytes, got %d', self::STRAND_BYTES, strlen($bytes))
            );
        }
    }

    /** Generate a random DNA strand. Pass a Randomizer for deterministic output. */
    public static function random(?\Random\Randomizer $rand = null): self
    {
        $rand ??= new \Random\Randomizer();
        return new self($rand->getBytes(self::STRAND_BYTES));
    }

    /** Decode a 72-character ACGT string into a DNA strand. */
    public static function fromAcgt(string $acgt): self
    {
        if (strlen($acgt) !== self::STRAND_BASES) {
            throw new \InvalidArgumentException(
                sprintf('ACGT string must be exactly %d chars, got %d', self::STRAND_BASES, strlen($acgt))
            );
        }
        $map = ['A' => 0, 'C' => 1, 'G' => 2, 'T' => 3];
        $bytes = '';
        for ($i = 0; $i < self::STRAND_BASES; $i += 4) {
            $b = 0;
            for ($j = 0; $j < 4; $j++) {
                $ch = $acgt[$i + $j];
                if (!isset($map[$ch])) {
                    throw new \InvalidArgumentException("Invalid ACGT character '{$ch}' at position " . ($i + $j));
                }
                $b |= $map[$ch] << ($j * 2);
            }
            $bytes .= chr($b & 0xFF);
        }
        return new self($bytes);
    }

    /** Encode this strand as a 72-character ACGT string (shareable, human-readable). */
    public function toAcgt(): string
    {
        $chars = ['A', 'C', 'G', 'T'];
        $out = '';
        for ($i = 0; $i < self::STRAND_BYTES; $i++) {
            $b = ord($this->bytes[$i]);
            for ($j = 0; $j < 4; $j++) {
                $out .= $chars[($b >> ($j * 2)) & 0b11];
            }
        }
        return $out;
    }

    /** Return the base value (0..3) at base index (0..71). */
    public function base(int $index): int
    {
        if ($index < 0 || $index >= self::STRAND_BASES) {
            throw new \OutOfRangeException("Base index out of range: {$index}");
        }
        $byte = ord($this->bytes[intdiv($index, 4)]);
        return ($byte >> (($index % 4) * 2)) & 0b11;
    }

    /** Return the codon value (0..63) at locus (0..23). */
    public function codon(int $locus): int
    {
        if ($locus < 0 || $locus >= self::STRAND_CODONS) {
            throw new \OutOfRangeException("Locus out of range: {$locus}");
        }
        $b = $locus * 3;
        return $this->base($b)
            | ($this->base($b + 1) << 2)
            | ($this->base($b + 2) << 4);
    }

    /**
     * Return a new DNA with up to $bitFlips random bit flips applied.
     * If the same bit is hit twice the flip cancels, so Hamming distance is <= $bitFlips.
     */
    public function mutate(int $bitFlips = 1, ?\Random\Randomizer $rand = null): self
    {
        $rand ??= new \Random\Randomizer();
        $bytes = $this->bytes;
        $maxBit = self::STRAND_BYTES * 8 - 1;
        for ($i = 0; $i < $bitFlips; $i++) {
            $pos = $rand->getInt(0, $maxBit);
            $byteIdx = intdiv($pos, 8);
            $bytes[$byteIdx] = chr((ord($bytes[$byteIdx]) ^ (1 << ($pos % 8))) & 0xFF);
        }
        return new self($bytes);
    }

    /**
     * Produce a child strand: first $bytePoint bytes from $a, remaining bytes from $b.
     * $bytePoint = 0 returns $b verbatim; $bytePoint = STRAND_BYTES returns $a verbatim.
     */
    public static function crossover(self $a, self $b, int $bytePoint): self
    {
        if ($bytePoint < 0 || $bytePoint > self::STRAND_BYTES) {
            throw new \OutOfRangeException("bytePoint out of range: {$bytePoint}");
        }
        return new self(substr($a->bytes, 0, $bytePoint) . substr($b->bytes, $bytePoint));
    }
}
