<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Runtime\VioWindow;

/**
 * Rendering for a test does not belong on screen: an offscreen run draws into
 * an invisible surface, so it never takes the focus from whatever the person
 * is doing while the suite runs.
 */
final class OffscreenWindowTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function config(VioWindow $window): array
    {
        return (new \ReflectionMethod(VioWindow::class, 'createConfig'))->invoke($window);
    }

    public function testAWindowIsOnScreenByDefault(): void
    {
        $window = new VioWindow(320, 200, 'test');

        $this->assertArrayNotHasKey('headless', self::config($window));
    }

    public function testAnOffscreenWindowAsksVioForAnInvisibleOne(): void
    {
        $window = new VioWindow(320, 200, 'test', offscreen: true);

        $this->assertTrue(self::config($window)['headless'] ?? false);
    }

    public function testAGameWindowIsVisibleUnlessAskedOtherwise(): void
    {
        $this->assertFalse((new EngineConfig())->offscreen);
        $this->assertTrue((new EngineConfig(offscreen: true))->offscreen);
    }
}
