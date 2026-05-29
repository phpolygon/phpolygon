<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Component;

use PHPolygon\Component\PlatformerController;
use PHPUnit\Framework\TestCase;

class PlatformerControllerTest extends TestCase
{
    public function testMaxFallIsClampedToNonNegativeSoGravityCannotFreezeMidAir(): void
    {
        // PlatformerControllerSystem computes `max($vy - $gravity, -$maxFall)`.
        // A negative or zero $maxFall makes `-$maxFall` non-negative, so the
        // max() clamps $vy upward and the character either rises or stops
        // falling — a silent failure mode that no error reports.
        $this->assertSame(0.0, (new PlatformerController(maxFall: -1.0))->maxFall);
        $this->assertSame(0.0, (new PlatformerController(maxFall: 0.0))->maxFall);
        $this->assertSame(0.9, (new PlatformerController(maxFall: 0.9))->maxFall);
    }
}
