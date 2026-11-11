<?php

declare(strict_types=1);

namespace PHPolygon\Activity;

/**
 * A system's declared interest in Far-tier entities (Phase 4 of the
 * visibility plan, 2026-11-11). {@see \PHPolygon\ECS\AbstractSystem::tierPolicy()}
 * defaults to {@see everyTick()}: the system's own update()/render() keeps
 * iterating componentPool()/query() exactly as it did before this phase -
 * Far-tier entities stay in the regular active pool (see {@see ActivityTier})
 * and are processed every tick by any system that does not override
 * tierPolicy(), which is what the plan's "Standard bleibt jeder Tick, alle"
 * requires: introducing tiers must not make an unmodified system silently
 * run less often.
 *
 * $hasFarCore is read only by a Phase 6 driver (not built in this phase)
 * that will decide whether to hand a system's Far-tier entities to
 * {@see \PHPolygon\Simulation\FarSimulationRunner} (fixed 10 Hz,
 * entity-record stepping) instead of ticking them every frame. Phase 4
 * builds the SimulationKernel/Transport substrate and this declaration
 * point only - nothing in the engine consults $hasFarCore yet. Flagged
 * exactly like {@see \PHPolygon\Visibility\VisibilityService::tierAt()}'s
 * Phase-4 stub was flagged when Phase 2 built it ahead of need.
 */
final class TierPolicy
{
    private function __construct(public readonly bool $hasFarCore)
    {
    }

    /** Default: every tick, every tier - activity tiers do not change this system's own loop at all. */
    public static function everyTick(): self
    {
        return new self(hasFarCore: false);
    }

    /** This system offloads its Far-tier entities to a registered SimulationKernel instead of ticking them itself. */
    public static function withFarCore(): self
    {
        return new self(hasFarCore: true);
    }
}
