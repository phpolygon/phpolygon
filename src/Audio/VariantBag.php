<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use InvalidArgumentException;
use PHPolygon\Audio\Dsp\Pcm;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Picks among the variants of a repeated sound so it does not read as one sample
 * firing over and over: never the same variant twice in a row, plus a little
 * pitch and level movement on every play (which also works with one variant).
 *
 * Not a shuffle: every variant but the last one is equally likely on each draw,
 * so there is no audible round through the set. Deterministic: the same seed
 * gives the same sequence, and each draw consumes the same amount of the stream.
 * Pure state, cheap enough to call on every play.
 */
final class VariantBag
{
    private readonly Randomizer $rng;
    private int $last = -1;

    /**
     * @param list<string> $variants clip ids (or any other names)
     * @param float $pitchJitter pitch moves within 1 ± this
     * @param float $gainJitterDb level moves within ± this many dB
     */
    public function __construct(
        public readonly array $variants,
        int $seed = 1,
        public readonly float $pitchJitter = 0.0,
        public readonly float $gainJitterDb = 0.0,
    ) {
        if ($variants === []) {
            throw new InvalidArgumentException('VariantBag: need at least one variant');
        }
        if ($pitchJitter < 0.0 || $pitchJitter >= 1.0) {
            throw new InvalidArgumentException("VariantBag: pitchJitter must be in [0, 1), got {$pitchJitter}");
        }
        if ($gainJitterDb < 0.0) {
            throw new InvalidArgumentException("VariantBag: gainJitterDb must be >= 0, got {$gainJitterDb}");
        }
        $this->rng = new Randomizer(new Xoshiro256StarStar($seed));
    }

    public function next(): VariantPick
    {
        $n = count($this->variants);
        $hasLast = $this->last >= 0 && $n > 1;
        $index = (int) floor($this->rng->nextFloat() * ($hasLast ? $n - 1 : $n));
        if ($hasLast && $index >= $this->last) {
            $index++;
        }
        $this->last = $index;

        $pitch = 1.0 + $this->pitchJitter * (2.0 * $this->rng->nextFloat() - 1.0);
        $gain = Pcm::db($this->gainJitterDb * (2.0 * $this->rng->nextFloat() - 1.0));

        return new VariantPick($this->variants[$index], $pitch, $gain);
    }
}
