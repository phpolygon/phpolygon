<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPolygon\Runtime\VioInput;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * VioInput buffers key-press/-release EDGES across render frames (the fixed
 * timestep may run fewer update ticks than renders, so an edge must survive until
 * a tick consumes it — this is also what buffers a jump pressed a hair early).
 * The edges are set by the GLFW callback independently of suppression and are NOT
 * cleared per frame, so a key mashed during a suppress window (boot / menu /
 * intro-skip handoff) used to linger and fire the instant gameplay input resumed —
 * a phantom "held" key nobody was pressing.
 *
 * These tests pin the fix: while suppressed, endFrame() DRAINS the edge buffers so
 * nothing survives the window; while NOT suppressed, the deliberate cross-frame
 * buffering is preserved. VioInput is otherwise FFI-bound (it needs a VioContext
 * for its reader methods), so the buffers are inspected directly via reflection.
 */
final class VioInputTest extends TestCase
{
    /** @return array<int, bool> */
    private static function edge(VioInput $in, string $prop): array
    {
        /** @var array<int, bool> $val */
        $val = (new ReflectionClass($in))->getProperty($prop)->getValue($in);

        return $val;
    }

    private static function setEdge(VioInput $in, string $prop, int $key): void
    {
        (new ReflectionClass($in))->getProperty($prop)->setValue($in, [$key => true]);
    }

    public function testSuppressedFrameDrainsBufferedKeyEdges(): void
    {
        $in = new VioInput();
        // Simulate the GLFW callback having buffered a press + release edge.
        self::setEdge($in, 'keyJustPressed', 69);   // 'E'
        self::setEdge($in, 'keyJustReleased', 69);

        $in->suppress(3, 0.0);
        $in->endFrame();

        self::assertSame([], self::edge($in, 'keyJustPressed'), 'suppressed frame must drain the press edge');
        self::assertSame([], self::edge($in, 'keyJustReleased'), 'suppressed frame must drain the release edge');
    }

    public function testUnsuppressedFramePreservesBufferedPressEdge(): void
    {
        $in = new VioInput();
        self::setEdge($in, 'keyJustPressed', 32); // 'Space' — jump-buffer case

        // Not suppressed: the edge must survive endFrame so a not-yet-consumed
        // press (e.g. jump pressed just before landing) still fires later.
        $in->endFrame();

        self::assertSame([32 => true], self::edge($in, 'keyJustPressed'), 'a live buffered press must survive a normal frame');
    }
}
