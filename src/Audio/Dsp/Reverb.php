<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

use InvalidArgumentException;

/**
 * Small algorithmic reverb after Freeverb (Jezar, public domain): per channel
 * eight damped feedback combs in parallel ({@see CombFilter}) into four
 * all-passes in series ({@see AllpassFilter} – true Schroeder all-passes,
 * where Freeverb uses a non-flat approximation); the right channel's delays
 * are offset by a stereo spread. Delay lengths are Freeverb's 44.1 kHz
 * tunings scaled to the sample rate.
 *
 * - $roomSize 0…1: tail length (comb feedback 0.70…0.98).
 * - $damping 0…1: how much faster highs decay than lows.
 * - $wet / $dry: linear gains of the reverberated and the direct signal
 *   (wet 1 ≈ Freeverb's full wet level, dry 1 = input unchanged).
 * - $width 0…1: stereo width of the tail (0 = both channels identical).
 * - $preDelaySeconds: gap before the tail starts.
 *
 * The process methods start from silence every call (no state carried over)
 * and append $tailSeconds of ring-out after the input.
 */
final class Reverb
{
    private const array COMB_TUNING = [1116, 1188, 1277, 1356, 1422, 1491, 1557, 1617];
    private const array ALLPASS_TUNING = [556, 441, 341, 225];
    private const int STEREO_SPREAD = 23;
    private const float FIXED_GAIN = 0.015;
    private const float SCALE_WET = 3.0;
    private const float SCALE_ROOM = 0.28;
    private const float OFFSET_ROOM = 0.7;
    private const float SCALE_DAMP = 0.4;
    private const float ALLPASS_GAIN = 0.5;

    public function __construct(
        public readonly float $roomSize = 0.5,
        public readonly float $damping = 0.5,
        public readonly float $wet = 0.33,
        public readonly float $dry = 1.0,
        public readonly float $width = 1.0,
        public readonly float $preDelaySeconds = 0.0,
        public readonly int $sampleRate = Pcm::SAMPLE_RATE,
    ) {
        foreach (['roomSize' => $roomSize, 'damping' => $damping, 'width' => $width] as $name => $v) {
            if ($v < 0.0 || $v > 1.0) {
                throw new InvalidArgumentException("Reverb: {$name} must be in [0, 1], got {$v}");
            }
        }
        if ($preDelaySeconds < 0.0 || $sampleRate <= 0) {
            throw new InvalidArgumentException('Reverb: preDelaySeconds must be >= 0 and sampleRate > 0');
        }
    }

    /**
     * Mono in, mono out (the average of the stereo tail).
     *
     * @param list<float> $pcm
     * @return list<float>
     */
    public function processMono(array $pcm, float $tailSeconds = 0.0): array
    {
        [$l, $r] = $this->processStereo($pcm, $pcm, $tailSeconds);
        $out = [];
        foreach ($l as $i => $v) {
            $out[] = 0.5 * ($v + $r[$i]);
        }
        return $out;
    }

    /**
     * @param list<float> $left
     * @param list<float> $right
     * @return array{list<float>, list<float>}
     */
    public function processStereo(array $left, array $right, float $tailSeconds = 0.0): array
    {
        $n = max(count($left), count($right));
        $total = $n + max(0, (int) round($tailSeconds * $this->sampleRate));
        $scale = $this->sampleRate / 44100.0;

        $feedback = $this->roomSize * self::SCALE_ROOM + self::OFFSET_ROOM;
        $damp = $this->damping * self::SCALE_DAMP;
        $wet = $this->wet * self::SCALE_WET;
        $wet1 = $wet * ($this->width / 2.0 + 0.5);
        $wet2 = $wet * ((1.0 - $this->width) / 2.0);

        /** @var array{list<CombFilter>, list<CombFilter>} $combs */
        $combs = [[], []];
        /** @var array{list<AllpassFilter>, list<AllpassFilter>} $allpasses */
        $allpasses = [[], []];
        foreach ([0, 1] as $ch) {
            $spread = $ch * self::STEREO_SPREAD;
            foreach (self::COMB_TUNING as $t) {
                $combs[$ch][] = new CombFilter(max(1, (int) round(($t + $spread) * $scale)), $feedback, $damp);
            }
            foreach (self::ALLPASS_TUNING as $t) {
                $allpasses[$ch][] = new AllpassFilter(max(1, (int) round(($t + $spread) * $scale)), self::ALLPASS_GAIN);
            }
        }

        $preDelay = (int) round($this->preDelaySeconds * $this->sampleRate);
        $outL = [];
        $outR = [];
        for ($i = 0; $i < $total; $i++) {
            $inL = $left[$i] ?? 0.0;
            $inR = $right[$i] ?? 0.0;
            $j = $i - $preDelay;
            $feed = $j >= 0 ? (($left[$j] ?? 0.0) + ($right[$j] ?? 0.0)) * self::FIXED_GAIN : 0.0;

            $accL = 0.0;
            $accR = 0.0;
            foreach ($combs[0] as $c) {
                $accL += $c->processDelayed($feed);
            }
            foreach ($combs[1] as $c) {
                $accR += $c->processDelayed($feed);
            }
            foreach ($allpasses[0] as $a) {
                $accL = $a->processSample($accL);
            }
            foreach ($allpasses[1] as $a) {
                $accR = $a->processSample($accR);
            }

            $outL[] = $accL * $wet1 + $accR * $wet2 + $inL * $this->dry;
            $outR[] = $accR * $wet1 + $accL * $wet2 + $inR * $this->dry;
        }
        return [$outL, $outR];
    }
}
