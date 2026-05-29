<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

/**
 * Idle decoration animation: a constant per-axis spin plus a vertical bob.
 * Covers spinning coins, the floating star, waddling enemies, etc. Driven by
 * {@see \PHPolygon\System\SpinBobSystem}.
 *
 * The bob is `amplitude * sin(elapsed * frequency + phaseOffset)`, or
 * `amplitude * |sin(...)|` when {@see $bobAbsolute} is set (the "hop" used by
 * the enemies). Spin speeds are radians per tick.
 */
#[Serializable]
#[Category('Gameplay')]
class SpinBob extends AbstractComponent
{
    /** Per-axis spin in radians per tick. */
    #[Property(editorHint: 'vec3')]
    public Vec3 $spinSpeed;

    /** Vertical bob amplitude (world units). */
    #[Property]
    public float $bobAmplitude;

    /** Bob angular frequency per tick. */
    #[Property]
    public float $bobFrequency;

    /** Phase offset so neighbouring items don't bob in lockstep. */
    #[Property]
    public float $phaseOffset;

    /** When true, bob uses |sin| (a one-sided hop) instead of sin. */
    #[Property]
    public bool $bobAbsolute;

    /** Captured base Y (the rest height the bob oscillates around). */
    #[Hidden]
    public ?float $baseY = null;

    /** Elapsed ticks, advanced by the system. */
    #[Hidden]
    public float $elapsed = 0.0;

    public function __construct(
        ?Vec3 $spinSpeed = null,
        float $bobAmplitude = 0.0,
        float $bobFrequency = 0.0,
        float $phaseOffset = 0.0,
        bool $bobAbsolute = false,
    ) {
        $this->spinSpeed = $spinSpeed ?? Vec3::zero();
        $this->bobAmplitude = $bobAmplitude;
        $this->bobFrequency = $bobFrequency;
        $this->phaseOffset = $phaseOffset;
        $this->bobAbsolute = $bobAbsolute;
    }
}
