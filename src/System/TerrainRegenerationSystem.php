<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\ComponentInterface;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPolygon\ECS\World;
use PHPolygon\Terrain\RegenerableTerrain;

/**
 * Generic live regeneration for procedural terrain. Not tied to any game:
 * register it with the concrete component class(es) that implement
 * {@see RegenerableTerrain}, and it rebuilds each instance whenever its
 * editable configuration changes.
 *
 *   $world->addSystem(new TerrainRegenerationSystem([MyTerrainConfig::class]));
 *
 * Change detection hashes each component's serialized `#[Property]` values
 * (via {@see AttributeSerializer}) every frame and compares against the value
 * the terrain was last built from. It therefore works with any editor that
 * edits properties in place — no explicit invalidation call is required.
 *
 * A rebuild is typically expensive (whole terrain meshes), so it is debounced:
 * the configuration must sit unchanged for {@see $debounceSeconds} before a
 * rebuild fires, which collapses a slider drag — mutating the config every
 * frame — into a single rebuild once the value settles.
 *
 * ECS component pools are keyed by concrete class, so the watched classes must
 * be passed explicitly; a query by the {@see RegenerableTerrain} interface
 * would match nothing.
 */
final class TerrainRegenerationSystem extends AbstractSystem
{
    /** @var list<class-string<ComponentInterface>> */
    private array $terrainClasses;

    private float $debounceSeconds;

    private AttributeSerializer $serializer;

    /** @var array<int, string> entityId => signature the terrain was last built from */
    private array $builtSignature = [];

    /** @var array<int, string> entityId => signature currently waiting out the debounce */
    private array $pendingSignature = [];

    /** @var array<int, float> entityId => seconds the pending signature has held steady */
    private array $stableFor = [];

    /**
     * @param list<class-string<ComponentInterface>> $terrainClasses Concrete components implementing RegenerableTerrain.
     */
    public function __construct(
        array $terrainClasses,
        float $debounceSeconds = 0.15,
        ?AttributeSerializer $serializer = null,
    ) {
        $this->terrainClasses = $terrainClasses;
        $this->debounceSeconds = $debounceSeconds;
        $this->serializer = $serializer ?? new AttributeSerializer();
    }

    public function update(World $world, float $dt): void
    {
        foreach ($this->terrainClasses as $class) {
            foreach ($world->query($class) as $entity) {
                $terrain = $entity->get($class);
                if ($terrain instanceof RegenerableTerrain) {
                    $this->tick($world, $entity->id, $terrain, $dt);
                }
            }
        }
    }

    public function onWorldClear(World $world): void
    {
        // Per-entity-id caches would otherwise associate stale signatures with
        // recycled entity ids after the world is cleared.
        $this->builtSignature = [];
        $this->pendingSignature = [];
        $this->stableFor = [];
    }

    private function tick(World $world, int $entityId, RegenerableTerrain $terrain, float $dt): void
    {
        $signature = $this->signature($terrain);

        // First time we see this terrain: adopt its current values as the
        // baseline. The scene already built the meshes from them.
        if (!isset($this->builtSignature[$entityId])) {
            $this->builtSignature[$entityId] = $signature;
            return;
        }

        // Unchanged since the last rebuild — clear any pending debounce.
        if ($signature === $this->builtSignature[$entityId]) {
            unset($this->pendingSignature[$entityId], $this->stableFor[$entityId]);
            return;
        }

        // Value is still moving (e.g. an active slider drag): (re)start the
        // debounce window and wait for it to settle.
        if (($this->pendingSignature[$entityId] ?? null) !== $signature) {
            $this->pendingSignature[$entityId] = $signature;
            $this->stableFor[$entityId] = 0.0;
            return;
        }

        $this->stableFor[$entityId] += $dt;
        if ($this->stableFor[$entityId] < $this->debounceSeconds) {
            return;
        }

        $terrain->rebuild($world, $entityId);
        $this->builtSignature[$entityId] = $signature;
        unset($this->pendingSignature[$entityId], $this->stableFor[$entityId]);
    }

    private function signature(RegenerableTerrain $terrain): string
    {
        return json_encode($this->serializer->toArray($terrain), JSON_THROW_ON_ERROR);
    }
}
