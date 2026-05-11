<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Mat4;

/**
 * Instanced draw command with two equally-valid storage modes:
 *
 *   - **Mat4 mode** (default): pass `Mat4[]` via $matrices. Convenient
 *     for game-code call sites that already build matrices via
 *     `Mat4::trs(...)` and can hand them in directly. Backends call
 *     `$mat->toArray()` per instance to flatten into the GPU buffer.
 *
 *   - **Flat mode**: build the command via {@see flat()} and pass a
 *     pre-flattened `float[]` of length `instanceCount * 16`
 *     (column-major). The renderer skips the per-instance flattening
 *     loop and uploads the buffer directly. ~6x fewer per-particle
 *     allocations - use it for hot paths like particle systems and
 *     dense building districts.
 *
 * Both modes feed the same GPU pipeline. Backends inspect
 * {@see hasFlatMatrices()} once per draw to decide which buffer to
 * read.
 */
readonly class DrawMeshInstanced
{
    /**
     * @param Mat4[]  $matrices       Mat4 mode: one instance per Mat4.
     *                                Empty when {@see hasFlatMatrices()} is true.
     * @param bool    $isStatic       When true, the renderer caches the
     *                                instance buffer on first upload and
     *                                skips re-upload on subsequent frames.
     * @param int     $instanceCount  Number of instances. -1 (default) means
     *                                "use count($matrices)" - the canonical
     *                                value for Mat4 mode. In flat mode this
     *                                is the authoritative count and the flat
     *                                buffer length must equal $instanceCount * 16.
     * @param float[] $flatMatrices   Flat mode: pre-flattened column-major
     *                                float buffer. Empty in Mat4 mode.
     */
    public function __construct(
        public string $meshId,
        public string $materialId,
        public array $matrices,
        public bool $isStatic = false,
        public int $instanceCount = -1,
        public array $flatMatrices = [],
    ) {}

    /**
     * Build a flat-mode command. Use this from hot loops (particles,
     * thousands of instances) where allocating one Mat4 per instance is
     * the dominant cost. The buffer must be column-major and
     * `$instanceCount * 16` floats long.
     *
     * @param float[] $flatMatrices
     */
    public static function flat(
        string $meshId,
        string $materialId,
        array $flatMatrices,
        int $instanceCount,
        bool $isStatic = false,
    ): self {
        return new self(
            meshId: $meshId,
            materialId: $materialId,
            matrices: [],
            isStatic: $isStatic,
            instanceCount: $instanceCount,
            flatMatrices: $flatMatrices,
        );
    }

    public function hasFlatMatrices(): bool
    {
        return $this->flatMatrices !== [];
    }

    /**
     * Effective instance count regardless of storage mode. Backends
     * should prefer this over count($matrices).
     */
    public function effectiveInstanceCount(): int
    {
        if ($this->instanceCount >= 0) {
            return $this->instanceCount;
        }
        return count($this->matrices);
    }
}
