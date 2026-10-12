<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

/**
 * Fundamental-frequency track of a recording, one value per {@see $hop}
 * samples (frame i belongs to sample i·hop). 0.0 marks an unvoiced frame.
 */
final readonly class PitchTrack
{
    /**
     * @param list<float> $f0 Hz per frame, 0.0 = unvoiced
     */
    public function __construct(
        public array $f0,
        public int $hop,
        public int $length,
    ) {}

    /** f0 at a sample position (linear between two voiced frames), 0.0 when unvoiced. */
    public function f0At(int|float $sample): float
    {
        $count = count($this->f0);
        if ($count === 0) {
            return 0.0;
        }
        $pos = max(0.0, $sample / $this->hop);
        $i = (int) floor($pos);
        if ($i >= $count - 1) {
            return $this->f0[$count - 1];
        }
        $a = $this->f0[$i];
        $b = $this->f0[$i + 1];
        $t = $pos - $i;
        if ($a > 0.0 && $b > 0.0) {
            return $a + ($b - $a) * $t;
        }
        return $t < 0.5 ? $a : $b;
    }

    public function isVoiced(int|float $sample): bool
    {
        return $this->f0At($sample) > 0.0;
    }

    public function voicedRatio(): float
    {
        if ($this->f0 === []) {
            return 0.0;
        }
        $v = 0;
        foreach ($this->f0 as $f) {
            if ($f > 0.0) {
                $v++;
            }
        }
        return $v / count($this->f0);
    }

    /** Median f0 over the voiced frames, 0.0 when nothing is voiced. */
    public function medianF0(): float
    {
        $voiced = array_values(array_filter($this->f0, static fn (float $f): bool => $f > 0.0));
        if ($voiced === []) {
            return 0.0;
        }
        sort($voiced);
        $half = intdiv(count($voiced), 2);
        return count($voiced) % 2 === 1 ? $voiced[$half] : (($voiced[$half - 1] ?? 0.0) + ($voiced[$half] ?? 0.0)) / 2.0;
    }
}
