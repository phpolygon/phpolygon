<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Mat4;

/**
 * Instanced draw command with three equally-valid storage modes:
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
 *   - **Packed mode**: build the command via {@see packed()} and pass a
 *     raw little-endian f32 byte string of length `instanceCount * 16 * 4`
 *     (column-major). This is the mode a GPU compute pass emits: the
 *     matrices are read back from a storage buffer as raw bytes and handed
 *     straight to `vio_draw_instanced()` with no PHP-array roundtrip. On
 *     the vio backend the bytes are forwarded verbatim; other backends
 *     unpack once per draw via {@see flatMatricesResolved()} and fall back
 *     to the flat path.
 *
 * All three modes feed the same GPU pipeline. Backends inspect
 * {@see hasFlatMatrices()} / {@see hasPackedMatrices()} once per draw to
 * decide which buffer to read.
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
     *                                value for Mat4 mode. In flat / packed
     *                                mode this is the authoritative count and
     *                                the buffer length must equal
     *                                $instanceCount * 16 (floats) resp.
     *                                $instanceCount * 16 * 4 (bytes).
     * @param float[] $flatMatrices   Flat mode: pre-flattened column-major
     *                                float buffer. Empty in Mat4 / packed mode.
     * @param string  $packedMatrices Packed mode: raw f32 byte string
     *                                (column-major). Empty in Mat4 / flat mode.
     * @param object|null $storageBuffer Storage-buffer mode: a GPU-resident
     *                                instance-matrix SSBO (a vio VioBuffer,
     *                                typed as object to keep this command
     *                                backend-agnostic) that the vertex shader
     *                                reads via gl_InstanceIndex — the
     *                                readback-free path. Only the vio backend
     *                                (with VIO_FEATURE_VERTEX_STORAGE) honours
     *                                it; it is only ever emitted there. Null in
     *                                every other mode.
     */
    public function __construct(
        public string $meshId,
        public string $materialId,
        public array $matrices,
        public bool $isStatic = false,
        public int $instanceCount = -1,
        public array $flatMatrices = [],
        public string $packedMatrices = '',
        public ?object $storageBuffer = null,
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

    /**
     * Build a packed-mode command. Use this when the instance matrices
     * already exist as a raw f32 byte buffer - typically the verbatim
     * readback of a GPU compute pass (see {@see \PHPolygon\System\GpuParticleBaker}).
     * The bytes are column-major and must be `$instanceCount * 16 * 4` long.
     * On the vio backend they are forwarded to `vio_draw_instanced()`
     * without an unpack/repack roundtrip.
     */
    public static function packed(
        string $meshId,
        string $materialId,
        string $packedMatrices,
        int $instanceCount,
        bool $isStatic = false,
    ): self {
        return new self(
            meshId: $meshId,
            materialId: $materialId,
            matrices: [],
            isStatic: $isStatic,
            instanceCount: $instanceCount,
            flatMatrices: [],
            packedMatrices: $packedMatrices,
        );
    }

    /**
     * Build a storage-buffer-mode command (the readback-free path). The
     * instance matrices are a GPU-resident SSBO written by a compute pass; the
     * vertex shader reads them via gl_InstanceIndex, so nothing crosses the
     * PHP<->GPU bus. Emitted only on the vio backend when
     * VIO_FEATURE_VERTEX_STORAGE is available (see {@see \PHPolygon\System\GpuParticleBaker}).
     *
     * @param object $storageBuffer a vio VioBuffer (typed object here to keep
     *                              the command backend-agnostic)
     */
    public static function fromStorageBuffer(
        string $meshId,
        string $materialId,
        object $storageBuffer,
        int $instanceCount,
        bool $isStatic = false,
    ): self {
        return new self(
            meshId: $meshId,
            materialId: $materialId,
            matrices: [],
            isStatic: $isStatic,
            instanceCount: $instanceCount,
            flatMatrices: [],
            packedMatrices: '',
            storageBuffer: $storageBuffer,
        );
    }

    public function hasFlatMatrices(): bool
    {
        return $this->flatMatrices !== [];
    }

    public function hasPackedMatrices(): bool
    {
        return $this->packedMatrices !== '';
    }

    public function hasStorageBuffer(): bool
    {
        return $this->storageBuffer !== null;
    }

    /**
     * Column-major instance floats regardless of flat/packed storage. In
     * flat mode returns the buffer directly; in packed mode unpacks the raw
     * f32 bytes once. Backends that cannot forward the packed bytes natively
     * (everything except vio) call this to reach their existing flat path.
     * Returns [] when neither buffer is populated (Mat4 mode). The flat buffer
     * is returned by reference (no copy) — this is the OpenGL instanced hot
     * path; only the packed branch allocates, which is fine (packed commands
     * only ever reach the non-vio backends in tests).
     *
     * @return float[]
     */
    public function flatMatricesResolved(): array
    {
        if ($this->packedMatrices !== '') {
            $unpacked = unpack('f*', $this->packedMatrices);
            if ($unpacked === false) {
                return [];
            }
            // 'f*' yields floats; the is_float guard keeps the type honest
            // without a cast (unpack's value type is mixed to the analyser).
            $out = [];
            foreach ($unpacked as $f) {
                if (is_float($f)) {
                    $out[] = $f;
                }
            }
            return $out;
        }
        return $this->flatMatrices;
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
