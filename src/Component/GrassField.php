<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Rendering\Color;
use PHPolygon\Vegetation\GrassMask;

/**
 * An area covered with grass blades, drawn by {@see \PHPolygon\System\GrassSystem}
 * near the camera only (the ground material carries the look beyond).
 *
 * The game gives:
 * - the bounds (XZ rectangle) the field may cover,
 * - a ground callable `fn(float $x, float $z): ?float` - the surface height,
 *   null where there is no ground (water, holes): no blade there,
 * - a {@see GrassMask}: where grass grows and where not (paths, buildings,
 *   plazas, water), with soft edges, plus any density callables,
 * - root and tip colour (usually derived from the ground material underneath),
 * - density and height multipliers and a seed.
 *
 * Tufts are generated lazily per cell around the camera, deterministic per
 * seed and position. Change the field through {@see touch()} (or edit the mask,
 * which has its own version) so the system rebuilds its cells.
 */
#[Serializable]
#[Category('Rendering')]
class GrassField extends AbstractComponent
{
    #[Property]
    public float $minX = -50.0;

    #[Property]
    public float $minZ = -50.0;

    #[Property]
    public float $maxX = 50.0;

    #[Property]
    public float $maxZ = 50.0;

    #[Property]
    public int $seed = 1;

    /** Multiplies the quality level's tuft density. */
    #[Property]
    #[Range(0.0, 4.0)]
    public float $density = 1.0;

    /** Multiplies the blade height. */
    #[Property]
    #[Range(0.1, 4.0)]
    public float $height = 1.0;

    /** Random height spread of a tuft (0.3 = 0.7 .. 1.3 of the height). */
    #[Property]
    #[Range(0.0, 0.9)]
    public float $heightJitter = 0.3;

    /** Sink of the tuft root below the ground (hides the cut ends on slopes). */
    #[Property]
    public float $sink = 0.03;

    #[Hidden]
    public Color $baseColor;

    #[Hidden]
    public Color $tipColor;

    #[Hidden]
    public GrassMask $mask;

    /** @var (\Closure(float, float): ?float)|null */
    #[Hidden]
    public ?\Closure $groundAt = null;

    /** Bumped by {@see touch()}; the system drops its cells when it changes. */
    #[Hidden]
    public int $version = 0;

    public function __construct(
        float $minX = -50.0,
        float $minZ = -50.0,
        float $maxX = 50.0,
        float $maxZ = 50.0,
        ?callable $groundAt = null,
        ?GrassMask $mask = null,
        ?Color $baseColor = null,
        ?Color $tipColor = null,
        int $seed = 1,
    ) {
        $this->minX = min($minX, $maxX);
        $this->minZ = min($minZ, $maxZ);
        $this->maxX = max($minX, $maxX);
        $this->maxZ = max($minZ, $maxZ);
        if ($groundAt !== null) {
            /** @var \Closure(float, float): ?float $closure */
            $closure = \Closure::fromCallable($groundAt);
            $this->groundAt = $closure;
        }
        $this->mask = $mask ?? new GrassMask();
        $this->baseColor = $baseColor ?? new Color(0.16, 0.3, 0.07);
        $this->tipColor = $tipColor ?? new Color(0.55, 0.72, 0.26);
        $this->seed = $seed;
    }

    /** @param callable(float, float): ?float $groundAt */
    public function setGround(callable $groundAt): void
    {
        /** @var \Closure(float, float): ?float $closure */
        $closure = \Closure::fromCallable($groundAt);
        $this->groundAt = $closure;
        $this->touch();
    }

    public function setColors(Color $base, Color $tip): void
    {
        $this->baseColor = $base;
        $this->tipColor = $tip;
        $this->touch();
    }

    /** Mark the field changed (bounds, density, height, seed, ground): cells are rebuilt. */
    public function touch(): void
    {
        $this->version++;
    }

    /** The surface height at (x, z), or null (no ground: no grass). Flat y = 0 without a callable. */
    public function groundHeight(float $x, float $z): ?float
    {
        if ($this->groundAt === null) {
            return 0.0;
        }
        return ($this->groundAt)($x, $z);
    }
}
