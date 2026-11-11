<?php

declare(strict_types=1);

namespace PHPolygon\Activity;

/**
 * Per-entity activity tier (Phase 4 of the visibility plan, 2026-11-11).
 *
 * - Visible: processed and drawn every tick, no restriction - the default
 *   for every entity that never acquires a {@see \PHPolygon\Component\Relevance}
 *   component. Nothing in the engine ever assigns this tier automatically;
 *   it is simply what every entity already has.
 * - Near: processed every tick like Visible (also behind the camera - a
 *   sound source or an NPC about to walk into view must keep simulating),
 *   just not guaranteed to be drawn - drawing is decided independently by
 *   {@see \PHPolygon\Visibility\VisibilityService}, which does not consult
 *   this tier at all.
 * - Far: {@see \PHPolygon\ECS\World::componentPoolFar()} is the ONLY thing
 *   that changes - the entity's components stay in the regular active pool
 *   ({@see \PHPolygon\ECS\World::componentPool()}/query() still see it, so
 *   every system that has not opted in keeps processing it exactly as
 *   before; {@see \PHPolygon\ECS\AbstractSystem::tierPolicy()} declaring a
 *   far core is how a system opts OUT of per-tick processing for this tier
 *   in favour of {@see \PHPolygon\Simulation\FarSimulationRunner}'s fixed
 *   10 Hz, entity-record-based stepping).
 * - Dormant: nothing processes it ({@see \PHPolygon\ECS\World::setEntityDormant()},
 *   unchanged since before this phase). NEVER assigned to an entity
 *   {@see \PHPolygon\Visibility\VisibilityService} currently (or very
 *   recently) considers visible - see ActivitySystem's class docblock on
 *   why ("Dormanz nicht gegen Renderkosten" from the plan's "Nicht tun").
 */
enum ActivityTier
{
    case Visible;
    case Near;
    case Far;
    case Dormant;
}
