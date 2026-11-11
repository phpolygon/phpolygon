<?php

declare(strict_types=1);

namespace PHPolygon\Math;

/**
 * Deterministic, seedable random stream with sequences that are identical on
 * every platform and PHP version with 64-bit ints (PHP_INT_SIZE = 8). Never uses mt_rand/rand/random_int.
 *
 * Algorithm (frozen: changing any step is a breaking change for every game
 * that bakes content from a seed):
 *
 *  - Generator: xoshiro128** (Blackman/Vigna), 128-bit state of four 32-bit
 *    words. Only 32-bit unsigned arithmetic, masked to 32 bits after every
 *    step, so no 64-bit overflow (PHP would turn that into float).
 *      result = rotl32(s1 * 5, 7) * 9
 *      t = s1 << 9; s2 ^= s0; s3 ^= s1; s1 ^= s2; s0 ^= s3; s2 ^= t; s3 = rotl32(s3, 11)
 *  - 32-bit multiply is done as mul32(a, b) = ((a & 0xFFFF) * b + (((a >> 16) * b & 0xFFFF) << 16)) & 0xFFFFFFFF.
 *  - fmix32 (MurmurHash3 finaliser):
 *      h ^= h >> 16; h = mul32(h, 0x85EBCA6B); h ^= h >> 13; h = mul32(h, 0xC2B2AE35); h ^= h >> 16
 *  - Seeding from a 64-bit int: lo = seed & 0xFFFFFFFF, hi = (seed >> 32) & 0xFFFFFFFF,
 *      z = fmix32(lo) ^ fmix32(hi ^ 0x85EBCA6B);
 *      for i in 0..3: z = (z + 0x9E3779B9) & 0xFFFFFFFF; s[i] = fmix32(z)
 *    (an all-zero state is replaced by s0 = 1).
 *  - Child streams: derive($key) builds a new Rng from (this stream's ORIGINAL seed, key),
 *    independent of how many numbers were drawn. key is hashed as the bytes of
 *    (string) $key with 32-bit FNV-1a (offset 0x811C9DC5, prime 0x01000193), then
 *      kh2 = fmix32(kh ^ 0xC2B2AE35)
 *      childSeed = (fmix32(lo ^ kh) << 32) | fmix32(hi ^ kh2)
 *  - float(): nextU32() / 4294967296.0 in [0, 1).
 *  - int(min, max): inclusive, unbiased by rejection on the 32-bit output
 *    (span up to 2^32; larger spans are rejected with an exception).
 *
 * The object holds mutable state by nature (that is the stream); there is no
 * global state, so one Rng per worker/thread is always safe.
 */
final class Rng
{
    private const M = 0xFFFFFFFF;

    private int $s0;
    private int $s1;
    private int $s2;
    private int $s3;
    private readonly int $seed;

    public function __construct(int $seed = 0)
    {
        $this->seed = $seed;
        $lo = $seed & self::M;
        $hi = ($seed >> 32) & self::M;
        $z = self::fmix32($lo) ^ self::fmix32($hi ^ 0x85EBCA6B);
        $s = [];
        for ($i = 0; $i < 4; $i++) {
            $z = ($z + 0x9E3779B9) & self::M;
            $s[$i] = self::fmix32($z);
        }
        if ($s[0] === 0 && $s[1] === 0 && $s[2] === 0 && $s[3] === 0) {
            $s[0] = 1;
        }
        [$this->s0, $this->s1, $this->s2, $this->s3] = $s;
    }

    /** The seed this stream was created with. */
    public function seed(): int
    {
        return $this->seed;
    }

    /** A child stream for (seed, key); independent of the number of draws so far. */
    public function derive(int|string $key): self
    {
        $lo = $this->seed & self::M;
        $hi = ($this->seed >> 32) & self::M;
        $kh = self::fnv1a32((string) $key);
        $kh2 = self::fmix32($kh ^ 0xC2B2AE35);
        $childSeed = (self::fmix32($lo ^ $kh) << 32) | self::fmix32($hi ^ $kh2);
        return new self($childSeed);
    }

    /** Next 32-bit unsigned value. */
    public function nextU32(): int
    {
        $s1 = $this->s1;
        $x = ($s1 * 5) & self::M;
        $x = (($x << 7) | ($x >> 25)) & self::M;
        $result = ($x * 9) & self::M;

        $t = ($s1 << 9) & self::M;
        $this->s2 ^= $this->s0;
        $this->s3 ^= $s1;
        $this->s1 ^= $this->s2;
        $this->s0 ^= $this->s3;
        $this->s2 ^= $t;
        $this->s3 = (($this->s3 << 11) | ($this->s3 >> 21)) & self::M;

        return $result;
    }

    /** Float in [0, 1). */
    public function float(): float
    {
        return $this->nextU32() / 4294967296.0;
    }

    /** Float in [min, max). */
    public function floatRange(float $min, float $max): float
    {
        return $min + ($max - $min) * $this->float();
    }

    /** Integer in [min, max] inclusive, unbiased. */
    public function int(int $min, int $max): int
    {
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }
        $span = $max - $min + 1;
        if ($span > 4294967296) {
            throw new \InvalidArgumentException('Rng::int span exceeds 2^32');
        }
        if ($span === 1) {
            return $min;
        }
        $limit = intdiv(4294967296, $span) * $span;
        do {
            $r = $this->nextU32();
        } while ($r >= $limit);
        return $min + ($r % $span);
    }

    public function bool(float $probability = 0.5): bool
    {
        return $this->float() < $probability;
    }

    /**
     * One element of a non-empty list.
     *
     * @template T
     * @param list<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new \InvalidArgumentException('Rng::pick needs a non-empty list');
        }
        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * Fisher-Yates shuffle (i from n-1 down to 1, j = int(0, i)).
     *
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            $tmp = $items[$i];
            $items[$i] = $items[$j];
            $items[$j] = $tmp;
        }
        return array_values($items);
    }

    /** 32-bit unsigned multiply without leaving PHP's int range. */
    public static function mul32(int $a, int $b): int
    {
        $a &= self::M;
        $b &= self::M;
        return (($a & 0xFFFF) * $b + ((((($a >> 16) * $b)) & 0xFFFF) << 16)) & self::M;
    }

    /** MurmurHash3 32-bit finaliser (see class docs). */
    public static function fmix32(int $h): int
    {
        $h &= self::M;
        $h ^= $h >> 16;
        $h = self::mul32($h, 0x85EBCA6B);
        $h ^= $h >> 13;
        $h = self::mul32($h, 0xC2B2AE35);
        $h ^= $h >> 16;
        return $h;
    }

    private static function fnv1a32(string $s): int
    {
        $h = 0x811C9DC5;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $h ^= ord($s[$i]);
            $h = self::mul32($h, 0x01000193);
        }
        return $h;
    }
}
