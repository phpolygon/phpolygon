<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\ShaderDefinition;

class ShaderDefinitionTest extends TestCase
{
    public function testPropertiesAreReadonly(): void
    {
        $def = new ShaderDefinition('/path/to/vert.glsl', '/path/to/frag.glsl');

        $this->assertSame('/path/to/vert.glsl', $def->vertexPath);
        $this->assertSame('/path/to/frag.glsl', $def->fragmentPath);
    }
}
