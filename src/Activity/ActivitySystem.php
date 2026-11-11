<?php

declare(strict_types=1);

namespace PHPolygon\Activity;

use PHPolygon\Component\ActivityRegion;
use PHPolygon\Component\Relevance;
use PHPolygon\Component\RelevanceMode;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Visibility\VisibilityService;

/**
 * Computes and applies activity tiers (Phase 4 of the visibility plan,
 * 2026-11-11) for every entity carrying {@see Relevance}. An entity without
 * one is NEVER touched - it stays Visible forever, exactly as every entity
 * did before this phase existed. Opt-in: the engine never auto-registers
 * this system.
 *
 * "Nicht tun: Dormanz nicht gegen Renderkosten" (the plan's own rule) is
 * enforced here, not left to the caller: an entity {@see VisibilityService}
 * currently considers visible is NEVER set Dormant by distance alone (it is
 * capped at Far instead) - Dormant would remove it from VisibilityService's
 * visibleEntities() too (see that class), popping it off screen. The
 * one-frame lag between "was visible last frame" and "is visible this
 * frame" is the same lag {@see \PHPolygon\Motion\MotionView} already lives
 * with for the opposite reason.
 *
 * Tiers are applied to a whole Transform3D subtree together, mirroring
 * {@see World::setEntityDormant()}'s own documented requirement - a lone
 * dormant/far child under an active parent (or vice versa) produces
 * stale/mis-parented transforms.
 */
final class ActivitySystem extends AbstractSystem
{
    /** Overridable in EngineConfig is satisfied by passing a non-default value here - see the class docblock on why this is a constructor parameter, not a new EngineConfig field. */
    public const float DEFAULT_NEAR_RADIUS = 64.0;

    /** Far = view distance x this multiplier - the plan's decision 4. */
    public const float DEFAULT_FAR_RADIUS_MULTIPLIER = 2.0;

    /**
     * Fraction of a radius used as its hysteresis band, so an entity
     * oscillating right at a boundary does not flip tiers every tick - a
     * DESIGN VALUE, not a measured one.
     */
    private const float HYSTERESIS_FRACTION = 0.1;

    /** @var \Closure(): float */
    private readonly \Closure $viewDistanceFn;

    /** Last known camera eye - kept across a frame with no active camera, the same convention {@see \PHPolygon\Motion\MotionView} uses. */
    private ?Vec3 $lastEye = null;

    /**
     * Tier last actually applied, keyed by the Relevance-carrying (subtree
     * root) entity id. World only stores Visible/Far/Dormant (see
     * {@see World::entityTier()}'s own docblock on why) - this is where the
     * Near/Visible distinction and the hysteresis state machine actually
     * live.
     *
     * @var array<int, ActivityTier>
     */
    private array $lastTier = [];

    /**
     * @param ?\Closure(): float $viewDistanceFn Live view-distance reader
     *        (e.g. `fn(): float => $graphics->settings()->viewDistance`) -
     *        null keeps a fixed 200.0 m (GraphicsSettings' own default).
     */
    public function __construct(
        private readonly ActivityService $activity,
        ?\Closure $viewDistanceFn = null,
        private readonly float $nearRadius = self::DEFAULT_NEAR_RADIUS,
        private readonly float $farRadiusMultiplier = self::DEFAULT_FAR_RADIUS_MULTIPLIER,
    ) {
        $this->viewDistanceFn = $viewDistanceFn ?? static fn(): float => 200.0;
    }

    public function update(World $world, float $dt): void
    {
        $frameEye = VisibilityService::forWorld($world)->frame()?->cameraPosition;
        if ($frameEye !== null) {
            $this->lastEye = $frameEye;
        }
        if ($this->lastEye === null) {
            return; // never seen a camera - nothing to measure distance from; every tier stays untouched
        }
        $eye = $this->lastEye;
        $farRadius = ($this->viewDistanceFn)() * $this->farRadiusMultiplier;

        foreach ($world->query(Relevance::class, Transform3D::class) as $entity) {
            $relevance = $entity->get(Relevance::class);
            if ($relevance->mode === RelevanceMode::Always) {
                continue;
            }

            $region = $world->tryGetComponent($entity->id, ActivityRegion::class);
            if ($region instanceof ActivityRegion && $this->activity->isForcedAwake($region->tag)) {
                $this->applyTier($world, $entity->id, ActivityTier::Near);
                continue;
            }

            $pos = $entity->get(Transform3D::class)->getWorldPosition();
            $wakeMargin = $region instanceof ActivityRegion ? max(0.0, $region->wakeRadius) : 0.0;
            $dx = $pos->x - $eye->x;
            $dy = $pos->y - $eye->y;
            $dz = $pos->z - $eye->z;
            $dist = max(0.0, sqrt($dx * $dx + $dy * $dy + $dz * $dz) - $wakeMargin);

            $nearRadius = $relevance->radius > 0.0 ? $relevance->radius : $this->nearRadius;
            $previous = $this->lastTier[$entity->id] ?? ActivityTier::Near;
            $tier = self::tierForDistance($dist, $nearRadius, $farRadius, $previous);

            if ($relevance->mode === RelevanceMode::Near && $tier === ActivityTier::Dormant) {
                $tier = ActivityTier::Far; // this mode never sleeps fully
            }
            if ($tier === ActivityTier::Dormant && VisibilityService::forWorld($world)->isVisible($entity->id)) {
                // "Dormanz nicht gegen Renderkosten" - see the class docblock.
                $tier = ActivityTier::Far;
            }

            $this->applyTier($world, $entity->id, $tier);
        }
    }

    /**
     * Near/Far/Dormant with a hysteresis band at each boundary: which
     * threshold variant applies depends on $previous, so an entity must
     * cross a boundary by the FULL band before flipping, in either
     * direction (never oscillates at the line).
     */
    private static function tierForDistance(float $dist, float $nearRadius, float $farRadius, ActivityTier $previous): ActivityTier
    {
        $nearBand = $nearRadius * self::HYSTERESIS_FRACTION;
        $farBand = $farRadius * self::HYSTERESIS_FRACTION;

        $wasAtLeastFar = $previous === ActivityTier::Far || $previous === ActivityTier::Dormant;
        $pastNear = $wasAtLeastFar ? ($dist > $nearRadius - $nearBand) : ($dist > $nearRadius + $nearBand);
        if (!$pastNear) {
            return ActivityTier::Near;
        }

        $wasDormant = $previous === ActivityTier::Dormant;
        $pastFar = $wasDormant ? ($dist > $farRadius - $farBand) : ($dist > $farRadius + $farBand);

        return $pastFar ? ActivityTier::Dormant : ActivityTier::Far;
    }

    private function applyTier(World $world, int $rootId, ActivityTier $tier): void
    {
        $previous = $this->lastTier[$rootId] ?? ActivityTier::Near;
        if ($previous === $tier) {
            return; // idempotent - no churn, no observer spam
        }
        $this->lastTier[$rootId] = $tier;
        $this->applyTierToSubtree($world, $rootId, $tier);
        $this->activity->notify($rootId, $previous, $tier);
        if ($tier === ActivityTier::Dormant || $previous === ActivityTier::Dormant) {
            // World::setEntityDormant() fires no event and does not bump
            // SpatialIndex::version() - see VisibilityService::invalidate().
            VisibilityService::forWorld($world)->invalidate();
        }
    }

    private function applyTierToSubtree(World $world, int $entityId, ActivityTier $tier): void
    {
        $worldTier = match ($tier) {
            ActivityTier::Dormant => ActivityTier::Dormant,
            ActivityTier::Far => ActivityTier::Far,
            ActivityTier::Near, ActivityTier::Visible => ActivityTier::Visible,
        };
        $world->setEntityTier($entityId, $worldTier);

        $transform = $world->tryGetComponent($entityId, Transform3D::class);
        if (!$transform instanceof Transform3D) {
            return;
        }
        foreach ($transform->childEntityIds as $childId) {
            if ($world->isAlive($childId)) {
                $this->applyTierToSubtree($world, $childId, $tier);
            }
        }
    }
}
