<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Pcm;

/**
 * YIN pitch tracker (de Cheveigné & Kawahara 2002) for speech and singing.
 *
 * The signal is low-passed (4th-order Butterworth, 2 kHz) and decimated by 4
 * (11.025 kHz at 44.1 kHz) – the fundamental of a voice lies far below that,
 * and the difference function costs a quarter. Per ≈ 10 ms frame
 * ({@see $hop}): difference function, cumulative-mean normalisation, first
 * dip under the threshold (deepest point of that dip), parabolic
 * interpolation. Frames that are quiet (absolute and 40 dB under the loudest
 * frame) or aperiodic are unvoiced; a 5-frame median smooths the voiced runs
 * and runs shorter than 3 frames are dropped.
 *
 * Input at {@see $sampleRate}. Deterministic.
 */
final class PitchDetector
{
    private const int DECIMATE = 4;
    private const int MIN_RUN_FRAMES = 3;
    private const float VOICED_MAX_DIP = 0.25;
    private const float ABS_RMS_GATE = 1e-4;
    private const float REL_RMS_GATE = 0.01; // −40 dB

    /** Frame hop in samples: ≈ 10 ms, a multiple of the decimation (440 at 44.1 kHz). */
    public readonly int $hop;

    public function __construct(
        private readonly float $minHz = 65.0,
        private readonly float $maxHz = 900.0,
        private readonly float $threshold = 0.15,
        public readonly int $sampleRate = Pcm::SAMPLE_RATE,
    ) {
        if ($minHz <= 0.0 || $maxHz <= $minHz) {
            throw new \InvalidArgumentException("invalid pitch range {$minHz}–{$maxHz} Hz");
        }
        if ($sampleRate < 16000) {
            throw new \InvalidArgumentException("sample rate $sampleRate Hz below 16 kHz");
        }
        $this->hop = intdiv(intdiv($sampleRate + 50, 100), self::DECIMATE) * self::DECIMATE;
    }

    /** @param list<float> $pcm mono at {@see $sampleRate} */
    public function track(array $pcm): PitchTrack
    {
        $n = count($pcm);
        $frames = intdiv($n, $this->hop) + 1;
        if ($n === 0) {
            return new PitchTrack([], $this->hop, 0);
        }
        $srD = $this->sampleRate / self::DECIMATE;
        $hopD = intdiv($this->hop, self::DECIMATE);
        $tauMin = max(2, (int) floor($srD / $this->maxHz));
        $tauMax = (int) ceil($srD / $this->minHz);
        $w = $tauMax + 16;
        $pad = $w + $tauMax;
        $x = $this->decimate($pcm, $pad);

        /** @var list<float> $raw */
        $raw = [];
        /** @var list<float> $rms */
        $rms = [];
        $d = array_fill(0, $tauMax + 2, 0.0);
        $dn = array_fill(0, $tauMax + 2, 1.0);
        for ($f = 0; $f < $frames; $f++) {
            $s = $pad + $f * $hopD - intdiv($w + $tauMax, 2);
            $e = 0.0;
            for ($j = $s, $end = $s + $w; $j < $end; $j++) {
                $e += $x[$j] * $x[$j];
            }
            $rms[] = sqrt($e / $w);
            if ($e <= 0.0) {
                $raw[] = 0.0;
                continue;
            }
            $sum = 0.0;
            for ($tau = 1; $tau <= $tauMax; $tau++) {
                $acc = 0.0;
                for ($j = $s, $end = $s + $w; $j < $end; $j++) {
                    $v = $x[$j] - $x[$j + $tau];
                    $acc += $v * $v;
                }
                $d[$tau] = $acc;
                $sum += $acc;
                $dn[$tau] = $sum > 0.0 ? $acc * $tau / $sum : 1.0;
            }
            $best = -1;
            for ($tau = $tauMin; $tau <= $tauMax; $tau++) {
                if ($dn[$tau] < $this->threshold) {
                    while ($tau + 1 <= $tauMax && $dn[$tau + 1] < $dn[$tau]) {
                        $tau++;
                    }
                    $best = $tau;
                    break;
                }
            }
            if ($best < 0) {
                $best = $tauMin;
                for ($tau = $tauMin + 1; $tau <= $tauMax; $tau++) {
                    if ($dn[$tau] < $dn[$best]) {
                        $best = $tau;
                    }
                }
            }
            if ($dn[$best] > self::VOICED_MAX_DIP || $best >= $tauMax) {
                $raw[] = 0.0;
                continue;
            }
            // $best >= $tauMin >= 2: both neighbours exist.
            $shift = 0.0;
            $a = $dn[$best - 1];
            $b = $dn[$best];
            $c = $dn[$best + 1];
            $den = $a - 2.0 * $b + $c;
            if ($den > 0.0) {
                $shift = max(-0.5, min(0.5, 0.5 * ($a - $c) / $den));
            }
            $raw[] = $srD / ($best + $shift);
        }

        $loud = max([0.0, ...$rms]);
        foreach ($raw as $f => $hz) {
            if ($rms[$f] < self::ABS_RMS_GATE || $rms[$f] < $loud * self::REL_RMS_GATE) {
                $raw[$f] = 0.0;
            }
        }
        return new PitchTrack($this->smooth($raw), $this->hop, $n);
    }

    /**
     * Low-pass (two RBJ biquads, Butterworth Q) and keep every 4th sample,
     * with $pad zeros on both sides.
     *
     * @param list<float> $pcm
     * @return list<float>
     */
    private function decimate(array $pcm, int $pad): array
    {
        $w0 = 2.0 * M_PI * 2000.0 / $this->sampleRate;
        $cs = cos($w0);
        $sn = sin($w0);
        $stages = [];
        foreach ([0.5411961, 1.3065630] as $q) {
            $alpha = $sn / (2.0 * $q);
            $a0 = 1.0 + $alpha;
            $stages[] = [(1.0 - $cs) / 2.0 / $a0, (1.0 - $cs) / $a0, -2.0 * $cs / $a0, (1.0 - $alpha) / $a0];
        }
        [[$b0, $b1, $a1, $a2], [$c0, $c1, $d1, $d2]] = $stages;
        $out = array_fill(0, $pad, 0.0);
        $x1 = $x2 = $y1 = $y2 = $u1 = $u2 = $z1 = $z2 = 0.0;
        foreach ($pcm as $i => $v) {
            $y = $b0 * $v + $b1 * $x1 + $b0 * $x2 - $a1 * $y1 - $a2 * $y2;
            $x2 = $x1;
            $x1 = $v;
            $y2 = $y1;
            $y1 = $y;
            $z = $c0 * $y + $c1 * $u1 + $c0 * $u2 - $d1 * $z1 - $d2 * $z2;
            $u2 = $u1;
            $u1 = $y;
            $z2 = $z1;
            $z1 = $z;
            if ($i % self::DECIMATE === 0) {
                $out[] = $z;
            }
        }
        for ($i = 0; $i < $pad + self::DECIMATE; $i++) {
            $out[] = 0.0;
        }
        return $out;
    }

    /**
     * Median of 5 inside voiced runs; runs shorter than {@see MIN_RUN_FRAMES} go.
     *
     * @param list<float> $raw
     * @return list<float>
     */
    private function smooth(array $raw): array
    {
        $n = count($raw);
        $out = $raw;
        for ($i = 0; $i < $n; $i++) {
            if ($raw[$i] <= 0.0) {
                continue;
            }
            $win = [];
            for ($k = max(0, $i - 2); $k <= min($n - 1, $i + 2); $k++) {
                if ($raw[$k] > 0.0) {
                    $win[] = $raw[$k];
                }
            }
            sort($win);
            $out[$i] = $win[intdiv(count($win), 2)];
        }
        for ($i = 0; $i < $n;) {
            if ($out[$i] <= 0.0) {
                $i++;
                continue;
            }
            $j = $i;
            while ($j < $n && $out[$j] > 0.0) {
                $j++;
            }
            if ($j - $i < self::MIN_RUN_FRAMES) {
                for ($k = $i; $k < $j; $k++) {
                    $out[$k] = 0.0;
                }
            }
            $i = $j;
        }
        return array_values($out);
    }
}
