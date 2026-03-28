<?php

declare(strict_types=1);

namespace PHPolygon\Thread\Subsystem;

use PHPolygon\ECS\World;
use PHPolygon\Thread\SubsystemInterface;

/**
 * AI subsystem with internal rate-splitting.
 *
 * The engine provides the thread lifecycle and rate infrastructure.
 * Game-specific AI (behavior trees, FSMs) implements AIAgentInterface
 * and is registered externally.
 *
 * Internal rates:
 *   - Perception: every frame (~60fps)
 *   - Pathfinding: configurable (~10-20fps)
 *   - Think (decisions): configurable (~10-20fps)
 *   - Movement interpolation: every frame (~60fps)
 */
class AISubsystem implements SubsystemInterface
{
    private AIProcessorConfig $config;

    public function __construct()
    {
        $this->config = new AIProcessorConfig();
    }

    public function setConfig(AIProcessorConfig $config): void
    {
        $this->config = $config;
    }

    public function prepareInput(World $world, float $dt): array
    {
        // Game code overrides this to extract NPC positions, player state, navmesh
        return [
            'dt' => $dt,
            'agents' => [],
            'worldSnapshot' => [],
            'navMesh' => [],
            'pathfindingIntervalNs' => $this->config->pathfindingIntervalNs(),
            'thinkIntervalNs' => $this->config->thinkIntervalNs(),
        ];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
        // Game code overrides this to apply NPC movement targets, state changes
    }

    public static function threadEntry(string $channelPrefix): void
    {
        $in = \parallel\Channel::open("{$channelPrefix}_in");
        $out = \parallel\Channel::open("{$channelPrefix}_out");

        while (true) {
            $input = $in->recv();
            if (!is_array($input)) {
                break;
            }
            /** @var array<string, mixed> $input */
            $out->send(self::compute($input));
        }
    }

    public static function compute(array $input): array
    {
        /** @var float $dt */
        $dt = $input['dt'] ?? 0.016;
        /** @var list<array<string, mixed>> $agents */
        $agents = $input['agents'] ?? [];
        /** @var array<string, mixed> $worldSnapshot */
        $worldSnapshot = $input['worldSnapshot'] ?? [];
        /** @var array<string, mixed> $navMesh */
        $navMesh = $input['navMesh'] ?? [];

        // Rate-splitting is handled by the persistent thread via threadEntry.
        // In compute() (used by NullThreadScheduler), we run all phases once.
        $updatedAgents = [];
        foreach ($agents as $agentState) {
            // Game-specific agents would be reconstructed here from state arrays.
            // Engine only provides the framework — no concrete agents shipped.
            $updatedAgents[] = $agentState;
        }

        return [
            'agents' => $updatedAgents,
        ];
    }
}
