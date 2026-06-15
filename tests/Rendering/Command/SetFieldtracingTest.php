<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Command;

use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetFieldtracing;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\Quality\FieldtracingMode;
use PHPolygon\Rendering\RenderCommandList;
use PHPUnit\Framework\TestCase;

class SetFieldtracingTest extends TestCase
{
    public function testDefaults(): void
    {
        $cmd = new SetFieldtracing(FieldtracingMode::SdfOcclusion);
        $this->assertSame(FieldtracingMode::SdfOcclusion, $cmd->mode);
        $this->assertSame(1.0, $cmd->intensity);
        $this->assertSame(1, $cmd->bounces);
        $this->assertSame(1.5, $cmd->aoRadius);
    }

    public function testIsImmutableValueObject(): void
    {
        $ref = new \ReflectionClass(SetFieldtracing::class);
        $this->assertTrue($ref->isReadOnly(), 'render commands must be readonly');
    }

    public function testVolumeCommandIsImmutableAndRecorded(): void
    {
        $ref = new \ReflectionClass(\PHPolygon\Rendering\Command\SetFieldtracingVolume::class);
        $this->assertTrue($ref->isReadOnly());

        $cmd = new \PHPolygon\Rendering\Command\SetFieldtracingVolume(
            data: str_repeat("\x80\x80\x80\xFF", 8),
            width: 2, height: 2, depth: 2,
            origin: new \PHPolygon\Math\Vec3(-1, -1, -1),
            size: new \PHPolygon\Math\Vec3(2, 2, 2),
            range: 4.0, version: 7,
        );
        $r = new NullRenderer3D();
        $list = new RenderCommandList();
        $list->add($cmd);
        $r->render($list);

        $cmds = $r->getLastCommandList()?->ofType(\PHPolygon\Rendering\Command\SetFieldtracingVolume::class) ?? [];
        $this->assertCount(1, $cmds);
        $this->assertSame(7, $cmds[0]->version);
        $this->assertSame(2, $cmds[0]->depth);
    }

    public function testHeadlessRendererRecordsCommandWithoutExecuting(): void
    {
        $r = new NullRenderer3D();
        $list = new RenderCommandList();
        $list->add(new SetFieldtracing(FieldtracingMode::SdfBounce, intensity: 0.8, bounces: 2, aoRadius: 2.0));
        $list->add(new DrawMesh('box', 'stone', Mat4::identity()));

        $r->render($list); // must not throw on the headless/No-Op path

        $snapshot = $r->getLastCommandList();
        $this->assertNotNull($snapshot);
        $cmds = $snapshot->ofType(SetFieldtracing::class);
        $this->assertCount(1, $cmds);
        $this->assertSame(FieldtracingMode::SdfBounce, $cmds[0]->mode);
        $this->assertSame(0.8, $cmds[0]->intensity);
        $this->assertSame(2, $cmds[0]->bounces);
        $this->assertSame(2.0, $cmds[0]->aoRadius);
    }
}
