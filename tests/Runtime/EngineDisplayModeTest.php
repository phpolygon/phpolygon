<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPolygon\Engine;
use PHPolygon\Runtime\NullWindow;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The saved display mode must be applied to the window BEFORE the studio splash
 * draws — i.e. during Engine::run()'s init, not in the game's onInit (which runs
 * after the splash). Engine::applyDisplayMode() is the pure window-mode step,
 * unit-tested here against a headless NullWindow.
 */
final class EngineDisplayModeTest extends TestCase
{
    private static function apply(NullWindow $w, string $mode): void
    {
        $m = new ReflectionMethod(Engine::class, 'applyDisplayMode');
        $m->invoke(null, $w, $mode);
    }

    public function testFullscreenModeMakesWindowFullscreen(): void
    {
        $w = new NullWindow(1280, 720, 'test');
        self::apply($w, 'fullscreen');
        $this->assertTrue($w->isFullscreen());
        $this->assertFalse($w->isBorderless());
    }

    public function testBorderlessModeMakesWindowBorderless(): void
    {
        $w = new NullWindow(1280, 720, 'test');
        self::apply($w, 'borderless');
        $this->assertTrue($w->isBorderless());
        $this->assertFalse($w->isFullscreen());
    }

    public function testWindowedModeLeavesWindowWindowed(): void
    {
        $w = new NullWindow(1280, 720, 'test');
        self::apply($w, 'windowed');
        $this->assertFalse($w->isFullscreen());
        $this->assertFalse($w->isBorderless());
    }

    public function testUnknownModeIsTreatedAsWindowed(): void
    {
        // A corrupted setting must never crash engine startup.
        $w = new NullWindow(1280, 720, 'test');
        self::apply($w, 'garbage');
        $this->assertFalse($w->isFullscreen());
        $this->assertFalse($w->isBorderless());
    }
}
