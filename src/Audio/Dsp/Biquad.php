<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

use InvalidArgumentException;

/**
 * Second-order IIR filter with the RBJ "Audio EQ Cookbook" responses:
 * low-/high-pass, band-pass (0 dB peak), notch, peaking EQ and low/high
 * shelves. Direct form I, stateful – {@see process()} continues where the
 * last call stopped (blocks join seamlessly), {@see reset()} clears it.
 *
 * $q is the resonance (0.7071 = Butterworth, no peak) for pass and notch
 * filters and the bandwidth for band-pass and peaking; shelves take the
 * shelf slope S (1 = steepest without overshoot).
 */
final class Biquad
{
    public const float BUTTERWORTH_Q = M_SQRT1_2;

    private float $x1 = 0.0;
    private float $x2 = 0.0;
    private float $y1 = 0.0;
    private float $y2 = 0.0;

    /** Normalised coefficients (a0 = 1). */
    public function __construct(
        public readonly float $b0,
        public readonly float $b1,
        public readonly float $b2,
        public readonly float $a1,
        public readonly float $a2,
        public readonly int $sampleRate = Pcm::SAMPLE_RATE,
    ) {}

    public static function lowPass(float $hz, float $q = self::BUTTERWORTH_Q, int $sampleRate = Pcm::SAMPLE_RATE): self
    {
        [$cs, $alpha] = self::prepare($hz, $q, $sampleRate);
        $b1 = 1.0 - $cs;
        return self::normalised($b1 / 2.0, $b1, $b1 / 2.0, 1.0 + $alpha, -2.0 * $cs, 1.0 - $alpha, $sampleRate);
    }

    public static function highPass(float $hz, float $q = self::BUTTERWORTH_Q, int $sampleRate = Pcm::SAMPLE_RATE): self
    {
        [$cs, $alpha] = self::prepare($hz, $q, $sampleRate);
        $b1 = -(1.0 + $cs);
        return self::normalised(-$b1 / 2.0, $b1, -$b1 / 2.0, 1.0 + $alpha, -2.0 * $cs, 1.0 - $alpha, $sampleRate);
    }

    /** Band-pass with 0 dB gain at the centre frequency. */
    public static function bandPass(float $hz, float $q = 1.0, int $sampleRate = Pcm::SAMPLE_RATE): self
    {
        [$cs, $alpha] = self::prepare($hz, $q, $sampleRate);
        return self::normalised($alpha, 0.0, -$alpha, 1.0 + $alpha, -2.0 * $cs, 1.0 - $alpha, $sampleRate);
    }

    public static function notch(float $hz, float $q = 1.0, int $sampleRate = Pcm::SAMPLE_RATE): self
    {
        [$cs, $alpha] = self::prepare($hz, $q, $sampleRate);
        return self::normalised(1.0, -2.0 * $cs, 1.0, 1.0 + $alpha, -2.0 * $cs, 1.0 - $alpha, $sampleRate);
    }

    public static function peaking(float $hz, float $gainDb, float $q = 1.0, int $sampleRate = Pcm::SAMPLE_RATE): self
    {
        [$cs, $alpha] = self::prepare($hz, $q, $sampleRate);
        $a = 10.0 ** ($gainDb / 40.0);
        return self::normalised(
            1.0 + $alpha * $a,
            -2.0 * $cs,
            1.0 - $alpha * $a,
            1.0 + $alpha / $a,
            -2.0 * $cs,
            1.0 - $alpha / $a,
            $sampleRate,
        );
    }

    public static function lowShelf(float $hz, float $gainDb, float $slope = 1.0, int $sampleRate = Pcm::SAMPLE_RATE): self
    {
        return self::shelf($hz, $gainDb, $slope, false, $sampleRate);
    }

    public static function highShelf(float $hz, float $gainDb, float $slope = 1.0, int $sampleRate = Pcm::SAMPLE_RATE): self
    {
        return self::shelf($hz, $gainDb, $slope, true, $sampleRate);
    }

    public function processSample(float $x): float
    {
        $y = $this->b0 * $x + $this->b1 * $this->x1 + $this->b2 * $this->x2 - $this->a1 * $this->y1 - $this->a2 * $this->y2;
        $this->x2 = $this->x1;
        $this->x1 = $x;
        $this->y2 = $this->y1;
        $this->y1 = $y;
        return $y;
    }

    /**
     * @param list<float> $pcm
     * @return list<float>
     */
    public function process(array $pcm): array
    {
        $b0 = $this->b0;
        $b1 = $this->b1;
        $b2 = $this->b2;
        $a1 = $this->a1;
        $a2 = $this->a2;
        $x1 = $this->x1;
        $x2 = $this->x2;
        $y1 = $this->y1;
        $y2 = $this->y2;
        $out = [];
        foreach ($pcm as $x) {
            $y = $b0 * $x + $b1 * $x1 + $b2 * $x2 - $a1 * $y1 - $a2 * $y2;
            $x2 = $x1;
            $x1 = $x;
            $y2 = $y1;
            $y1 = $y;
            $out[] = $y;
        }
        $this->x1 = $x1;
        $this->x2 = $x2;
        $this->y1 = $y1;
        $this->y2 = $y2;
        return $out;
    }

    public function reset(): void
    {
        $this->x1 = $this->x2 = $this->y1 = $this->y2 = 0.0;
    }

    /** Magnitude response at $hz in dB (analytic, independent of the state). */
    public function responseDb(float $hz): float
    {
        $w = 2.0 * M_PI * $hz / $this->sampleRate;
        $c1 = cos($w);
        $s1 = sin($w);
        $c2 = cos(2.0 * $w);
        $s2 = sin(2.0 * $w);
        $nr = $this->b0 + $this->b1 * $c1 + $this->b2 * $c2;
        $ni = -($this->b1 * $s1 + $this->b2 * $s2);
        $dr = 1.0 + $this->a1 * $c1 + $this->a2 * $c2;
        $di = -($this->a1 * $s1 + $this->a2 * $s2);
        $mag2 = ($nr * $nr + $ni * $ni) / max(1e-300, $dr * $dr + $di * $di);
        return 10.0 * log10(max(1e-300, $mag2));
    }

    private static function shelf(float $hz, float $gainDb, float $slope, bool $high, int $sampleRate): self
    {
        if ($slope <= 0.0) {
            throw new InvalidArgumentException("Biquad: shelf slope must be > 0, got {$slope}");
        }
        self::checkFrequency($hz, $sampleRate);
        $a = 10.0 ** ($gainDb / 40.0);
        $w0 = 2.0 * M_PI * $hz / $sampleRate;
        $cs = cos($w0);
        $alpha = sin($w0) / 2.0 * sqrt(max(0.0, ($a + 1.0 / $a) * (1.0 / $slope - 1.0) + 2.0));
        $sq = 2.0 * sqrt($a) * $alpha;
        $sign = $high ? -1.0 : 1.0;

        return self::normalised(
            $a * (($a + 1.0) - $sign * ($a - 1.0) * $cs + $sq),
            $sign * 2.0 * $a * (($a - 1.0) - $sign * ($a + 1.0) * $cs),
            $a * (($a + 1.0) - $sign * ($a - 1.0) * $cs - $sq),
            ($a + 1.0) + $sign * ($a - 1.0) * $cs + $sq,
            -$sign * 2.0 * (($a - 1.0) + $sign * ($a + 1.0) * $cs),
            ($a + 1.0) + $sign * ($a - 1.0) * $cs - $sq,
            $sampleRate,
        );
    }

    /** @return array{float, float} cos(w0), alpha */
    private static function prepare(float $hz, float $q, int $sampleRate): array
    {
        self::checkFrequency($hz, $sampleRate);
        if (!($q > 0.0)) {
            throw new InvalidArgumentException("Biquad: q must be > 0, got {$q}");
        }
        $w0 = 2.0 * M_PI * $hz / $sampleRate;
        return [cos($w0), sin($w0) / (2.0 * $q)];
    }

    private static function checkFrequency(float $hz, int $sampleRate): void
    {
        if ($sampleRate <= 0 || !($hz > 0.0) || $hz >= $sampleRate / 2) {
            throw new InvalidArgumentException("Biquad: frequency must be in (0, {$sampleRate}/2), got {$hz}");
        }
    }

    private static function normalised(float $b0, float $b1, float $b2, float $a0, float $a1, float $a2, int $sampleRate): self
    {
        return new self($b0 / $a0, $b1 / $a0, $b2 / $a0, $a1 / $a0, $a2 / $a0, $sampleRate);
    }
}
