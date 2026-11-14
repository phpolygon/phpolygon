<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Rendering\Command\DrawMeshInstanced;

/**
 * Where {@see CellInstanceBuffers} puts bytes that must stay resident on the
 * GPU between frames: one growable byte buffer per key, written in ranges, and
 * the draw command that reads it. The engine ships no implementation yet - a
 * php-vio one (`vio_storage_buffer` + `vio_update_buffer` at an offset +
 * {@see DrawMeshInstanced::fromStorageBuffer()}, gated on
 * `VIO_FEATURE_VERTEX_STORAGE`) has to solve in-flight frames reading a buffer
 * that is being written (see the note in GpuParticleBaker) before it is safe.
 * Without a sink {@see CellInstanceBuffers} emits ordinary packed commands.
 */
interface InstanceBufferSink
{
    /**
     * Make bytes `[$byteOffset, $byteOffset + strlen($bytes))` of the buffer for
     * $key equal $bytes, creating it or growing it to at least $capacityBytes.
     */
    public function write(string $key, int $byteOffset, string $bytes, int $capacityBytes): void;

    /** Free the buffer for $key. */
    public function release(string $key): void;

    /**
     * The command that draws the first $count instances of the buffer for
     * $key, or null when the sink cannot draw it (the buffer is skipped this frame).
     */
    public function drawCommand(string $key, string $meshId, string $materialId, int $count, ?BatchBounds $bounds): ?DrawMeshInstanced;
}
