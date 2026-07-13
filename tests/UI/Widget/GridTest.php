<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Grid;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Sizing;
use PHPolygon\UI\Widget\WidgetSerializer;
use PHPUnit\Framework\TestCase;

class GridTest extends TestCase
{
    private UIStyle $style;

    protected function setUp(): void
    {
        $this->style = UIStyle::dark();
    }

    private function cell(float $w, float $h): Label
    {
        return (new Label('x'))->size(Sizing::fixed($w, $h));
    }

    public function testColumnsAlignAcrossRows(): void
    {
        // 2 columns, 2 rows. Column widths must be the max of each column's cells.
        $grid = new Grid(columns: 2, columnSpacing: 0.0, rowSpacing: 0.0);
        $grid->addChild($this->cell(30, 20));  // row 0, col 0
        $grid->addChild($this->cell(50, 20));  // row 0, col 1
        $grid->addChild($this->cell(40, 20));  // row 1, col 0 -> col 0 max = 40
        $grid->addChild($this->cell(10, 20));  // row 1, col 1 -> col 1 max = 50

        $grid->measure(400, 400, $this->style);
        $grid->setBounds(new Rect(0, 0, $grid->getMeasuredWidth(), $grid->getMeasuredHeight()));
        $grid->layout($this->style);

        $cells = $grid->getChildren();
        // Col 0 starts at x=0, col 1 starts at x=40 (widest col-0 cell) for BOTH rows.
        $this->assertSame(0.0, $cells[0]->getBounds()->x);
        $this->assertSame(40.0, $cells[1]->getBounds()->x);
        $this->assertSame(0.0, $cells[2]->getBounds()->x);
        $this->assertSame(40.0, $cells[3]->getBounds()->x, 'second row col-1 shares col-0 width');
    }

    public function testRowMajorFlowAndRowStacking(): void
    {
        $grid = new Grid(columns: 2, columnSpacing: 0.0, rowSpacing: 6.0);
        $grid->addChild($this->cell(30, 20));  // row 0
        $grid->addChild($this->cell(30, 20));  // row 0
        $grid->addChild($this->cell(30, 20));  // row 1

        $grid->measure(400, 400, $this->style);
        $grid->setBounds(new Rect(0, 0, $grid->getMeasuredWidth(), $grid->getMeasuredHeight()));
        $grid->layout($this->style);

        $cells = $grid->getChildren();
        $this->assertSame(0.0, $cells[0]->getBounds()->y, 'row 0');
        $this->assertSame(0.0, $cells[1]->getBounds()->y, 'row 0');
        $this->assertSame(26.0, $cells[2]->getBounds()->y, 'row 1 = 20 + 6 rowSpacing');
    }

    public function testColumnSpacingOffsetsColumns(): void
    {
        $grid = new Grid(columns: 3, columnSpacing: 10.0, rowSpacing: 0.0);
        $grid->addChild($this->cell(20, 20));
        $grid->addChild($this->cell(20, 20));
        $grid->addChild($this->cell(20, 20));

        $grid->measure(400, 400, $this->style);
        $grid->setBounds(new Rect(0, 0, $grid->getMeasuredWidth(), $grid->getMeasuredHeight()));
        $grid->layout($this->style);

        $cells = $grid->getChildren();
        $this->assertSame(0.0, $cells[0]->getBounds()->x);
        $this->assertSame(30.0, $cells[1]->getBounds()->x);  // 20 + 10
        $this->assertSame(60.0, $cells[2]->getBounds()->x);  // 40 + 20
    }

    public function testMeasuredSizeWrapsContent(): void
    {
        $grid = new Grid(columns: 2, columnSpacing: 5.0, rowSpacing: 5.0);
        $grid->addChild($this->cell(30, 20));
        $grid->addChild($this->cell(40, 20));

        $grid->measure(1000, 1000, $this->style);

        // 30 + 5 + 40 = 75 wide, single row 20 tall.
        $this->assertSame(75.0, $grid->getMeasuredWidth());
        $this->assertSame(20.0, $grid->getMeasuredHeight());
    }

    public function testFillWidthDistributesLeftover(): void
    {
        $grid = new Grid(columns: 2, columnSpacing: 0.0, rowSpacing: 0.0);
        $grid->sizing = Sizing::fillWidth();
        $grid->addChild($this->cell(30, 20));
        $grid->addChild($this->cell(50, 20));

        $grid->measure(200, 200, $this->style);
        $grid->setBounds(new Rect(0, 0, 200, 200));
        $grid->layout($this->style);

        // Natural = 80, leftover = 120 over 2 cols = +60 each: col0 = 90, col1 = 110.
        $cells = $grid->getChildren();
        $this->assertSame(0.0, $cells[0]->getBounds()->x);
        $this->assertSame(90.0, $cells[1]->getBounds()->x, 'leftover split evenly across columns');
    }

    public function testSkipsHiddenChildren(): void
    {
        $grid = new Grid(columns: 2, columnSpacing: 0.0, rowSpacing: 0.0);
        $grid->addChild($this->cell(30, 20));
        $hidden = $this->cell(30, 20);
        $hidden->hide();
        $grid->addChild($hidden);
        $grid->addChild($this->cell(50, 20));  // becomes the second visible cell -> col 1

        $grid->measure(400, 400, $this->style);
        $grid->setBounds(new Rect(0, 0, $grid->getMeasuredWidth(), $grid->getMeasuredHeight()));
        $grid->layout($this->style);

        $cells = $grid->getChildren();
        $this->assertSame(0.0, $cells[0]->getBounds()->x);
        $this->assertSame(30.0, $cells[2]->getBounds()->x, 'hidden child does not occupy a cell');
    }

    public function testSerializationRoundTrip(): void
    {
        $grid = new Grid(columns: 3, columnSpacing: 8.0, rowSpacing: 4.0);
        $grid->addChild(new Label('a'));

        $serializer = new WidgetSerializer;
        $restored = $serializer->fromArray($serializer->toArray($grid));

        $this->assertInstanceOf(Grid::class, $restored);
        $this->assertSame(3, $restored->columns);
        $this->assertSame(8.0, $restored->columnSpacing);
        $this->assertSame(4.0, $restored->rowSpacing);
        $this->assertCount(1, $restored->getChildren());
    }

    public function testInstantiatesFromFqcnNode(): void
    {
        $restored = (new WidgetSerializer)->fromArray([
            '_widget' => Grid::class,
            'columns' => 2,
        ]);

        $this->assertInstanceOf(Grid::class, $restored);
        $this->assertSame(2, $restored->columns);
    }
}
