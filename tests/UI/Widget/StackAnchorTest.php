<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Rect;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Sizing;
use PHPolygon\UI\Widget\Stack;

/**
 * A Stack child can pin itself to a corner via the declarative stackAnchor
 * string (e.g. a badge overlaid on an icon's bottom-left), without the
 * imperative addAnchored() call.
 */
class StackAnchorTest extends TestCase
{
    private function laidOutBadge(string $anchor): Rect
    {
        $stack = new Stack();
        $stack->sizing = Sizing::fixed(200.0, 100.0);

        $badge = new Label('3');
        $badge->stackAnchor = $anchor;
        $badge->sizing = Sizing::fixed(30.0, 20.0);
        $stack->addChild($badge);

        $style = UIStyle::dark();
        $stack->measure(200.0, 100.0, $style);
        $stack->setBounds(new Rect(0.0, 0.0, 200.0, 100.0));
        $stack->layout($style);

        return $badge->getBounds();
    }

    public function testBottomLeftAnchorPinsToLowerLeftCorner(): void
    {
        $b = $this->laidOutBadge('bottom_left');
        self::assertEqualsWithDelta(0.0, $b->x, 0.01, 'pinned to the left edge');
        self::assertEqualsWithDelta(80.0, $b->y, 0.01, 'pinned to the bottom (100 - 20)');
    }

    public function testEmptyAnchorFallsBackToTopLeft(): void
    {
        $b = $this->laidOutBadge('');
        self::assertEqualsWithDelta(0.0, $b->x, 0.01);
        self::assertEqualsWithDelta(0.0, $b->y, 0.01, 'default anchor is top-left');
    }

    public function testUnknownAnchorFallsBackToTopLeft(): void
    {
        $b = $this->laidOutBadge('nonsense');
        self::assertEqualsWithDelta(0.0, $b->y, 0.01, 'an invalid anchor is ignored, not fatal');
    }
}
