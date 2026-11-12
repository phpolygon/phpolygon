<?php

declare(strict_types=1);

namespace PHPolygon\Animation\Face;

/**
 * Expression states as morph-weight recipes: a state name ("neutral", "warm", ...) maps to
 * weights of named morph targets ({@see \PHPolygon\Geometry\MorphTargets}) or any other
 * driver channel. The set holds no state names of its own: the game defines them and the
 * recipes. The driver API only produces weights; applying them is the caller's step.
 */
final readonly class ExpressionSet
{
    /** @param array<string, array<string, float>> $states state => (channel => weight) */
    public function __construct(public array $states)
    {
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->states);
    }

    /**
     * The weights of a mix of states: each state's recipe scaled by its amount, summed per
     * channel. An unknown state throws (a typo must not silently give a blank face).
     *
     * @param array<string, float> $mix state => amount (usually 0..1)
     * @return array<string, float>
     */
    public function weights(array $mix): array
    {
        $out = [];
        foreach ($mix as $state => $amount) {
            if (!isset($this->states[$state])) {
                throw new \InvalidArgumentException("ExpressionSet: unknown state '{$state}'");
            }
            foreach ($this->states[$state] as $channel => $w) {
                $out[$channel] = ($out[$channel] ?? 0.0) + $w * $amount;
            }
        }

        return $out;
    }

    /**
     * Cross-fade between two states: from at t = 0, to at t = 1.
     *
     * @return array<string, float>
     */
    public function blend(string $from, string $to, float $t): array
    {
        $t = max(0.0, min(1.0, $t));

        return $this->weights([$from => 1.0 - $t, $to => $t]);
    }

    /**
     * Channel-wise combination of weight maps: $base with $overlay added, then each
     * channel in $limits clamped to [min, max]. For layering a blink or speech shape over
     * an emotion without exceeding a channel's range.
     *
     * @param array<string, float> $base
     * @param array<string, float> $overlay
     * @param array<string, array{0: float, 1: float}> $limits
     * @return array<string, float>
     */
    public static function layer(array $base, array $overlay, array $limits = []): array
    {
        foreach ($overlay as $channel => $w) {
            $base[$channel] = ($base[$channel] ?? 0.0) + $w;
        }
        foreach ($limits as $channel => [$lo, $hi]) {
            if (isset($base[$channel])) {
                $base[$channel] = max($lo, min($hi, $base[$channel]));
            }
        }

        return $base;
    }
}
