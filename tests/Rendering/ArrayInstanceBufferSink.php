<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\BatchBounds;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\InstanceBufferSink;

/** In-memory {@see InstanceBufferSink} that logs every range written, to check what an upload budget really charged. */
final class ArrayInstanceBufferSink implements InstanceBufferSink
{
    /** @var array<string, string> */
    public array $buffers = [];

    /** @var list<array{key: string, offset: int, length: int, capacity: int}> */
    public array $writes = [];

    /** @var list<string> */
    public array $released = [];

    public function write(string $key, int $byteOffset, string $bytes, int $capacityBytes): void
    {
        $buf = $this->buffers[$key] ?? '';
        if (strlen($buf) < $capacityBytes) {
            $buf = str_pad($buf, $capacityBytes, "\0");
        }
        $this->buffers[$key] = substr_replace($buf, $bytes, $byteOffset, strlen($bytes));
        $this->writes[] = ['key' => $key, 'offset' => $byteOffset, 'length' => strlen($bytes), 'capacity' => $capacityBytes];
    }

    public function release(string $key): void
    {
        unset($this->buffers[$key]);
        $this->released[] = $key;
    }

    public function drawCommand(string $key, string $meshId, string $materialId, int $count, ?BatchBounds $bounds): ?DrawMeshInstanced
    {
        return DrawMeshInstanced::packed($meshId, $materialId, substr($this->buffers[$key] ?? '', 0, $count * 64), $count, true, $bounds);
    }

    public function writtenBytes(): int
    {
        return array_sum(array_column($this->writes, 'length'));
    }
}
