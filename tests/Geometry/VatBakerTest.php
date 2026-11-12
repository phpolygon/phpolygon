<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\VatBaker;
use PHPUnit\Framework\TestCase;

final class VatBakerTest extends TestCase
{
    public function testDefaultBakeIsUnchanged(): void
    {
        $f = [new MeshData([0, 0, 0, 1, 2, 3], [], [], []), new MeshData([0.5, 0, 0, 1, 2.5, 3], [], [], [])];
        $b = VatBaker::bake($f);
        self::assertSame(2, $b['texelsPerVertex']);
        self::assertSame(4, $b['width']);
        self::assertSame(2, $b['height']);
        // Hash of the original result keys, taken from the baker before the normals option existed.
        self::assertSame('c2b9ab8d3e83e974c8f069fe6de08e0d', md5(serialize([
            'rgba' => $b['rgba'], 'width' => $b['width'], 'height' => $b['height'], 'min' => $b['min'], 'extent' => $b['extent'],
        ])));
    }

    public function testNormalsAreBakedAsOctahedralTexel(): void
    {
        $n = [0.0, 1.0, 0.0, 0.0, 0.0, -1.0, 0.57735, 0.57735, -0.57735];
        $frame = new MeshData([0, 0, 0, 1, 0, 0, 0, 1, 0], $n, [], []);
        $plain = VatBaker::bake([$frame]);
        $b = VatBaker::bake([$frame], true);
        self::assertSame(3, $b['texelsPerVertex']);
        self::assertSame(9, $b['width']);
        // Position texels are the same bytes as without normals.
        self::assertSame(substr($plain["rgba"], 0, 8), substr($b["rgba"], 0, 8));
        for ($i = 0; $i < 3; $i++) {
            $o = $i * 12 + 8;
            $d = VatBaker::octDecode((ord($b['rgba'][$o]) << 8) | ord($b['rgba'][$o + 1]), (ord($b['rgba'][$o + 2]) << 8) | ord($b['rgba'][$o + 3]));
            for ($a = 0; $a < 3; $a++) {
                self::assertEqualsWithDelta($n[$i * 3 + $a], $d[$a], 1e-3);
            }
        }
    }

    public function testNormalsRequireNormals(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VatBaker::bake([new MeshData([0, 0, 0], [], [], [])], true);
    }
}
