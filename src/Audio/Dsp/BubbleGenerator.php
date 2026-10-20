<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

use Closure;
use InvalidArgumentException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Granular bubble texture: many short sine "blips" whose pitch glides upward
 * while they decay – the sound of a single air bubble in liquid (Minnaert
 * resonance, f rising as the bubble nears the surface). Scattered at a
 * density (bubbles per second, Poisson-distributed) they make anything from
 * a lone drip to boiling, fizz or an aquarium pump.
 *
 * Per bubble, drawn from the seeded generator: start frequency log-uniform in
 * [$minHz, $maxHz], length uniform in [$minSeconds, $maxSeconds], gain uniform
 * in [$minGain, $maxGain]. The frequency glides to start × (1 + $glide) over
 * the bubble's life; the amplitude has a 0.5 ms attack and decays
 * exponentially by $decayDb, with a 1 ms fade at the end – no clicks.
 *
 * Deterministic: the same parameters and seed give the same samples. The sum
 * is not normalised – a dense texture can exceed ±1; follow it with
 * {@see Limiter} or scale the gains.
 */
final class BubbleGenerator
{
    private const float ATTACK_SECONDS = 0.0005;
    private const float END_FADE_SECONDS = 0.001;

    public function __construct(
        public readonly float $minHz = 400.0,
        public readonly float $maxHz = 2500.0,
        public readonly float $minSeconds = 0.01,
        public readonly float $maxSeconds = 0.06,
        public readonly float $glide = 0.6,
        public readonly float $minGain = 0.05,
        public readonly float $maxGain = 0.25,
        public readonly float $decayDb = 40.0,
        public readonly int $seed = 1,
    ) {
        if (!($minHz > 0.0) || $maxHz < $minHz) {
            throw new InvalidArgumentException('BubbleGenerator: need 0 < minHz <= maxHz');
        }
        if (!($minSeconds > 0.0) || $maxSeconds < $minSeconds) {
            throw new InvalidArgumentException('BubbleGenerator: need 0 < minSeconds <= maxSeconds');
        }
        if ($glide <= -1.0) {
            throw new InvalidArgumentException("BubbleGenerator: glide must be > -1, got {$glide}");
        }
        if ($minGain < 0.0 || $maxGain < $minGain) {
            throw new InvalidArgumentException('BubbleGenerator: need 0 <= minGain <= maxGain');
        }
        if ($decayDb < 0.0) {
            throw new InvalidArgumentException("BubbleGenerator: decayDb must be >= 0, got {$decayDb}");
        }
    }

    /**
     * Render $seconds of bubbles.
     *
     * $density is bubbles per second – a constant, or a Closure(float $t): float
     * for a texture that swells or thins out. With $wrap, bubbles that run
     * past the end continue at the start, so the buffer loops seamlessly.
     *
     * @param float|Closure(float): float $density
     * @return list<float>
     */
    public function render(float $seconds, float|Closure $density, int $sampleRate = Pcm::SAMPLE_RATE, bool $wrap = false): array
    {
        $n = max(0, (int) round($seconds * $sampleRate));
        $out = array_fill(0, $n, 0.0);
        if ($n === 0) {
            return $out;
        }

        foreach ($this->schedule($seconds, $density) as $bubble) {
            $this->addBubble($out, $bubble, $sampleRate, $wrap);
        }
        return array_values($out);
    }

    /**
     * The bubbles render() would play: start time, start frequency, length and
     * gain – for tests, visualisation, or driving another synthesiser.
     *
     * @param float|Closure(float): float $density
     * @return list<array{start: float, hz: float, seconds: float, gain: float}>
     */
    public function schedule(float $seconds, float|Closure $density): array
    {
        $rng = new Randomizer(new Xoshiro256StarStar($this->seed));
        $rate = $density instanceof Closure ? $density : static fn (float $t): float => $density;

        // Thinning (Lewis–Shedler) needs an upper bound of the rate: sample it.
        $peak = 0.0;
        $steps = 256;
        for ($i = 0; $i <= $steps; $i++) {
            $peak = max($peak, (float) $rate($seconds * $i / $steps));
        }
        if (!($peak > 0.0) || $seconds <= 0.0) {
            return [];
        }

        $logMin = log($this->minHz);
        $logMax = log($this->maxHz);
        $bubbles = [];
        $t = 0.0;
        while (true) {
            $t += -log(1.0 - $rng->nextFloat()) / $peak;
            if ($t >= $seconds) {
                break;
            }
            // Draw all fields every time so the stream does not depend on
            // which candidates thinning drops.
            $accept = $rng->nextFloat();
            $hz = exp($logMin + ($logMax - $logMin) * $rng->nextFloat());
            $len = $this->minSeconds + ($this->maxSeconds - $this->minSeconds) * $rng->nextFloat();
            $gain = $this->minGain + ($this->maxGain - $this->minGain) * $rng->nextFloat();
            if ($accept * $peak > max(0.0, (float) $rate($t))) {
                continue;
            }
            $bubbles[] = ['start' => $t, 'hz' => $hz, 'seconds' => $len, 'gain' => $gain];
        }
        return $bubbles;
    }

    /**
     * @param array<int, float> $out
     * @param array{start: float, hz: float, seconds: float, gain: float} $bubble
     */
    private function addBubble(array &$out, array $bubble, int $sampleRate, bool $wrap): void
    {
        $n = count($out);
        $start = (int) round($bubble['start'] * $sampleRate);
        $len = max(1, (int) round($bubble['seconds'] * $sampleRate));
        $decayRate = $this->decayDb / 20.0 * M_LN10 / $bubble['seconds'];
        $attack = max(1, (int) round(self::ATTACK_SECONDS * $sampleRate));
        $endFade = max(1, (int) round(self::END_FADE_SECONDS * $sampleRate));
        $twoPiOverSr = 2.0 * M_PI / $sampleRate;
        $phase = 0.0;

        for ($k = 0; $k < $len; $k++) {
            $idx = $start + $k;
            if ($idx >= $n) {
                if (!$wrap) {
                    return;
                }
                $idx %= $n;
            }
            $u = $k / $len;
            $hz = $bubble['hz'] * (1.0 + $this->glide * $u);
            $amp = $bubble['gain'] * exp(-$decayRate * $k / $sampleRate);
            if ($k < $attack) {
                $amp *= $k / $attack;
            }
            $left = $len - 1 - $k;
            if ($left < $endFade) {
                $amp *= $left / $endFade;
            }
            $out[$idx] += $amp * sin($phase);
            $phase += $twoPiOverSr * $hz;
        }
    }
}
