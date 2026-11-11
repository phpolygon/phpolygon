<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

/**
 * Groups entities into a named region {@see \PHPolygon\Activity\ActivityService::requestAwake()}/
 * {@see \PHPolygon\Activity\ActivityService::release()} can force awake
 * regardless of distance - a scripted event needing "CodeCity" fully live
 * during a cutscene, the way code-rescue's own `RegionDormancySystem` keeps
 * the destination region awake during a ferry crossing. Attach alongside
 * {@see Relevance} on the same (subtree-root) entities.
 *
 * Phase 4 ships TAG-based grouping only (member entities sharing the same
 * $tag) - the plan also allows grouping by spatial $bounds; that path is
 * NOT implemented yet (ActivitySystem reads $tag only) and is flagged here
 * as an open gap rather than guessed at, the same way Phase 2/3 left
 * tierAt()/OcclusionSource as stubs ahead of their real implementation.
 */
#[Serializable]
#[Category('Activity')]
class ActivityRegion extends AbstractComponent
{
    #[Property]
    public string $tag;

    /**
     * Pre-wake margin (world metres): ActivitySystem treats this entity as
     * though it were $wakeRadius closer to the camera than it really is
     * when deciding its distance tier, so a whole region wakes before the
     * player's view actually reaches it rather than popping in at the exact
     * radius boundary (the plan's "Vorab-Wecken"). 0.0 = no margin.
     */
    #[Property]
    public float $wakeRadius;

    /**
     * Reserved for spatial (not tag) region membership - NOT read by
     * ActivitySystem yet, see the class docblock.
     */
    #[Property(editorHint: 'vec3')]
    public ?Vec3 $boundsMin;

    #[Property(editorHint: 'vec3')]
    public ?Vec3 $boundsMax;

    public function __construct(
        string $tag = '',
        float $wakeRadius = 0.0,
        ?Vec3 $boundsMin = null,
        ?Vec3 $boundsMax = null,
    ) {
        $this->tag = $tag;
        $this->wakeRadius = $wakeRadius;
        $this->boundsMin = $boundsMin;
        $this->boundsMax = $boundsMax;
    }
}
