<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

/**
 * Per-frame state of the grass blades (materials with Material::$grass), pushed
 * by {@see \PHPolygon\System\GrassSystem}:
 *
 * - the distance bands: full density up to `$fullRange`, tufts thinning out and
 *   shrinking toward `$farHeight` up to `$fadeRange`, nothing beyond;
 * - up to {@see MAX_BENDERS} benders (players, characters) the blades bend away
 *   from: `[x, y, z, radius]` each, `$pushStrength` 0..1 how far they lay down.
 *
 * Without a SetGrass the renderer keeps the last state (initially 25 / 60 m,
 * no benders).
 */
readonly class SetGrass
{
    public const int MAX_BENDERS = 4;

    /**
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $benders
     */
    public function __construct(
        public float $fullRange = 25.0,
        public float $fadeRange = 60.0,
        public float $farHeight = 0.55,
        public array $benders = [],
        public float $pushStrength = 0.8,
    ) {}

    /**
     * The four `u_grass_push` slots (unused ones have radius 0).
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    public function benderSlots(): array
    {
        $out = [];
        for ($i = 0; $i < self::MAX_BENDERS; $i++) {
            $b = $this->benders[$i] ?? null;
            $out[] = $b === null ? [0.0, -10000.0, 0.0, 0.0] : [$b[0], $b[1], $b[2], max(0.0, $b[3])];
        }
        return $out;
    }

    /**
     * `u_grass_range`: full range, fade range, far height, push strength.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function rangeUniform(): array
    {
        $full = max(0.0, $this->fullRange);
        return [$full, max($full + 0.01, $this->fadeRange), max(0.0, min(1.0, $this->farHeight)), max(0.0, min(1.0, $this->pushStrength))];
    }
}
