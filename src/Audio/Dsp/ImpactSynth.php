<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

use InvalidArgumentException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * One-shot impact: something hits something. A low decaying sine (the thump of
 * the mass), decaying sine modes (what the struck material rings at) and bursts
 * of filtered noise ({@see ImpactNoise}: scrape, crack, second contact).
 *
 * Footsteps, doors set down, crates, tools - the material is entirely in the
 * parameters, the engine ships none. $strikes repeats the thump and the modes
 * (heel, then toe); noise bursts have their own delays. $detune moves the thump
 * and every mode by up to ± that fraction, drawn from the seed, so seeds of one
 * recipe are variants of one material rather than copies of one sample.
 *
 * Starts at 0 and ends at 0 (a short fade closes the buffer, whatever still
 * rings), so it plays without a click. Deterministic: the same parameters, seed
 * and sample rate give the same samples. Not normalised - follow it with
 * {@see Limiter} or scale it to the peak the game wants.
 */
final class ImpactSynth
{
    private const float END_FADE_SECONDS = 0.002;

    /**
     * @param list<array{0: float, 1: float, 2: float}> $modes [Hz, gain, decay time constant in seconds]
     * @param list<ImpactNoise> $noise
     * @param list<array{0: float, 1: float}> $strikes [start in seconds, gain]
     */
    public function __construct(
        public readonly float $thumpHz = 90.0,
        public readonly float $thumpGain = 1.0,
        public readonly float $thumpDecaySeconds = 0.05,
        public readonly array $modes = [],
        public readonly array $noise = [],
        public readonly array $strikes = [[0.0, 1.0]],
        public readonly float $detune = 0.0,
        public readonly float $attackSeconds = 0.0015,
        public readonly int $seed = 1,
    ) {
        if (!($thumpHz > 0.0) || !($thumpDecaySeconds > 0.0)) {
            throw new InvalidArgumentException('ImpactSynth: need thumpHz > 0 and thumpDecaySeconds > 0');
        }
        foreach ($modes as [$hz, , $decay]) {
            if (!($hz > 0.0) || !($decay > 0.0)) {
                throw new InvalidArgumentException('ImpactSynth: a mode needs Hz > 0 and decay > 0');
            }
        }
        foreach ($strikes as [$start]) {
            if ($start < 0.0) {
                throw new InvalidArgumentException("ImpactSynth: a strike cannot start before 0, got {$start}");
            }
        }
        if ($detune < 0.0 || $detune >= 1.0) {
            throw new InvalidArgumentException("ImpactSynth: detune must be in [0, 1), got {$detune}");
        }
        if ($attackSeconds < 0.0) {
            throw new InvalidArgumentException("ImpactSynth: attackSeconds must be >= 0, got {$attackSeconds}");
        }
    }

    /**
     * Render $seconds of the impact.
     *
     * @return list<float>
     */
    public function render(float $seconds, int $sampleRate = Pcm::SAMPLE_RATE): array
    {
        $n = max(0, (int) round($seconds * $sampleRate));
        if ($n === 0) {
            return [];
        }
        $out = array_fill(0, $n, 0.0);
        $rng = new Randomizer(new Xoshiro256StarStar($this->seed));

        // Every partial draws its detune even at 0, so changing $detune never
        // changes the noise that follows in the stream.
        $partials = [[$this->detuned($this->thumpHz, $rng), $this->thumpGain, $this->thumpDecaySeconds]];
        foreach ($this->modes as [$hz, $gain, $decay]) {
            $partials[] = [$this->detuned($hz, $rng), $gain, $decay];
        }

        foreach ($this->strikes as [$start, $strikeGain]) {
            foreach ($partials as [$hz, $gain, $decay]) {
                $this->addPartial($out, $start, $hz, $gain * $strikeGain, $decay, $sampleRate);
            }
        }
        foreach ($this->noise as $burst) {
            $this->addNoise($out, $burst, $rng, $sampleRate);
        }

        return Envelope::ar(0.0, min(self::END_FADE_SECONDS, $seconds * 0.5))->apply(array_values($out), null, $sampleRate);
    }

    private function detuned(float $hz, Randomizer $rng): float
    {
        return $hz * (1.0 + $this->detune * (2.0 * $rng->nextFloat() - 1.0));
    }

    /** @param array<int, float> $out */
    private function addPartial(array &$out, float $start, float $hz, float $gain, float $decay, int $sampleRate): void
    {
        $n = count($out);
        $first = (int) round($start * $sampleRate);
        $attack = $this->attackSeconds * $sampleRate;
        $phase = 2.0 * M_PI * $hz / $sampleRate;
        $fall = 1.0 / ($decay * $sampleRate);

        for ($k = 0; $first + $k < $n; $k++) {
            $amp = $gain * exp(-$k * $fall);
            if ($k < $attack) {
                $amp *= $k / $attack;
            }
            $out[$first + $k] += $amp * sin($phase * $k);
        }
    }

    /** @param array<int, float> $out */
    private function addNoise(array &$out, ImpactNoise $burst, Randomizer $rng, int $sampleRate): void
    {
        $n = count($out);
        $first = (int) round($burst->delaySeconds * $sampleRate);
        $len = $n - $first;
        if ($len <= 0) {
            return;
        }

        $white = [];
        for ($k = 0; $k < $len; $k++) {
            $white[] = 2.0 * $rng->nextFloat() - 1.0;
        }
        if ($burst->bandHz !== null) {
            $white = Biquad::bandPass(Pcm::belowNyquist($burst->bandHz, $sampleRate), $burst->bandQ, $sampleRate)->process($white);
        }
        if ($burst->lowPassHz !== null) {
            $white = Biquad::lowPass(Pcm::belowNyquist($burst->lowPassHz, $sampleRate), Biquad::BUTTERWORTH_Q, $sampleRate)->process($white);
        }
        if ($burst->highPassHz !== null) {
            $white = Biquad::highPass(Pcm::belowNyquist($burst->highPassHz, $sampleRate), Biquad::BUTTERWORTH_Q, $sampleRate)->process($white);
        }

        // At least one sample, so a burst always starts from 0.
        $attack = max(1.0, $burst->attackSeconds * $sampleRate);
        $fall = 1.0 / ($burst->decaySeconds * $sampleRate);
        foreach ($white as $k => $x) {
            $amp = $burst->gain * exp(-$k * $fall);
            if ($k < $attack) {
                $amp *= $k / $attack;
            }
            $out[$first + $k] += $amp * $x;
        }
    }
}
