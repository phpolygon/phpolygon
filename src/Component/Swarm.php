<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * A flock of birds or a school of fish (shader plan 7.2), simulated on the GPU by
 * {@see \PHPolygon\System\SwarmSystem} (a compute kernel writes the instance
 * matrices, drawn straight from the buffer) with the same math on the CPU where
 * compute is missing. The swarm circles its entity's position: agents follow a
 * slowly turning loop of `radius`, spread over `height` metres, steer towards
 * their spot on it and are pushed by a curl-noise swirl - so they wheel and
 * scatter instead of flying in formation.
 */
#[Serializable]
#[Category('Rendering')]
class Swarm extends AbstractComponent
{
    public const KIND_BIRDS = 'birds';
    public const KIND_FISH = 'fish';

    public function __construct(
        #[Property] public string $meshId = 'swarm_agent',
        #[Property] public string $materialId = 'swarm_agent',
        #[Property] public int $count = 64,
        #[Property] public float $radius = 25.0,
        #[Property] public float $height = 6.0,
        #[Property] public float $speed = 7.0,
        #[Property] public float $agentScale = 1.0,
        /** birds: wide loops, banking climbs; fish: flat loops, sudden darts. */
        #[Property] public string $kind = self::KIND_BIRDS,
        #[Property] public int $seed = 1,
    ) {
    }
}
