<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\CubemapFaces;
use PHPolygon\Rendering\CubemapRegistry;

class CubemapRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        CubemapRegistry::clear();
    }

    public function testRegisterAndGet(): void
    {
        $faces = new CubemapFaces(
            right: 'sky/right.png', left: 'sky/left.png',
            top: 'sky/top.png', bottom: 'sky/bottom.png',
            front: 'sky/front.png', back: 'sky/back.png',
        );
        CubemapRegistry::register('sky', $faces);

        $this->assertTrue(CubemapRegistry::has('sky'));
        $this->assertSame($faces, CubemapRegistry::get('sky'));
        $this->assertNull(CubemapRegistry::get('nonexistent'));
    }

    public function testCubemapFacesToArray(): void
    {
        $faces = new CubemapFaces('r.png', 'l.png', 't.png', 'b.png', 'f.png', 'bk.png');
        $arr = $faces->toArray();
        $this->assertCount(6, $arr);
        $this->assertEquals('r.png', $arr[0]);
        $this->assertEquals('bk.png', $arr[5]);
    }

    public function testClear(): void
    {
        CubemapRegistry::register('a', new CubemapFaces('', '', '', '', '', ''));
        CubemapRegistry::clear();
        $this->assertFalse(CubemapRegistry::has('a'));
    }
}
