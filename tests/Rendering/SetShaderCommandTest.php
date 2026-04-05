<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetShader;
use PHPolygon\Rendering\RenderCommandList;

class SetShaderCommandTest extends TestCase
{
    public function testSetShaderWithId(): void
    {
        $cmd = new SetShader('unlit');
        $this->assertSame('unlit', $cmd->shaderId);
    }

    public function testSetShaderWithNull(): void
    {
        $cmd = new SetShader(null);
        $this->assertNull($cmd->shaderId);
    }

    public function testSetShaderInCommandList(): void
    {
        $list = new RenderCommandList();
        $list->add(new SetShader('unlit'));
        $list->add(new DrawMesh('box', 'stone', Mat4::identity()));
        $list->add(new SetShader(null));

        $shaderCmds = $list->ofType(SetShader::class);
        $this->assertCount(2, $shaderCmds);
        $this->assertSame('unlit', $shaderCmds[0]->shaderId);
        $this->assertNull($shaderCmds[1]->shaderId);
    }
}
