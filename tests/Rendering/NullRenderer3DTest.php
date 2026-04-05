<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetShader;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Renderer3DInterface;

class NullRenderer3DTest extends TestCase
{
    public function testImplementsRenderer3DInterface(): void
    {
        $r = new NullRenderer3D();
        $this->assertInstanceOf(Renderer3DInterface::class, $r);
    }

    public function testGetLastCommandListIsNullBeforeFirstRender(): void
    {
        $r = new NullRenderer3D();
        $this->assertNull($r->getLastCommandList());
    }

    public function testRenderStoresCommandList(): void
    {
        $r = new NullRenderer3D();
        $list = new RenderCommandList();
        $list->add(new DrawMesh('box', 'stone', Mat4::identity()));
        $r->render($list);
        $snapshot = $r->getLastCommandList();
        $this->assertNotNull($snapshot);
        $this->assertEquals(1, $snapshot->count());
        $draws = $snapshot->ofType(DrawMesh::class);
        $this->assertCount(1, $draws);
        $this->assertEquals('box', $draws[0]->meshId);
    }

    public function testSetViewportUpdatesWidthHeight(): void
    {
        $r = new NullRenderer3D(800, 600);
        $this->assertEquals(800, $r->getWidth());
        $this->assertEquals(600, $r->getHeight());
        $r->setViewport(0, 0, 1920, 1080);
        $this->assertEquals(1920, $r->getWidth());
        $this->assertEquals(1080, $r->getHeight());
    }

    public function testAllRenderContextMethodsAcceptCallsWithoutError(): void
    {
        $r = new NullRenderer3D();
        $r->beginFrame();
        $r->clear(Color::black());
        $r->endFrame();
        $this->assertTrue(true); // no exception thrown
    }

    public function testSetShaderCommandIsPreservedInSnapshot(): void
    {
        $r = new NullRenderer3D();
        $list = new RenderCommandList();
        $list->add(new SetShader('unlit'));
        $list->add(new DrawMesh('box', 'stone', Mat4::identity()));
        $list->add(new SetShader(null));

        $r->render($list);
        $snapshot = $r->getLastCommandList();

        $shaderCmds = $snapshot->ofType(SetShader::class);
        $this->assertCount(2, $shaderCmds);
        $this->assertSame('unlit', $shaderCmds[0]->shaderId);
        $this->assertNull($shaderCmds[1]->shaderId);
    }
}
