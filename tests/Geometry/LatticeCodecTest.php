<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\LatticeCodec;
use PHPUnit\Framework\TestCase;

final class LatticeCodecTest extends TestCase
{
    public function testHeightsRoundTripWithinHalfAQuantumAndKeepTheVoids(): void
    {
        mt_srand(7);
        $width = 37;
        $corners = [];
        for ($k = 0; $k < $width * 29; $k++) {
            $corners[] = mt_rand(0, 9) === 0 ? null : -1.5 + 6.0 * mt_rand() / mt_getrandmax();
        }
        $packed = LatticeCodec::packHeights($corners, $width, 2.0);
        $back = LatticeCodec::unpackHeights($packed, $width, 2.0);
        self::assertCount(count($corners), $back);
        foreach ($corners as $k => $h) {
            if ($h === null) {
                self::assertNull($back[$k]);
            } else {
                self::assertEqualsWithDelta($h, (float) $back[$k], 0.5e-4 + 1e-12);
            }
        }
        // Packing the unpacked values again gives the same text.
        self::assertSame($packed, LatticeCodec::packHeights($back, $width, 2.0));
    }

    public function testQuantisedStringAnswersPerCorner(): void
    {
        $packed = LatticeCodec::packHeights([1.0, null, 1.2345, 0.0, 0.0001, null], 3, 0.0);
        $q = LatticeCodec::unpackQuantised($packed, 3);
        self::assertSame(12, strlen($q));
        self::assertSame(1.0, LatticeCodec::quantisedAt($q, 0));
        self::assertNull(LatticeCodec::quantisedAt($q, 1));
        self::assertEqualsWithDelta(1.2345, (float) LatticeCodec::quantisedAt($q, 2), 1e-9);
        self::assertSame(0.0, LatticeCodec::quantisedAt($q, 3));
        self::assertEqualsWithDelta(0.0001, (float) LatticeCodec::quantisedAt($q, 4), 1e-9);
        self::assertNull(LatticeCodec::quantisedAt($q, 5));
    }

    public function testASmoothFieldPacksSmall(): void
    {
        $corners = [];
        for ($j = 0; $j < 100; $j++) {
            for ($i = 0; $i < 100; $i++) {
                $corners[] = 0.02 * $i + 0.01 * $j;
            }
        }
        $packed = LatticeCodec::packHeights($corners, 100);
        self::assertLessThan(10000 * 2 / 8, strlen($packed), 'far below the raw 20 kB');
    }

    public function testOutOfRangeAndBadShapesThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LatticeCodec::packHeights([7.0], 1);
    }

    public function testRaggedRowsThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LatticeCodec::packHeights([1.0, 2.0, 3.0], 2);
    }

    public function testDamagedInputThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LatticeCodec::unpackHeights('not base64 zlib!!', 4);
    }

    public function testBytesRoundTrip(): void
    {
        $bytes = str_repeat("\x00\x01\x02", 500) . random_bytes(32);
        self::assertSame($bytes, LatticeCodec::unpackBytes(LatticeCodec::packBytes($bytes)));
    }
}
