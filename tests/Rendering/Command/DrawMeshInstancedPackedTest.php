<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Command;

use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPUnit\Framework\TestCase;

/**
 * Packed storage mode: a raw f32 byte buffer (the verbatim readback of a GPU
 * compute pass) carried through DrawMeshInstanced without a PHP-array roundtrip.
 */
class DrawMeshInstancedPackedTest extends TestCase
{
    public function testPackedFactorySetsOnlyPackedBuffer(): void
    {
        $bytes = pack('f*', ...array_fill(0, 32, 1.5)); // 2 instances * 16
        $cmd = DrawMeshInstanced::packed('quad', 'mat', $bytes, 2);

        $this->assertSame('quad', $cmd->meshId);
        $this->assertSame('mat', $cmd->materialId);
        $this->assertTrue($cmd->hasPackedMatrices());
        $this->assertFalse($cmd->hasFlatMatrices());
        $this->assertSame([], $cmd->matrices);
        $this->assertSame(2, $cmd->effectiveInstanceCount());
        $this->assertSame($bytes, $cmd->packedMatrices);
    }

    public function testEmptyPackedBufferIsNotPacked(): void
    {
        $cmd = DrawMeshInstanced::packed('quad', 'mat', '', 0);
        $this->assertFalse($cmd->hasPackedMatrices());
    }

    public function testResolvedFlatMatricesRoundTripsThePackedBytes(): void
    {
        // Two distinct instance matrices, column-major.
        $floats = [];
        for ($i = 0; $i < 32; $i++) {
            $floats[] = $i * 0.25 - 3.0;
        }
        $bytes = pack('f*', ...$floats);
        $cmd = DrawMeshInstanced::packed('quad', 'mat', $bytes, 2);

        $resolved = $cmd->flatMatricesResolved();
        $this->assertCount(32, $resolved);
        foreach ($floats as $k => $expected) {
            // f32 round-trip: compare with a float32 tolerance.
            $this->assertEqualsWithDelta($expected, $resolved[$k], 1e-5);
        }
    }

    public function testFlatModeResolvesToItsOwnBufferUnchanged(): void
    {
        $flat = array_fill(0, 16, 0.0);
        $flat[0] = $flat[5] = $flat[10] = $flat[15] = 1.0;
        $cmd = DrawMeshInstanced::flat('quad', 'mat', $flat, 1);

        $this->assertFalse($cmd->hasPackedMatrices());
        $this->assertTrue($cmd->hasFlatMatrices());
        $this->assertSame($flat, $cmd->flatMatricesResolved());
    }

    public function testMat4ModeResolvesToEmpty(): void
    {
        $cmd = new DrawMeshInstanced('quad', 'mat', []);
        $this->assertFalse($cmd->hasPackedMatrices());
        $this->assertFalse($cmd->hasFlatMatrices());
        $this->assertSame([], $cmd->flatMatricesResolved());
    }

    public function testStorageBufferModeCarriesTheHandle(): void
    {
        // The readback-free path: a GPU-resident instance buffer (a vio
        // VioBuffer at runtime; a plain object stands in for the structural
        // test) with an explicit instance count and no CPU-side matrix data.
        $handle = new \stdClass();
        $cmd = DrawMeshInstanced::fromStorageBuffer('quad', 'mat', $handle, 128);

        $this->assertTrue($cmd->hasStorageBuffer());
        $this->assertSame($handle, $cmd->storageBuffer);
        $this->assertSame(128, $cmd->effectiveInstanceCount());
        $this->assertFalse($cmd->hasFlatMatrices());
        $this->assertFalse($cmd->hasPackedMatrices());
        $this->assertSame([], $cmd->matrices);
        // No CPU matrix bytes to resolve — the vertex shader reads the buffer.
        $this->assertSame([], $cmd->flatMatricesResolved());
    }

    public function testOtherModesHaveNoStorageBuffer(): void
    {
        $this->assertFalse((new DrawMeshInstanced('q', 'm', []))->hasStorageBuffer());
        $this->assertFalse(DrawMeshInstanced::flat('q', 'm', array_fill(0, 16, 0.0), 1)->hasStorageBuffer());
        $this->assertFalse(DrawMeshInstanced::packed('q', 'm', str_repeat("\0", 64), 1)->hasStorageBuffer());
    }
}
