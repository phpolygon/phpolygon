<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Quality\ShadingRate;

/**
 * The shading-rate tier maps onto php-vio's VIO_SHADING_RATE_* values without
 * needing the extension loaded, and its pixel-work fractions follow the block
 * sizes (2x2 = a quarter, 4x4 = a sixteenth).
 */
final class ShadingRateTest extends TestCase
{
    public function testVioConstantsMatchTheExtensionValues(): void
    {
        // VIO_SHADING_RATE_1X1 = 0, 2X2 = 3, 4X4 = 4 (php-vio include/vio_types.h).
        self::assertSame(0, ShadingRate::Full->vioConstant());
        self::assertSame(3, ShadingRate::Half->vioConstant());
        self::assertSame(4, ShadingRate::Quarter->vioConstant());
        if (defined('VIO_SHADING_RATE_2X2')) {
            self::assertSame(constant('VIO_SHADING_RATE_2X2'), ShadingRate::Half->vioConstant());
            self::assertSame(constant('VIO_SHADING_RATE_4X4'), ShadingRate::Quarter->vioConstant());
        }
    }

    public function testPixelWorkFractionFollowsTheBlockSize(): void
    {
        self::assertSame(1.0, ShadingRate::Full->pixelWorkFraction());
        self::assertSame(0.25, ShadingRate::Half->pixelWorkFraction());
        self::assertSame(0.0625, ShadingRate::Quarter->pixelWorkFraction());
        self::assertSame(ShadingRate::Half, ShadingRate::from('half'));
    }
}
