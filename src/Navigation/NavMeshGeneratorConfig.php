<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

/**
 * Configuration for NavMesh generation.
 *
 * Defaults are tuned for a human-sized agent (1.8m tall, 0.4m radius)
 * matching CharacterController3D defaults.
 */
readonly class NavMeshGeneratorConfig
{
    public function __construct(
        /** Voxel raster cell size in the XZ plane (meters). */
        public float $cellSize = 0.3,

        /** Voxel raster cell height (Y axis, meters). */
        public float $cellHeight = 0.2,

        /** Agent capsule height (meters). */
        public float $agentHeight = 1.8,

        /** Agent capsule radius (meters). */
        public float $agentRadius = 0.4,

        /** Maximum step height the agent can climb (meters). */
        public float $agentMaxClimb = 0.3,

        /** Maximum walkable slope angle (degrees). */
        public float $agentMaxSlope = 45.0,

        /** Minimum region area in cells (regions smaller than this are discarded). */
        public int $regionMinSize = 8,

        /** Maximum edge length in cells before forced splitting. */
        public int $maxEdgeLength = 12,

        /** Merge distance for shared-edge detection (meters). */
        public float $mergeDistance = 0.01,
    ) {}

    /**
     * @return array<string, float|int>
     */
    public function toArray(): array
    {
        return [
            'cellSize' => $this->cellSize,
            'cellHeight' => $this->cellHeight,
            'agentHeight' => $this->agentHeight,
            'agentRadius' => $this->agentRadius,
            'agentMaxClimb' => $this->agentMaxClimb,
            'agentMaxSlope' => $this->agentMaxSlope,
            'regionMinSize' => $this->regionMinSize,
            'maxEdgeLength' => $this->maxEdgeLength,
            'mergeDistance' => $this->mergeDistance,
        ];
    }

    /**
     * @param array<string, float|int> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            cellSize: (float) ($data['cellSize'] ?? 0.3),
            cellHeight: (float) ($data['cellHeight'] ?? 0.2),
            agentHeight: (float) ($data['agentHeight'] ?? 1.8),
            agentRadius: (float) ($data['agentRadius'] ?? 0.4),
            agentMaxClimb: (float) ($data['agentMaxClimb'] ?? 0.3),
            agentMaxSlope: (float) ($data['agentMaxSlope'] ?? 45.0),
            regionMinSize: (int) ($data['regionMinSize'] ?? 8),
            maxEdgeLength: (int) ($data['maxEdgeLength'] ?? 12),
            mergeDistance: (float) ($data['mergeDistance'] ?? 0.01),
        );
    }
}
