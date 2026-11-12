<?php

declare(strict_types=1);

namespace PHPolygon\Animation\Face;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Blink building block: a stateless, deterministic eyelid-closure curve. Time is cut
 * into slots of $meanInterval seconds; each slot holds one blink at a seed-derived
 * offset (so intervals vary between 0 and 2 x the mean minus the blink length, with
 * no state to carry between frames or threads). The lid closes fast and opens slower.
 */
final readonly class Blink
{
    public function __construct(
        public int $seed = 1,
        public float $meanInterval = 4.0,
        public float $duration = 0.18,
        /** Share of the blink spent closing (the rest is opening). */
        public float $closeShare = 0.4,
    ) {
        if ($meanInterval <= $duration || $duration <= 0.0) {
            throw new \InvalidArgumentException('Blink: meanInterval must exceed a positive duration');
        }
    }

    /** Lid closure at $time: 0 open .. 1 closed. */
    public function weight(float $time): float
    {
        $slot = (int) floor($time / $this->meanInterval);
        $rng = new Randomizer(new Mt19937(($this->seed * 7919 + $slot * 104729) & 0x7FFFFFFF));
        $start = $slot * $this->meanInterval + $rng->nextInt() / (float) mt_getrandmax() * ($this->meanInterval - $this->duration);
        $p = ($time - $start) / $this->duration;
        if ($p <= 0.0 || $p >= 1.0) {
            return 0.0;
        }
        if ($p < $this->closeShare) {
            $x = $p / $this->closeShare;

            return $x * $x * (3.0 - 2.0 * $x);
        }
        $x = ($p - $this->closeShare) / (1.0 - $this->closeShare);

        return 1.0 - $x * $x * (3.0 - 2.0 * $x);
    }
}
