<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\BatchBounds;
use PHPolygon\Rendering\CellInstanceBuffers;
use PHPolygon\Rendering\Command\DrawMeshInstanced;

class CellInstanceBuffersTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
        BatchBounds::clearCache();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        BatchBounds::clearCache();
    }

    /** @return string `$n` translation matrices along +x, spaced 2 apart from $x0 */
    private static function row(int $n, float $x0 = 0.0, float $y = 0.0, float $scale = 1.0): string
    {
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $out .= pack('g16', $scale, 0, 0, 0, 0, $scale, 0, 0, 0, 0, $scale, 0, $x0 + $i * 2.0, $y, 0.0, 1.0);
        }

        return $out;
    }

    private static function at(string $packed, int $i): array
    {
        return array_values(unpack('g16', substr($packed, $i * 64, 64)));
    }

    public function testSetEmitsOnePackedCommandWithKeyCountAndBounds(): void
    {
        $b = new CellInstanceBuffers();
        $b->set('c1', 'box', 'mat', self::row(10));

        $cmds = $b->commands();

        $this->assertCount(1, $cmds);
        $cmd = $cmds[0];
        $this->assertSame('box', $cmd->meshId);
        $this->assertSame('mat', $cmd->materialId);
        $this->assertSame(10, $cmd->instanceCount);
        $this->assertSame(640, strlen($cmd->packedMatrices));
        $this->assertSame('c1|box|mat', $cmd->staticKey);
        $this->assertFalse($cmd->isStatic, 'a static flag would leave per-cell cache entries behind in the culler and the ray-traced scene key');
        $this->assertNotNull($cmd->bounds);
        $this->assertSame(10, $b->instanceCount());
        $this->assertSame(640, $b->byteSize());
    }

    public function testBoundsEncloseEveryInstanceSphere(): void
    {
        $b = new CellInstanceBuffers();
        $b->set('c1', 'box', 'mat', self::row(20, -10.0, 3.0, 2.0));
        $bounds = $b->commands()[0]->bounds;
        $this->assertNotNull($bounds);

        $radius = BatchBounds::meshRadius('box');
        $this->assertNotNull($radius);
        for ($i = 0; $i < 20; $i++) {
            $m = self::at($b->packed('c1', 'box', 'mat') ?? '', $i);
            $d = sqrt(($m[12] - $bounds->x) ** 2 + ($m[13] - $bounds->y) ** 2 + ($m[14] - $bounds->z) ** 2);
            $this->assertLessThanOrEqual($bounds->radius + 1e-6, $d + $radius * 2.0, "instance {$i} sticks out");
        }
        // Never looser than the sphere around the box of the instance spheres that the renderer derives itself.
        $exact = BatchBounds::of($b->commands()[0]);
        $this->assertNotNull($exact);
        $this->assertLessThanOrEqual($exact->radius + 1e-6, $bounds->radius);
    }

    public function testWorkerMeasuredExtentsAreTakenAsGiven(): void
    {
        $packed = self::row(4);
        $extents = BatchBounds::extentsOfPacked($packed, 4);
        $this->assertNotNull($extents);
        $b = new CellInstanceBuffers();
        $b->set('c', 'box', 'mat', $packed, $extents);

        $this->assertEqualsWithDelta(3.0, $b->commands()[0]->bounds?->x, 1e-6, 'centre of the translations 0..6');
    }

    public function testBoundsAreNullWhenTheMeshIsNotRegistered(): void
    {
        $b = new CellInstanceBuffers();
        $b->set('c', 'unknown', 'mat', self::row(2));

        $this->assertNull($b->commands()[0]->bounds, 'the renderer derives (or draws) it; a guessed radius could cull wrongly');
    }

    public function testAppendReplaceRemoveKeepAFlatPackedBuffer(): void
    {
        $b = new CellInstanceBuffers();
        $this->assertSame(0, $b->append('c', 'box', 'mat', Mat4::translation(1.0, 0.0, 0.0)));
        $this->assertSame(1, $b->append('c', 'box', 'mat', Mat4::translation(2.0, 0.0, 0.0)));
        $this->assertSame(2, $b->append('c', 'box', 'mat', Mat4::translation(3.0, 0.0, 0.0)));

        $b->replaceAt('c', 'box', 'mat', 1, Mat4::translation(20.0, 0.0, 0.0));
        $this->assertSame(20.0, self::at($b->packed('c', 'box', 'mat') ?? '', 1)[12]);

        // Remove the first: the last (index 2) moves into slot 0.
        $this->assertSame(2, $b->removeAt('c', 'box', 'mat', 0));
        $packed = $b->packed('c', 'box', 'mat') ?? '';
        $this->assertSame(128, strlen($packed));
        $this->assertSame(3.0, self::at($packed, 0)[12]);
        $this->assertSame(20.0, self::at($packed, 1)[12]);

        // Removing the last moves nothing.
        $this->assertNull($b->removeAt('c', 'box', 'mat', 1));
        $this->assertSame(1, $b->instanceCount('c'));
        $this->assertSame(64, $b->byteSize());
    }

    public function testEditsOutsideTheBufferThrow(): void
    {
        $b = new CellInstanceBuffers();
        $b->set('c', 'box', 'mat', self::row(2));
        foreach ([fn () => $b->removeAt('c', 'box', 'mat', 2), fn () => $b->replaceAt('c', 'box', 'mat', -1, [0.0]), fn () => $b->removeAt('x', 'box', 'mat', 0)] as $call) {
            try {
                $call();
                $this->fail('expected an exception');
            } catch (\OutOfRangeException | \InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSetRejectsRaggedBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CellInstanceBuffers())->set('c', 'box', 'mat', str_repeat("\0", 65));
    }

    public function testSetReplacesAndKeepsCountsExact(): void
    {
        $b = new CellInstanceBuffers();
        $b->set('c', 'box', 'mat', self::row(5));
        $b->set('c', 'box', 'mat', self::row(3));
        $b->set('d', 'box', 'mat', self::row(4));

        $this->assertSame(7, $b->instanceCount());
        $this->assertSame(448, $b->byteSize());
        $this->assertSame(4, $b->removeCell('d'));
        $this->assertSame(3, $b->instanceCount());
        $this->assertFalse($b->hasCell('d'));
    }

    public function testHardCapThrowsAndFitsAnswersFirst(): void
    {
        $b = new CellInstanceBuffers(maxInstances: 10);
        $b->set('c', 'box', 'mat', self::row(8));

        $this->assertTrue($b->fits(2));
        $this->assertFalse($b->fits(3));
        try {
            $b->set('d', 'box', 'mat', self::row(3));
            $this->fail('expected OverflowException');
        } catch (\OverflowException) {
            $this->assertFalse($b->hasCell('d'));
        }
        // Replacing a buffer by a smaller or equal one is never an overflow.
        $b->set('c', 'box', 'mat', self::row(10));
        $this->assertSame(10, $b->instanceCount());
        $this->expectException(\OverflowException::class);
        $b->append('c', 'box', 'mat', [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1]);
    }

    public function testUploadBudgetAdmitsNearestFirstAndDefersTheRest(): void
    {
        $b = new CellInstanceBuffers();
        foreach (['a', 'b', 'c'] as $cell) {
            $b->set($cell, 'box', 'mat', self::row(100)); // 6400 bytes each
        }

        $f1 = $b->commands(['b', 'a', 'c'], 7000);
        $this->assertSame(['b|box|mat'], array_map(static fn (DrawMeshInstanced $c): ?string => $c->staticKey, $f1));
        $this->assertSame(6400, $b->lastFrame()['admittedBytes']);
        $this->assertSame(2, $b->lastFrame()['deferredBuffers']);
        $this->assertSame(12800, $b->pendingBytes());

        $f2 = $b->commands(['b', 'a', 'c'], 7000);
        $this->assertSame(['b|box|mat', 'a|box|mat'], array_map(static fn (DrawMeshInstanced $c): ?string => $c->staticKey, $f2));

        $f3 = $b->commands(['b', 'a', 'c'], 7000);
        $this->assertCount(3, $f3);
        $this->assertSame(0, $b->pendingBytes());
        $this->assertCount(3, $b->commands(['b', 'a', 'c'], 0), 'nothing changed: nothing to admit, nothing deferred');
        $this->assertSame(0, $b->lastFrame()['deferredBuffers']);
    }

    public function testBufferLargerThanTheBudgetIsStillAdmittedWhenItIsFirst(): void
    {
        $b = new CellInstanceBuffers();
        $b->set('big', 'box', 'mat', self::row(1000)); // 64000 bytes

        $this->assertCount(1, $b->commands(null, 1000));
        $this->assertSame(64000, $b->lastFrame()['admittedBytes']);
    }

    public function testDeferredBufferKeepsDrawingItsLastAdmittedState(): void
    {
        $b = new CellInstanceBuffers();
        $b->set('x', 'box', 'mat', self::row(3));
        $b->set('y', 'box', 'mat', self::row(50));
        $b->commands(['x', 'y']); // both admitted

        $b->append('x', 'box', 'mat', Mat4::identity());   // 64 bytes pending
        $b->set('y', 'box', 'mat', self::row(60));          // 3840 bytes pending, nearer
        $cmds = $b->commands(['y', 'x'], 3840);

        $byKey = [];
        foreach ($cmds as $c) {
            $byKey[(string) $c->staticKey] = $c;
        }
        $this->assertSame(60, $byKey['y|box|mat']->instanceCount);
        $this->assertSame(3, $byKey['x|box|mat']->instanceCount, 'the append has not been admitted yet');
        $this->assertSame(192, strlen($byKey['x|box|mat']->packedMatrices));

        $cmds = $b->commands(['y', 'x'], 3840);
        $this->assertSame(4, $cmds[1]->instanceCount);
    }

    public function testBufferNeverAdmittedIsNotDrawn(): void
    {
        $b = new CellInstanceBuffers();
        $b->set('a', 'box', 'mat', self::row(100));
        $b->set('b', 'box', 'mat', self::row(100));

        $this->assertCount(1, $b->commands(['a', 'b'], 100));
    }

    public function testOnlyTheRequestedCellsAreDrawn(): void
    {
        $b = new CellInstanceBuffers();
        $b->set('a', 'box', 'mat', self::row(2));
        $b->set('b', 'box', 'mat', self::row(2));

        $this->assertCount(1, $b->commands(['b']));
        $this->assertCount(0, $b->commands(['missing']));
        $this->assertCount(2, $b->commands());
    }

    public function testSinkReceivesExactlyTheChangedRanges(): void
    {
        $sink = new ArrayInstanceBufferSink();
        $b = new CellInstanceBuffers(sink: $sink);
        $b->set('c', 'box', 'mat', self::row(10));
        $b->commands();
        $this->assertSame([['key' => 'c|box|mat', 'offset' => 0, 'length' => 640, 'capacity' => 640]], $sink->writes);

        $sink->writes = [];
        $b->append('c', 'box', 'mat', Mat4::translation(99.0, 0.0, 0.0));
        $cmds = $b->commands();
        $this->assertSame([['key' => 'c|box|mat', 'offset' => 640, 'length' => 64, 'capacity' => 704]], $sink->writes);
        $this->assertSame(11, $cmds[0]->instanceCount);

        $sink->writes = [];
        $b->replaceAt('c', 'box', 'mat', 3, Mat4::translation(-5.0, 0.0, 0.0));
        $b->commands();
        $this->assertSame([['key' => 'c|box|mat', 'offset' => 192, 'length' => 64, 'capacity' => 704]], $sink->writes);

        $sink->writes = [];
        $b->removeAt('c', 'box', 'mat', 0); // the last (99) moves to slot 0
        $cmds = $b->commands();
        $this->assertSame([['key' => 'c|box|mat', 'offset' => 0, 'length' => 64, 'capacity' => 704]], $sink->writes);
        $this->assertSame(10, $cmds[0]->instanceCount);
        $this->assertSame(99.0, self::at($cmds[0]->packedMatrices, 0)[12]);

        // The sink's copy equals the live buffer for the drawn count.
        $this->assertSame($b->packed('c', 'box', 'mat'), substr($sink->buffers['c|box|mat'], 0, 640));
    }

    public function testRemovingTheLastInstanceCostsNoBytesAndShrinksTheDrawCount(): void
    {
        $sink = new ArrayInstanceBufferSink();
        $b = new CellInstanceBuffers(sink: $sink);
        $b->set('c', 'box', 'mat', self::row(5));
        $b->commands();
        $sink->writes = [];

        $b->removeAt('c', 'box', 'mat', 4);
        $cmds = $b->commands(null, 0);

        $this->assertSame([], $sink->writes);
        $this->assertSame(4, $cmds[0]->instanceCount);
    }

    public function testSinkBudgetChargesRangesNotWholeBuffers(): void
    {
        $sink = new ArrayInstanceBufferSink();
        $b = new CellInstanceBuffers(sink: $sink);
        for ($i = 0; $i < 20; $i++) {
            $b->set("c{$i}", 'box', 'mat', self::row(1000)); // 64 kB each
        }
        $b->commands();
        $sink->writes = [];
        for ($i = 0; $i < 20; $i++) {
            $b->append("c{$i}", 'box', 'mat', Mat4::identity()); // 64 bytes each
        }

        $b->commands(null, 640); // ten appends fit

        $this->assertSame(640, $sink->writtenBytes());
        $this->assertSame(10, $b->lastFrame()['admittedBuffers']);
        $this->assertSame(640, $b->pendingBytes());
    }

    public function testRemoveCellReleasesSinkBuffers(): void
    {
        $sink = new ArrayInstanceBufferSink();
        $b = new CellInstanceBuffers(sink: $sink);
        $b->set('c', 'box', 'mat', self::row(2));
        $b->set('c', 'box', 'other', self::row(2));
        $b->commands();

        $b->removeCell('c');

        $this->assertEqualsCanonicalizing(['c|box|mat', 'c|box|other'], $sink->released);
        $this->assertSame([], $sink->buffers);
        $this->assertSame([], $b->commands());
    }

    public function testBoundsGrowWithAppendedInstances(): void
    {
        $b = new CellInstanceBuffers();
        $b->set('c', 'box', 'mat', self::row(2));
        $before = $b->commands()[0]->bounds;
        $b->append('c', 'box', 'mat', Mat4::translation(500.0, 0.0, 0.0));
        $after = $b->commands()[0]->bounds;

        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertGreaterThan($before->radius + 200.0, $after->radius);
    }

    public function testExtentsOfPackedMatchesAFullWalkAcrossChunks(): void
    {
        $n = 5000; // more than two 2048-instance chunks
        $packed = self::row($n, 0.0, 1.0, 1.5);

        $e = BatchBounds::extentsOfPacked($packed, $n);

        $this->assertNotNull($e);
        $this->assertSame(0.0, $e[0]);
        $this->assertSame(1.0, $e[1]);
        $this->assertSame(($n - 1) * 2.0, $e[3]);
        $this->assertEqualsWithDelta(1.5, $e[6], 1e-6);
        $this->assertNull(BatchBounds::extentsOfPacked('', 0));
    }
}
