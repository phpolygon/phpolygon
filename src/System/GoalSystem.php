<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Goal;
use PHPolygon\Component\PlatformerController;
use PHPolygon\Component\PlatformerGameState;
use PHPolygon\Component\PlatformerStatus;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;

/**
 * Marks the run won when a {@see PlatformerController} reaches a {@see Goal},
 * awarding its score plus a per-life bonus from the {@see PlatformerGameState}.
 */
class GoalSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        $state = $this->findState($world);
        if ($state !== null && $state->status !== PlatformerStatus::Playing) {
            return;
        }

        $playerPositions = [];
        foreach ($world->query(PlatformerController::class, Transform3D::class) as $entity) {
            // World position keeps the comparison in the same space as the goal.
            $playerPositions[] = $entity->get(Transform3D::class)->getWorldPosition();
        }
        if ($playerPositions === []) {
            return;
        }

        foreach ($world->query(Goal::class, Transform3D::class) as $entity) {
            $goal = $entity->get(Goal::class);
            if ($goal->reached) {
                continue;
            }
            $g = $entity->get(Transform3D::class)->getWorldPosition();
            $r2 = $goal->radius * $goal->radius;
            foreach ($playerPositions as $p) {
                if ($p->distanceSquaredTo($g) < $r2) {
                    $goal->reached = true;
                    if ($state !== null) {
                        $state->status = PlatformerStatus::Won;
                        $state->score += $goal->score + max(0, $state->lives) * $goal->lifeBonus;
                    }
                    break;
                }
            }
        }
    }

    private function findState(World $world): ?PlatformerGameState
    {
        foreach ($world->query(PlatformerGameState::class) as $entity) {
            return $entity->get(PlatformerGameState::class);
        }
        return null;
    }
}
