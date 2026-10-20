<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

use InvalidArgumentException;

/**
 * Schroeder all-pass diffuser on a delay line:
 * v[n] = x[n] + g · v[n − D], y[n] = v[n − D] − g · v[n],
 * i.e. H(z) = (z^−D − g) / (1 − g · z^−D) – flat magnitude, so it smears
 * echoes into a dense tail without colouring the spectrum or adding energy.
 * Stateful across calls.
 */
final class AllpassFilter
{
    /** @var array<int, float> delay line, indexed 0 … delay − 1 */
    private array $buffer;
    private int $index = 0;

    public function __construct(
        public readonly int $delaySamples,
        public readonly float $gain = 0.5,
    ) {
        if ($delaySamples < 1) {
            throw new InvalidArgumentException("AllpassFilter: delay must be >= 1 sample, got {$delaySamples}");
        }
        if (abs($gain) >= 1.0) {
            throw new InvalidArgumentException("AllpassFilter: |gain| must be < 1, got {$gain}");
        }
        $this->buffer = array_fill(0, $delaySamples, 0.0);
    }

    public function processSample(float $x): float
    {
        $delayed = $this->buffer[$this->index];
        $v = $x + $this->gain * $delayed;
        $this->buffer[$this->index] = $v;
        if (++$this->index >= $this->delaySamples) {
            $this->index = 0;
        }
        return $delayed - $this->gain * $v;
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
    }
}
