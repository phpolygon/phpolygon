<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\ShaderDefinition;
use PHPolygon\Rendering\ShaderRegistry;

class ShaderRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        ShaderRegistry::clear();
    }

    public function testRegisterAndGet(): void
    {
        $def = new ShaderDefinition('/vert.glsl', '/frag.glsl');
        ShaderRegistry::register('test', $def);

        $this->assertTrue(ShaderRegistry::has('test'));
        $this->assertSame($def, ShaderRegistry::get('test'));
    }

    public function testGetReturnsNullForUnknown(): void
    {
        $this->assertNull(ShaderRegistry::get('nonexistent'));
        $this->assertFalse(ShaderRegistry::has('nonexistent'));
    }

    public function testOverwriteExisting(): void
    {
        $def1 = new ShaderDefinition('/a.vert.glsl', '/a.frag.glsl');
        $def2 = new ShaderDefinition('/b.vert.glsl', '/b.frag.glsl');

        ShaderRegistry::register('shader', $def1);
        ShaderRegistry::register('shader', $def2);

        $this->assertSame($def2, ShaderRegistry::get('shader'));
    }

    public function testClear(): void
    {
        ShaderRegistry::register('a', new ShaderDefinition('/a.vert', '/a.frag'));
        ShaderRegistry::register('b', new ShaderDefinition('/b.vert', '/b.frag'));
        $this->assertCount(2, ShaderRegistry::ids());

        ShaderRegistry::clear();
        $this->assertCount(0, ShaderRegistry::ids());
    }

    public function testIdsReturnsAllRegistered(): void
    {
        ShaderRegistry::register('unlit', new ShaderDefinition('/u.vert', '/u.frag'));
        ShaderRegistry::register('normals', new ShaderDefinition('/n.vert', '/n.frag'));

        $ids = ShaderRegistry::ids();
        $this->assertContains('unlit', $ids);
        $this->assertContains('normals', $ids);
        $this->assertCount(2, $ids);
    }
}
