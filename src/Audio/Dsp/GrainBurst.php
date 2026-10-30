<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

use Closure;
use InvalidArgumentException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Granular friction noise: many very short bursts of band-limited noise - what
 * crunching, rustling, scraping and crumbling are made of. Scattered at a density
 * (grains per second) that may follow a Closure(t), so one texture can swell in
 * two lobes as a foot rolls from heel to toe.
 *
 * The sibling of {@see BubbleGenerator}, and it uses that class for the part they
 * share: start time, centre frequency (log-uniform), length and gain of every
 * grain come from {@see BubbleGenerator::schedule()}. The difference is the grain
 * itself. A bubble is a sine, so one bubble is a narrow ping: 4 ms at 3 kHz spreads
 * about 270 Hz around its centre. A grain here is noise through a band-pass of
 * bandwidth centre / $q: about 2900 Hz at $q = 1, 1700 Hz at $q = 4. Sparse textures
 * show it most, dense sines overlap into something noise-like anyway.
 *
 * Each grain is scaled so its own peak is its drawn gain; it has a 0.5 ms attack,
 * decays exponentially by $decayDb and fades out over 1 ms. A short fade closes
 * the buffer, so it starts and ends at 0. Deterministic: the same parameters,
 * seed and sample rate give the same samples. The sum is not normalised.
 */
final class GrainBurst
{
    private const float ATTACK_SECONDS = 0.0005;
    private const float GRAIN_FADE_SECONDS = 0.001;
    private const float END_FADE_SECONDS = 0.002;

    /** Keeps the noise of a grain independent of the draws that placed it. */
    private const int NOISE_SEED_MIX = 0x5BD1E995;

    private readonly BubbleGenerator $scheduler;

    public function __construct(
        public readonly float $minHz = 800.0,
        public readonly float $maxHz = 5000.0,
        public readonly float $minSeconds = 0.002,
        public readonly float $maxSeconds = 0.008,
        public readonly float $q = 1.0,
        public readonly float $minGain = 0.05,
        public readonly float $maxGain = 0.25,
        public readonly float $decayDb = 30.0,
        public readonly int $seed = 1,
    ) {
        if (!($q > 0.0)) {
            throw new InvalidArgumentException("GrainBurst: q must be > 0, got {$q}");
        }
        // Validates the shared parameters; the glide of a bubble means nothing here.
        $this->scheduler = new BubbleGenerator($minHz, $maxHz, $minSeconds, $maxSeconds, 0.0, $minGain, $maxGain, $decayDb, $seed);
    }

    /**
     * Render $seconds of grains. $density is grains per second - a constant, or a
     * Closure(float $t): float for a texture that swells or thins out.
     *
     * @param float|Closure(float): float $density
     * @return list<float>
     */
    public function render(float $seconds, float|Closure $density, int $sampleRate = Pcm::SAMPLE_RATE): array
    {
        $n = max(0, (int) round($seconds * $sampleRate));
        if ($n === 0) {
            return [];
        }
        $out = array_fill(0, $n, 0.0);
        $rng = new Randomizer(new Xoshiro256StarStar($this->seed ^ self::NOISE_SEED_MIX));

        foreach ($this->schedule($seconds, $density) as $grain) {
            $this->addGrain($out, $grain, $rng, $sampleRate);
        }

        return Envelope::ar(0.0, min(self::END_FADE_SECONDS, $seconds * 0.5))->apply(array_values($out), null, $sampleRate);
    }

    /**
     * The grains render() would play: start time, band centre, length and gain.
     *
     * @param float|Closure(float): float $density
     * @return list<array{start: float, hz: float, seconds: float, gain: float}>
     */
    public function schedule(float $seconds, float|Closure $density): array
    {
        return $this->scheduler->schedule($seconds, $density);
    }

    /**
     * @param array<int, float> $out
     * @param array{start: float, hz: float, seconds: float, gain: float} $grain
     */
    private function addGrain(array &$out, array $grain, Randomizer $rng, int $sampleRate): void
    {
        $len = max(1, (int) round($grain['seconds'] * $sampleRate));
        $attack = max(1, (int) round(self::ATTACK_SECONDS * $sampleRate));
        $endFade = max(1, (int) round(self::GRAIN_FADE_SECONDS * $sampleRate));
        $decayRate = $this->decayDb / 20.0 * M_LN10 / $len;
        $filter = Biquad::bandPass(Pcm::belowNyquist($grain['hz'], $sampleRate), $this->q, $sampleRate);

        // Every grain draws its full length, so the noise of one grain does not
        // depend on how much of the one before fell past the end of the buffer.
        $samples = [];
        $peak = 0.0;
        for ($k = 0; $k < $len; $k++) {
            $amp = exp(-$decayRate * $k) * min(1.0, $k / $attack) * min(1.0, ($len - 1 - $k) / $endFade);
            $x = $amp * $filter->processSample(2.0 * $rng->nextFloat() - 1.0);
            $samples[] = $x;
            $peak = max($peak, abs($x));
        }
        if ($peak <= 0.0) {
            return;
        }

        $n = count($out);
        $first = (int) round($grain['start'] * $sampleRate);
        $scale = $grain['gain'] / $peak;
        foreach ($samples as $k => $x) {
            if ($first + $k >= $n) {
                return;
            }
            $out[$first + $k] += $x * $scale;
        }
    }
}
