<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

/**
 * Offline look-ahead peak limiter: keeps every sample of a finished mix at or
 * below a ceiling without clipping it.
 *
 * Works on whole buffers, so it can look ahead for free:
 *  1. Every frame gets the gain it needs (ceiling / peak, 1 when below). With
 *     {@see $truePeak} the peak also covers the waveform BETWEEN samples
 *     (4× Catmull-Rom estimate) – what a DAC or a lossy codec will reproduce.
 *  2. A backward pass lets the gain fall linearly ahead of each peak: a full
 *     swing (1 → 0) takes {@see $lookaheadMs}, so any reduction is complete
 *     when the peak arrives – no overshoot, no hard clip.
 *  3. A forward pass lets the gain recover linearly: a full swing (0 → 1)
 *     takes {@see $releaseMs}.
 *
 * Channels are linked (one gain for all), so the stereo image stays put.
 * A buffer that never exceeds the ceiling comes back bit-identical; so does
 * every sample further than look-ahead before / release after a reduction.
 * Deterministic: same input, same output.
 */
final class Limiter
{
    /** Final sample ceiling (linear). */
    public readonly float $ceiling;

    public function __construct(
        public readonly float $ceilingDb = -1.0,
        public readonly float $lookaheadMs = 3.0,
        public readonly float $releaseMs = 250.0,
        public readonly int $sampleRate = Pcm::SAMPLE_RATE,
        public readonly bool $truePeak = false,
    ) {
        if ($ceilingDb > 0.0 || is_nan($ceilingDb)) {
            throw new \InvalidArgumentException('Limiter ceiling must be <= 0 dBFS');
        }
        if ($lookaheadMs < 0.0 || $releaseMs < 0.0 || $sampleRate <= 0) {
            throw new \InvalidArgumentException('Limiter times must be >= 0 and the sample rate > 0');
        }
        $this->ceiling = Pcm::db($ceilingDb);
    }

    /**
     * @param list<float> $samples
     * @return list<float>
     */
    public function processMono(array $samples): array
    {
        return $this->process([$samples])[0];
    }

    /**
     * @param list<float> $left
     * @param list<float> $right same length as $left
     * @return array{0: list<float>, 1: list<float>}
     */
    public function processStereo(array $left, array $right): array
    {
        $out = $this->process([$left, $right]);
        return [$out[0], $out[1]];
    }

    /**
     * Limit any number of linked channels of equal length.
     *
     * @param list<list<float>> $channels
     * @return list<list<float>>
     */
    public function process(array $channels): array
    {
        $gains = $this->gains($channels);
        if ($gains === null) {
            return $channels;
        }
        $ceiling = $this->ceiling;
        foreach ($channels as $c => $samples) {
            foreach ($samples as $i => $v) {
                $v *= $gains[$i];
                // Rounding in ceiling / peak · peak may land one ulp above.
                if ($v > $ceiling) {
                    $v = $ceiling;
                } elseif ($v < -$ceiling) {
                    $v = -$ceiling;
                }
                $samples[$i] = $v;
            }
            $channels[$c] = $samples;
        }
        return $channels;
    }

    /**
     * The gain curve the limiter would apply (one value per frame), or null
     * when nothing exceeds the ceiling.
     *
     * @param list<list<float>> $channels
     * @return ?list<float>
     */
    public function gains(array $channels): ?array
    {
        $n = $channels === [] ? 0 : count($channels[0]);
        foreach ($channels as $samples) {
            if (count($samples) !== $n) {
                throw new \InvalidArgumentException('Limiter channels must have the same length');
            }
        }
        if ($n === 0) {
            return null;
        }

        $peaks = $this->peaks($channels, $n);
        $need = [];
        $any = false;
        foreach ($peaks as $p) {
            $g = $p > $this->ceiling ? $this->ceiling / $p : 1.0;
            $any = $any || $g < 1.0;
            $need[] = $g;
        }
        if (!$any) {
            return null;
        }

        // Fall ahead of each peak: at most 1/lookahead per sample.
        $lookahead = $this->lookaheadMs * 0.001 * $this->sampleRate;
        if ($lookahead > 0.0) {
            $attack = 1.0 / $lookahead;
            for ($i = $n - 2; $i >= 0; $i--) {
                $limit = $need[$i + 1] + $attack;
                if ($limit < $need[$i]) {
                    $need[$i] = $limit;
                }
            }
        }

        // Recover after it: at most 1/release per sample.
        $releaseSamples = $this->releaseMs * 0.001 * $this->sampleRate;
        $release = $releaseSamples > 0.0 ? 1.0 / $releaseSamples : 1.0;
        $g = 1.0;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $g = min($need[$i], $g + $release);
            $out[] = $g;
        }
        return $out;
    }

    /**
     * Linked absolute peak per frame (sample peak, or estimated true peak).
     *
     * @param list<list<float>> $channels
     * @return list<float>
     */
    private function peaks(array $channels, int $n): array
    {
        $peaks = array_fill(0, $n, 0.0);
        foreach ($channels as $x) {
            for ($i = 0; $i < $n; $i++) {
                $a = abs($x[$i]);
                if ($a > $peaks[$i]) {
                    $peaks[$i] = $a;
                }
            }
            if (!$this->truePeak) {
                continue;
            }
            // Inter-sample peaks between i and i+1, owned by both samples.
            for ($i = 0; $i < $n - 1; $i++) {
                $p0 = $x[$i - 1] ?? $x[$i];
                $p1 = $x[$i];
                $p2 = $x[$i + 1];
                $p3 = $x[$i + 2] ?? $p2;
                $m = max(
                    abs(self::catmullRom($p0, $p1, $p2, $p3, 0.25)),
                    abs(self::catmullRom($p0, $p1, $p2, $p3, 0.5)),
                    abs(self::catmullRom($p0, $p1, $p2, $p3, 0.75)),
                );
                if ($m > $peaks[$i]) {
                    $peaks[$i] = $m;
                }
                if ($m > $peaks[$i + 1]) {
                    $peaks[$i + 1] = $m;
                }
            }
        }
        return $peaks;
    }

    /** Catmull-Rom spline between $p1 and $p2 at $t ∈ [0, 1]. */
    public static function catmullRom(float $p0, float $p1, float $p2, float $p3, float $t): float
    {
        $t2 = $t * $t;
        $t3 = $t2 * $t;
        return 0.5 * (
            2.0 * $p1
            + (-$p0 + $p2) * $t
            + (2.0 * $p0 - 5.0 * $p1 + 4.0 * $p2 - $p3) * $t2
            + (-$p0 + 3.0 * $p1 - 3.0 * $p2 + $p3) * $t3
        );
    }
}
