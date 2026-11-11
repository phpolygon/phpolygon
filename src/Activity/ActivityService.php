<?php

declare(strict_types=1);

namespace PHPolygon\Activity;

/**
 * `$engine->activity` - the facade for Phase 4's activity tiers (2026-11-11):
 * tier-change notifications and forced-awake regions. Owns no World/ECS
 * state itself; {@see ActivitySystem} is what actually computes and applies
 * tiers, reading this service's forced-awake set and calling {@see notify()}
 * after every real change.
 *
 * Inert by itself - a game that never constructs an ActivitySystem never
 * sees any behaviour from this class either.
 */
final class ActivityService
{
    /** @var list<callable(int, ActivityTier, ActivityTier): void> entityId, from, to */
    private array $observers = [];

    /** @var array<string, array<string, true>> region tag => reasons currently holding it awake */
    private array $awakeReasons = [];

    /**
     * @param callable(int $entityId, ActivityTier $from, ActivityTier $to): void $observer
     */
    public function addObserver(callable $observer): void
    {
        $this->observers[] = $observer;
    }

    /**
     * Forces every {@see \PHPolygon\Component\ActivityRegion} with this tag
     * to Visible regardless of distance, until every requestAwake() reason
     * is released - reference-counted by $reason so two independent
     * callers both needing a region awake don't fight over when to let it
     * sleep again (the second release() does not drop it out from under
     * the first caller still relying on it).
     */
    public function requestAwake(string $region, string $reason): void
    {
        $this->awakeReasons[$region][$reason] = true;
    }

    /** Drops one reason; the region stays forced awake while any other reason remains held. */
    public function release(string $region, string $reason): void
    {
        unset($this->awakeReasons[$region][$reason]);
        if (($this->awakeReasons[$region] ?? []) === []) {
            unset($this->awakeReasons[$region]);
        }
    }

    public function isForcedAwake(string $region): bool
    {
        return $region !== '' && ($this->awakeReasons[$region] ?? []) !== [];
    }

    /** Called by ActivitySystem once per actual tier change - never for a no-op re-application of the same tier. */
    public function notify(int $entityId, ActivityTier $from, ActivityTier $to): void
    {
        foreach ($this->observers as $observer) {
            $observer($entityId, $from, $to);
        }
    }
}
