<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

use InvalidArgumentException;

/**
 * ADSR amplitude envelope rendered to per-sample gains.
 *
 * The gate is held for $gateSeconds: attack 0 → 1, decay 1 → sustain, hold
 * sustain until the gate closes, then release to 0 from wherever the curve
 * is (a gate shorter than attack + decay releases from mid-attack/decay, no
 * jump). $curve 1 = linear segments; > 1 bends them exponentially (fast
 * start, slow tail on decay/release, slow start on attack), as analogue
 * envelopes do.
 */
final class Envelope
{
    public function __construct(
        public readonly float $attack = 0.01,
        public readonly float $decay = 0.1,
        public readonly float $sustain = 0.7,
        public readonly float $release = 0.2,
        public readonly float $curve = 1.0,
    ) {
        if ($attack < 0.0 || $decay < 0.0 || $release < 0.0) {
            throw new InvalidArgumentException('Envelope: attack, decay and release must be >= 0');
        }
        if ($sustain < 0.0 || $sustain > 1.0) {
            throw new InvalidArgumentException("Envelope: sustain must be in [0, 1], got {$sustain}");
        }
        if ($curve < 1.0) {
            throw new InvalidArgumentException("Envelope: curve must be >= 1, got {$curve}");
        }
    }

    /** Attack/release only: sustain at full level, no decay. */
    public static function ar(float $attack, float $release, float $curve = 1.0): self
    {
        return new self($attack, 0.0, 1.0, $release, $curve);
    }

    /** Total length in seconds of a note gated for $gateSeconds. */
    public function length(float $gateSeconds): float
    {
        return max(0.0, $gateSeconds) + $this->release;
    }

    /**
     * Per-sample gains for a note gated for $gateSeconds (gate + release long).
     *
     * @return list<float>
     */
    public function render(float $gateSeconds, int $sampleRate = Pcm::SAMPLE_RATE): array
    {
        $gate = max(0, (int) round(max(0.0, $gateSeconds) * $sampleRate));
        $rel = (int) round($this->release * $sampleRate);
        $out = [];
        $level = 0.0;
        for ($i = 0; $i < $gate; $i++) {
            $level = $this->held($i / $sampleRate);
            $out[] = $level;
        }
        for ($i = 0; $i < $rel; $i++) {
            $out[] = $level * $this->fall(($i + 1) / $rel);
        }
        return $out;
    }

    /**
     * Multiply $pcm by the envelope; the note is gated for $gateSeconds
     * (null = the whole buffer minus the release). Samples past the envelope
     * become 0; the output keeps the input's length.
     *
     * @param list<float> $pcm
     * @return list<float>
     */
    public function apply(array $pcm, ?float $gateSeconds = null, int $sampleRate = Pcm::SAMPLE_RATE): array
    {
        $gate = $gateSeconds ?? max(0.0, count($pcm) / $sampleRate - $this->release);
        $gains = $this->render($gate, $sampleRate);
        $out = [];
        foreach ($pcm as $i => $x) {
            $out[] = $x * ($gains[$i] ?? 0.0);
        }
        return $out;
    }

    /** Level while the gate is open, $t seconds after note-on. */
    private function held(float $t): float
    {
        if ($t < $this->attack) {
            return $this->rise($t / $this->attack);
        }
        $t -= $this->attack;
        if ($t < $this->decay) {
            return $this->sustain + (1.0 - $this->sustain) * $this->fall($t / $this->decay);
        }
        return $this->sustain;
    }

    /** 0 → 1 over u ∈ [0, 1]. */
    private function rise(float $u): float
    {
        return $this->curve === 1.0 ? $u : $u ** $this->curve;
    }

    /** 1 → 0 over u ∈ [0, 1]. */
    private function fall(float $u): float
    {
        return $this->curve === 1.0 ? 1.0 - $u : (1.0 - $u) ** $this->curve;
    }
}
