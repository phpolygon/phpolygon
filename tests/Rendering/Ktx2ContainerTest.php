<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\Ktx2Container;
use PHPUnit\Framework\TestCase;

class Ktx2ContainerTest extends TestCase
{
    public function testBc7LevelBytesRoundsPartialBlocksUp(): void
    {
        $this->assertSame(16, Ktx2Container::bc7LevelBytes(1, 1));
        $this->assertSame(64, Ktx2Container::bc7LevelBytes(8, 8));
        $this->assertSame(4 * 2 * 16, Ktx2Container::bc7LevelBytes(13, 5));
    }

    public function testPlainBc7ContainerLayout(): void
    {
        $levels = $this->bc7Levels(16, 8);
        $ktx = Ktx2Container::bc7(16, 8, $levels);

        $this->assertStringStartsWith(Ktx2Container::IDENTIFIER, $ktx);
        $this->assertSame(Ktx2Container::SUPERCOMPRESSION_NONE, Ktx2Container::supercompression($ktx));
        $head = $this->unpack('V9', $ktx, 12);
        $this->assertSame([145, 1, 16, 8, 0, 0, 1, count($levels), 0], array_values($head));
        $this->assertSame($levels, $this->levels($ktx));
        // Plain levels are 16-byte aligned and stored smallest first.
        $base = $this->unpack('P3', $ktx, 80);
        $last = $this->unpack('P3', $ktx, 80 + 24 * (count($levels) - 1));
        $this->assertSame(0, $base[1] % 16);
        $this->assertGreaterThan($last[1], $base[1]);
    }

    public function testRejectsWrongLevelSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ktx2Container::bc7(8, 8, [str_repeat("\0", 63)]);
    }

    public function testZlibSupercompressionShrinksAndInflatesBack(): void
    {
        $levels = $this->bc7Levels(64, 64);
        $plain = Ktx2Container::bc7(64, 64, $levels);
        $zlib = Ktx2Container::bc7(64, 64, $levels, zlibLevel: 9);

        $this->assertSame(Ktx2Container::SUPERCOMPRESSION_ZLIB, Ktx2Container::supercompression($zlib));
        $this->assertLessThan(strlen($plain) / 2, strlen($zlib));
        // Level index: compressed byteLength, original uncompressedByteLength.
        $base = $this->unpack('P3', $zlib, 80);
        $this->assertSame(strlen($levels[0]), $base[3]);
        $this->assertLessThan($base[3], $base[2]);

        $inflated = Ktx2Container::inflate($zlib);
        $this->assertNotNull($inflated);
        $this->assertSame($plain, $inflated);
    }

    public function testInflateKeepsKeyValueData(): void
    {
        $levels = [str_repeat("\x11", 16)];
        $kvd = pack('V', 12) . "KTXorien\0rd\0" ;
        $zlib = Ktx2Container::build(Ktx2Container::VK_FORMAT_BC7_UNORM, 1, 4, 4, $levels, 6, $kvd);
        $inflated = Ktx2Container::inflate($zlib);

        $this->assertNotNull($inflated);
        $index = $this->unpack('V4', $inflated, 48);
        $this->assertSame($kvd, substr($inflated, $index[3], $index[4]));
        $this->assertSame($levels, $this->levels($inflated));
    }

    public function testPlainContainerPassesThrough(): void
    {
        $plain = Ktx2Container::bc7(8, 8, $this->bc7Levels(8, 8));
        $this->assertSame($plain, Ktx2Container::inflate($plain));
    }

    public function testInflateRefusesOtherSchemesAndCorruptData(): void
    {
        $zlib = Ktx2Container::bc7(32, 32, $this->bc7Levels(32, 32), zlibLevel: 6);

        $zstd = substr_replace($zlib, pack('V', 2), 44, 4);
        $this->assertNull(Ktx2Container::inflate($zstd));
        $this->assertNull(Ktx2Container::inflate(substr($zlib, 0, strlen($zlib) - 10)));
        $this->assertNull(Ktx2Container::inflate('PNG not a ktx2 file at all, long enough to pass the size check ......'));
        $this->assertNull(Ktx2Container::supercompression('short'));
    }

    public function testDdsPayloadSkipsHeaderAndDx10Extension(): void
    {
        $blocks = str_repeat("\xAB", 64);
        $dds = 'DDS ' . str_repeat("\0", 80) . 'DX10' . str_repeat("\0", 40) . str_repeat("\0", 20) . $blocks;
        $this->assertSame($blocks, Ktx2Container::ddsPayload($dds, 8, 8));
    }

    /** @return list<string> a full BC7 mip chain of compressible pseudo-blocks */
    private function bc7Levels(int $width, int $height): array
    {
        $levels = [];
        $w = $width;
        $h = $height;
        while (true) {
            $levels[] = substr(str_repeat("BC7-block-data!\x00", intdiv(Ktx2Container::bc7LevelBytes($w, $h), 16) + 1), 0, Ktx2Container::bc7LevelBytes($w, $h));
            if ($w === 1 && $h === 1) {
                return $levels;
            }
            $w = max(1, intdiv($w, 2));
            $h = max(1, intdiv($h, 2));
        }
    }

    /** @return list<string> level data, base level first */
    private function levels(string $ktx): array
    {
        $count = max(1, $this->unpack('V', $ktx, 40)[1]);
        $levels = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = $this->unpack('P3', $ktx, 80 + 24 * $i);
            $levels[] = substr($ktx, $entry[1], $entry[2]);
        }
        return $levels;
    }

    /** @return array<int|string, int> */
    private function unpack(string $format, string $data, int $offset): array
    {
        $values = unpack($format, $data, $offset);
        $this->assertIsArray($values);
        /** @var array<int|string, int> $values */
        return $values;
    }
}
