<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec2;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\ScrollView;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\WidgetTree;

/**
 * A scroll view that has nothing to scroll must not swallow the wheel.
 *
 * Nesting one scroll view inside another is ordinary: a long list of cards
 * where a card holds a short list of its own. The wheel goes to the nearest
 * scroll view above the pointer, and if that one's content fits, nothing moves
 * at all - the outer list sits still while the pointer rests over the inner
 * one. From the outside it looks like a dead patch in the middle of the page.
 *
 * So an inner view that cannot scroll is passed over, and the next one up gets
 * the wheel. An inner view that CAN scroll still keeps it - otherwise the two
 * would move together and neither would be controllable.
 */
final class NestedScrollViewWheelTest extends TestCase
{
    /**
     * An outer scroll view with plenty to scroll, and an inner one over which
     * the pointer rests.
     *
     * @param int $innerLines how much content the inner view holds
     * @return array{0: ScrollView, 1: ScrollView, 2: WidgetTree}
     */
    private function nested(int $innerLines): array
    {
        $inner = new ScrollView();
        $inner->sizing->height = 60.0;
        $innerBox = new VBox();
        for ($i = 0; $i < $innerLines; $i++) {
            $innerBox->addChild(new Label('inner ' . $i));
        }
        $inner->addChild($innerBox);

        $outerBox = new VBox();
        $outerBox->addChild($inner);
        for ($i = 0; $i < 60; $i++) {
            $outerBox->addChild(new Label('outer ' . $i));
        }

        $outer = new ScrollView();
        $outer->sizing->fillWidth = true;
        $outer->sizing->fillHeight = true;
        $outer->addChild($outerBox);

        $root = new VBox();
        $root->sizing->fillWidth = true;
        $root->sizing->fillHeight = true;
        $root->addChild($outer);

        $tree = new WidgetTree($root, new WidgetTestHelper(), new WheelInput(0.0, 0.0), 400, 300, UIStyle::dark());
        $tree->performLayout();

        return [$outer, $inner, $tree];
    }

    /** Point the mouse at the middle of a widget and turn the wheel one notch. */
    private function wheelOver(WidgetTree $tree, ScrollView $target): void
    {
        $b = $target->getBounds();
        $input = new WheelInput($b->x + $b->width / 2, $b->y + $b->height / 2, -1.0);

        $rp = new \ReflectionProperty(WidgetTree::class, 'input');
        $rp->setValue($tree, $input);

        $tree->update();
    }

    public function testAnInnerViewWithNothingToScrollPassesTheWheelOn(): void
    {
        [$outer, $inner, $tree] = $this->nested(innerLines: 1);

        self::assertSame(0.0, $inner->getMaxScroll(), 'Vorbedingung: der innere hat nichts zu scrollen');

        $this->wheelOver($tree, $inner);

        self::assertGreaterThan(0.0, $outer->getScrollY(), 'die äußere Liste muss sich bewegen');
        self::assertSame(0.0, $inner->getScrollY());
    }

    public function testAnInnerViewThatCanScrollKeepsTheWheel(): void
    {
        [$outer, $inner, $tree] = $this->nested(innerLines: 40);

        self::assertGreaterThan(0.0, $inner->getMaxScroll(), 'Vorbedingung: der innere kann scrollen');

        $this->wheelOver($tree, $inner);

        self::assertGreaterThan(0.0, $inner->getScrollY(), 'der innere scrollt selbst');
        self::assertSame(0.0, $outer->getScrollY(), 'und beide zugleich zu bewegen wäre unbedienbar');
    }
}

/** An input double that reports one mouse position and one wheel delta. */
final class WheelInput implements InputInterface
{
    public function __construct(
        private float $x = 0.0,
        private float $y = 0.0,
        private float $scrollY = 0.0,
    ) {}

    public function isKeyDown(int $key): bool { return false; }
    public function isKeyPressed(int $key): bool { return false; }
    public function isKeyTyped(int $key): bool { return false; }
    public function isKeyReleased(int $key): bool { return false; }
    public function isMouseButtonDown(int $button): bool { return false; }
    public function isMouseButtonPressed(int $button): bool { return false; }
    public function isMouseButtonReleased(int $button): bool { return false; }
    public function getMousePosition(): Vec2 { return new Vec2($this->x, $this->y); }
    public function getMouseX(): float { return $this->x; }
    public function getMouseY(): float { return $this->y; }
    public function getScrollX(): float { return 0.0; }
    public function getScrollY(): float { return $this->scrollY; }
    /** @return list<string> */
    public function getCharsTyped(): array { return []; }
    public function getTextInput(): string { return ''; }
    public function getBackspaceCount(): int { return 0; }
    public function showSoftKeyboard(): void {}
    public function hideSoftKeyboard(): void {}
    public function suppress(int $frames = 0, float $seconds = 0.0): void {}
    public function unsuppress(): void {}
    public function isSuppressed(): bool { return false; }
    public function clearKeyEdges(): void {}
    public function endFrame(): void {}
}
