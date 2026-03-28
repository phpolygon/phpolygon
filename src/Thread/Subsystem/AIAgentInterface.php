<?php

declare(strict_types=1);

namespace PHPolygon\Thread\Subsystem;

/**
 * Contract for game-specific AI agents.
 *
 * Agents run inside the AI worker thread. All data in/out must be
 * serializable arrays — no ECS objects, no World references.
 */
interface AIAgentInterface
{
    /**
     * High-frequency perception — runs every frame (~60fps).
     * Observes the world snapshot and updates internal awareness state.
     *
     * @param array<string, mixed> $worldSnapshot
     */
    public function perceive(array $worldSnapshot): void;

    /**
     * Low-frequency pathfinding — runs at configured rate (~10-20fps).
     * Updates the navigation path based on current awareness.
     *
     * @param array<string, mixed> $navMesh
     */
    public function updatePath(array $navMesh): void;

    /**
     * Decision-making — runs at configured rate (~10-20fps).
     * FSM transitions, behavior tree ticks, utility evaluation.
     */
    public function think(): void;

    /**
     * Movement interpolation — runs every frame (~60fps).
     * Smoothly moves the agent along its path.
     */
    public function interpolate(float $dt): void;

    /**
     * Export the agent's current state as a serializable array.
     *
     * @return array<string, mixed>
     */
    public function getState(): array;
}
