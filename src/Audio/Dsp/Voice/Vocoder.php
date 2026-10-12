<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Pcm;

/**
 * A channel vocoder that lets a spoken recording play an instrument.
 *
 * The recording (modulator) is split into log-spaced bands; each band's
 * envelope opens the same band of a held carrier tone ({@see VocoderCarrier},
 * default {@see SawChordCarrier}: root, fifth, octave on detuned saws). The
 * upper bands mix noise into the carrier so s, t and ch stay audible. The
 * vocoded signal is blended with the untouched recording ({@see $dry}) and
 * normalised to {@see PEAK}.
 *
 * The voice itself is not synthesised here – the words come from the
 * recording, the carrier only gives them their robot timbre.
 *
 * Mono in, mono out, both at {@see $sampleRate}. Deterministic.
 */
final class Vocoder
{
    public const float DEFAULT_CARRIER_HZ = 200.0;
    public const float DEFAULT_DRY = 0.75;
    public const float PEAK = 0.891; // −1 dBFS

    private const float LOW_HZ = 90.0;
    private const float HIGH_HZ = 8000.0;
    private const float Q = 6.0;
    private const float ATTACK_S = 0.004;
    private const float RELEASE_S = 0.025;
    /** Band gain: a Q-6 band-pass passes a narrow slice; this restores the level. */
    private const float BAND_GAIN = 6.0;

    private readonly VocoderCarrier $carrier;

    public function __construct(
        private readonly float $carrierHz = self::DEFAULT_CARRIER_HZ,
        private readonly float $dry = self::DEFAULT_DRY,
        private readonly int $bands = 22,
        private readonly int $seed = 4242,
        public readonly int $sampleRate = Pcm::SAMPLE_RATE,
        ?VocoderCarrier $carrier = null,
    ) {
        $this->carrier = $carrier ?? new SawChordCarrier();
    }

    /**
     * @param list<float> $voice mono recording at {@see $sampleRate}
     * @return list<float>
     */
    public function process(array $voice): array
    {
        $n = count($voice);
        if ($n === 0) {
            return [];
        }

        $carrier = $this->carrier->render($this->carrierHz, $n, $this->sampleRate, $this->seed);
        if (count($carrier) < $n) {
            throw new \LogicException(sprintf('vocoder carrier returned %d of %d samples', count($carrier), $n));
        }
        $sr = $this->sampleRate;
        $wet = array_fill(0, $n, 0.0);
        $atk = exp(-1.0 / (self::ATTACK_S * $sr));
        $rel = exp(-1.0 / (self::RELEASE_S * $sr));

        for ($b = 0; $b < $this->bands; $b++) {
            $fc = self::LOW_HZ * ((self::HIGH_HZ / self::LOW_HZ) ** (($b + 0.5) / $this->bands));
            $w0 = 2.0 * M_PI * $fc / $sr;
            $alpha = sin($w0) / (2.0 * self::Q);
            $a0 = 1.0 + $alpha;
            $b0 = $alpha / $a0;
            $b2 = -$alpha / $a0;
            $a1 = -2.0 * cos($w0) / $a0;
            $a2 = (1.0 - $alpha) / $a0;
            $noiseAmt = $fc > 3500.0 ? 0.6 : ($fc > 2000.0 ? 0.25 : 0.0);

            $mx1 = $mx2 = $my1 = $my2 = 0.0;
            $cx1 = $cx2 = $cy1 = $cy2 = 0.0;
            $env = 0.0;
            $rng = ($this->seed + $b * 7919) & 0x7FFFFFFF;

            for ($i = 0; $i < $n; $i++) {
                $x = $voice[$i];
                $y = $b0 * $x + $b2 * $mx2 - $a1 * $my1 - $a2 * $my2;
                $mx2 = $mx1;
                $mx1 = $x;
                $my2 = $my1;
                $my1 = $y;
                $a = abs($y);
                $env = $a > $env ? $atk * $env + (1.0 - $atk) * $a : $rel * $env + (1.0 - $rel) * $a;

                $c = $carrier[$i];
                if ($noiseAmt > 0.0) {
                    $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
                    $c = $c * (1.0 - $noiseAmt) + (($rng / 1073741824.0) - 1.0) * $noiseAmt;
                }
                $cy = $b0 * $c + $b2 * $cx2 - $a1 * $cy1 - $a2 * $cy2;
                $cx2 = $cx1;
                $cx1 = $c;
                $cy2 = $cy1;
                $cy1 = $cy;
                $wet[$i] += $cy * $env * self::BAND_GAIN;
            }
        }

        $out = [];
        $peak = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $v = $wet[$i] * (1.0 - $this->dry) + $voice[$i] * $this->dry;
            $out[] = $v;
            $peak = max($peak, abs($v));
        }
        if ($peak <= 0.0) {
            return $out;
        }
        $g = self::PEAK / $peak;
        foreach ($out as $i => $v) {
            $out[$i] = $v * $g;
        }
        return $out;
    }
}
