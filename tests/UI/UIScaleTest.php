<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Math\Vec2;
use PHPolygon\Runtime\Input;
use PHPolygon\Runtime\ScaledInput;
use PHPolygon\UI\UIScale;
use PHPUnit\Framework\TestCase;

final class UIScaleTest extends TestCase
{
    public function testForScreenFollowsTheHeight(): void
    {
        self::assertSame(1.0, UIScale::forScreen(1080.0));
        self::assertSame(2.0, UIScale::forScreen(2160.0));
        // 1440 / 1080 = 1.333 -> snapped to 1.375 (eighths).
        self::assertSame(1.375, UIScale::forScreen(1440.0));
    }

    public function testSmallWindowsKeepTheDesignSizeButThePlayerMayShrink(): void
    {
        self::assertSame(1.0, UIScale::forScreen(720.0));
        self::assertSame(0.875, UIScale::forScreen(1080.0, userFactor: 0.875));
        self::assertSame(2.5, UIScale::forScreen(2160.0, userFactor: 1.25));
    }

    public function testScaledInputReportsThePointerInLogicalPixels(): void
    {
        $scale = new UIScale(2.0);
        $input = new Input();
        $scaled = new ScaledInput($input, $scale);
        $input->handleCursorPosEvent(300.0, 140.0);

        self::assertEquals(new Vec2(150.0, 70.0), $scaled->getMousePosition());
        self::assertSame(150.0, $scaled->getMouseX());

        $scale->set(1.0);
        self::assertSame(300.0, $scaled->getMouseX(), 'follows the shared factor');
        self::assertSame($input, $scaled->inner());
    }
}
