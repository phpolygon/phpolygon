<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The storage-instance vertex stage is derived from mesh3d.vert by text. If the
 * source drifts, the derivation must fail loudly instead of silently drawing
 * every GPU-simulated instance at the origin.
 */
final class StorageInstancingVertexSourceTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../resources/shaders/source/vio/mesh3d.vert.glsl';

    public function testInstanceMatrixComesFromTheStorageBuffer(): void
    {
        $src = VioRenderer3D::storageInstancingVertexSource((string) file_get_contents(self::SOURCE));

        self::assertStringStartsWith("#version 450\n", $src);
        self::assertStringContainsString('readonly buffer InstanceStorage { mat4 instanceModels[]; };', $src);
        self::assertStringContainsString('model = instanceModels[gl_InstanceIndex];', $src);
        self::assertStringNotContainsString('a_instance_col', $src, 'no per-instance vertex attributes left to bind');
        // Everything else of the vertex stage is shared: vertex animation, cloth, varyings.
        self::assertStringContainsString('u_vertex_anim', $src);
        self::assertStringContainsString('v_lightSpacePos', $src);
    }

    public function testChangedSourceFailsInsteadOfDrawingAtTheOrigin(): void
    {
        $this->expectException(\RuntimeException::class);
        VioRenderer3D::storageInstancingVertexSource("#version 410 core\nvoid main() {}\n");
    }
}
