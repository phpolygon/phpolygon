<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPUnit\Framework\TestCase;

/**
 * NullRenderer3D validates packed-mode instanced draws: a compute readback that
 * produced the wrong byte count is tallied (so headless tests can catch it),
 * while the command list stays fully inspectable (render is otherwise a no-op).
 */
class NullRenderer3DPackedTest extends TestCase
{
    public function testWellFormedPackedDrawIsNotFlagged(): void
    {
        $bytes = pack('f*', ...array_fill(0, 3 * 16, 0.0)); // 3 instances, exact
        $cmd = DrawMeshInstanced::packed('quad', 'mat', $bytes, 3);

        $renderer = new NullRenderer3D();
        $list = new RenderCommandList();
        $list->add($cmd);
        $renderer->render($list);

        $this->assertSame(0, $renderer->getMalformedPackedDrawCount());
    }

    public function testShortPackedBufferIsFlagged(): void
    {
        // Claims 4 instances (256 bytes) but only carries 3 instances of bytes.
        $bytes = pack('f*', ...array_fill(0, 3 * 16, 0.0));
        $cmd = DrawMeshInstanced::packed('quad', 'mat', $bytes, 4);

        $renderer = new NullRenderer3D();
        $list = new RenderCommandList();
        $list->add($cmd);
        $renderer->render($list);

        $this->assertSame(1, $renderer->getMalformedPackedDrawCount());
    }

    public function testCommandListStaysInspectableAfterValidation(): void
    {
        $bytes = pack('f*', ...array_fill(0, 2 * 16, 0.0));
        $cmd = DrawMeshInstanced::packed('quad', 'mat', $bytes, 2);

        $renderer = new NullRenderer3D();
        $list = new RenderCommandList();
        $list->add($cmd);
        $renderer->render($list);

        $snapshot = $renderer->getLastCommandList();
        $this->assertNotNull($snapshot);
        $cmds = array_values(array_filter(
            iterator_to_array($snapshot->getCommands()),
            fn ($c) => $c instanceof DrawMeshInstanced,
        ));
        $this->assertCount(1, $cmds);
        $this->assertSame($bytes, $cmds[0]->packedMatrices);
    }

    public function testFlatAndMat4DrawsAreNeverFlagged(): void
    {
        $renderer = new NullRenderer3D();
        $list = new RenderCommandList();
        $list->add(DrawMeshInstanced::flat('quad', 'mat', array_fill(0, 16, 0.0), 1));
        $list->add(new DrawMeshInstanced('quad', 'mat', []));
        $renderer->render($list);

        $this->assertSame(0, $renderer->getMalformedPackedDrawCount());
    }
}
