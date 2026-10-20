<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

use InvalidArgumentException;

/**
 * Comb filter on a circular delay line, stateful across calls.
 *
 * - Feedback (default): y[n] = x[n] + feedback · lp(y[n − D]) – a decaying
 *   train of echoes every D samples, resonances at multiples of sr / D. With
 *   $damping > 0 a one-pole low-pass sits in the loop (Freeverb's "lowpass
 *   feedback comb"), so highs die faster than lows, as in a real room.
 * - Feed-forward: y[n] = x[n] + feedback · x[n − D] – one echo, notches.
 *
 * {@see Reverb} uses the feedback form with output = the delayed sample, as
 * Freeverb does ({@see processDelayed()}).
 */
final class CombFilter
{
    /** @var array<int, float> delay line, indexed 0 … delay − 1 */
    private array $buffer;
    private int $index = 0;
    private float $filterStore = 0.0;

    public function __construct(
        public readonly int $delaySamples,
        public readonly float $feedback = 0.5,
        public readonly float $damping = 0.0,
        public readonly bool $feedForward = false,
    ) {
        if ($delaySamples < 1) {
            throw new InvalidArgumentException("CombFilter: delay must be >= 1 sample, got {$delaySamples}");
        }
        if (!$feedForward && abs($feedback) >= 1.0) {
            throw new InvalidArgumentException("CombFilter: |feedback| must be < 1, got {$feedback}");
        }
        if ($damping < 0.0 || $damping >= 1.0) {
            throw new InvalidArgumentException("CombFilter: damping must be in [0, 1), got {$damping}");
        }
        $this->buffer = array_fill(0, $delaySamples, 0.0);
    }

    /** Delay of $seconds at $sampleRate (at least one sample). */
    public static function fromSeconds(float $seconds, float $feedback = 0.5, float $damping = 0.0, int $sampleRate = Pcm::SAMPLE_RATE): self
    {
        return new self(max(1, (int) round($seconds * $sampleRate)), $feedback, $damping);
    }

    /** Feedback that makes the echoes fall by 60 dB within $rt60 seconds. */
    public static function feedbackForDecay(int $delaySamples, float $rt60, int $sampleRate = Pcm::SAMPLE_RATE): float
    {
        if ($rt60 <= 0.0) {
            return 0.0;
        }
        return 10.0 ** (-3.0 * $delaySamples / ($rt60 * $sampleRate));
    }

    public function processSample(float $x): float
    {
        $delayed = $this->buffer[$this->index];
        if ($this->feedForward) {
            $this->buffer[$this->index] = $x;
            $y = $x + $this->feedback * $delayed;
        } else {
            $this->filterStore = $delayed * (1.0 - $this->damping) + $this->filterStore * $this->damping;
            $y = $x + $this->feedback * $this->filterStore;
            $this->buffer[$this->index] = $y;
        }
        if (++$this->index >= $this->delaySamples) {
            $this->index = 0;
        }
        return $y;
    }

    /**
     * Freeverb comb step: returns the delayed sample and writes
     * x + feedback · lp(delayed) back (feedback form only).
     */
    public function processDelayed(float $x): float
    {
        $delayed = $this->buffer[$this->index];
        $this->filterStore = $delayed * (1.0 - $this->damping) + $this->filterStore * $this->damping;
        $this->buffer[$this->index] = $x + $this->feedback * $this->filterStore;
        if (++$this->index >= $this->delaySamples) {
            $this->index = 0;
        }
        return $delayed;
    }

    /**
     * @param list<float> $pcm
     * @return list<float>
     */
    public function process(array $pcm): array
    {
        $out = [];
        foreach ($pcm as $x) {
            $out[] = $this->processSample($x);
        }
        return $out;
    }

    public function reset(): void
    {
        $this->buffer = array_fill(0, $this->delaySamples, 0.0);
        $this->index = 0;
        $this->filterStore = 0.0;
    }
}
