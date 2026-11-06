<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use PHPolygon\Runtime\VioMainWindow;
use PHPolygon\Runtime\Window;

/**
 * The engine's main-window adapter is VioMainWindow; its former name stays an alias
 * until 1.0 and never collides with php-vio's global \VioWindow (2.41+).
 */
final class VioMainWindowAliasTest extends TestCase
{
    public function testTheFormerNameResolvesToTheSameClass(): void
    {
        self::assertTrue(class_exists('PHPolygon\\Runtime\\VioWindow'));
        $former = new \ReflectionClass('PHPolygon\\Runtime\\VioWindow');
        self::assertSame(VioMainWindow::class, $former->getName());
        self::assertTrue(is_subclass_of(VioMainWindow::class, Window::class));
    }

    /** `instanceof` autoloads nothing: game code checking the former name must see the engine window. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testInstanceofTheFormerNameHoldsWithoutAutoloadingIt(): void
    {
        $window = (new \ReflectionClass(VioMainWindow::class))->newInstanceWithoutConstructor();
        self::assertTrue($window instanceof \PHPolygon\Runtime\VioWindow); // @phpstan-ignore instanceof.alwaysTrue
    }

    public function testTheEngineWindowIsNotPhpViosWindowClass(): void
    {
        if (!class_exists('VioWindow', false)) {
            self::markTestSkipped('php-vio without the global VioWindow class (before 2.41)');
        }
        self::assertNotSame('VioWindow', VioMainWindow::class);
        self::assertFalse(is_a(VioMainWindow::class, 'VioWindow', true));
    }
}
