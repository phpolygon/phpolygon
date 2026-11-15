<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Accessibility\AccessibilitySettings;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\ScaledRenderer2D;
use PHPolygon\Runtime\Input;
use PHPolygon\Tests\UI\Widget\WidgetTestHelper;
use PHPolygon\UI\TextScale;
use PHPolygon\UI\UIScale;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Sizing;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\WidgetTree;

class TextScaleTest extends TestCase
{
    /** @return array{WidgetTree, Button, Label, WidgetTestHelper} */
    private function build(?float $scale): array
    {
        $renderer = new WidgetTestHelper();
        $button = new Button('Start');
        $fixed = (new Button('Fixed'))->size(Sizing::fixed(120, 30));
        $label = new Label('Some text');
        $root = (new VBox())->addChild($button)->addChild($fixed)->addChild($label);
        $tree = new WidgetTree($root, $renderer, new Input(), 800, 600, UIStyle::dark());
        if ($scale !== null) {
            $tree->setTextScale($scale);
        }
        $tree->performLayout();

        return [$tree, $button, $label, $renderer];
    }

    public function testFactorOneIsByteForByteTheSameLayoutAndDraw(): void
    {
        [$plain, $b1, , $r1] = $this->build(null);
        [$one, $b2, , $r2] = $this->build(1.0);

        $plain->draw();
        $one->draw();

        $this->assertEquals($b1->getBounds(), $b2->getBounds());
        $this->assertEquals($r1->calls, $r2->calls);
    }

    public function testBiggerFactorGrowsTextAndTextSizedWidgets(): void
    {
        [, $button1, , $r1] = $this->build(1.0);
        [$big, $button2, , $r2] = $this->build(2.0);
        $big->draw();

        $this->assertGreaterThan($button1->getBounds()->width, $button2->getBounds()->width);
        $this->assertGreaterThan($button1->getBounds()->height, $button2->getBounds()->height);
        $sizes = [];
        foreach ($r2->calls as $call) {
            if ($call['method'] === 'drawText') {
                $sizes[] = $call['args'][3];
            }
        }
        $this->assertNotEmpty($sizes);
        $this->assertSame([32.0], array_values(array_unique($sizes)), 'the 16 px design font is drawn at 32');
        unset($r1);
    }

    public function testFixedSizeWidgetsKeepTheirSize(): void
    {
        [$tree] = $this->build(2.0);
        $fixed = $tree->getRoot()->getChildren()[1];

        $this->assertSame(120.0, $fixed->getBounds()->width);
    }

    public function testTheTreesOwnStyleIsNotChanged(): void
    {
        [$tree] = $this->build(2.0);

        $this->assertSame(16.0, $tree->getStyle()->fontSize);
    }

    public function testSettingsKeepTheFactorInRangeAndInJson(): void
    {
        $settings = (new AccessibilitySettings())->with(textScale: 1.5);

        $this->assertSame(1.5, $settings->textScale);
        $this->assertSame(1.5, AccessibilitySettings::fromJson($settings->toJson())->textScale);
        $this->assertSame(AccessibilitySettings::MAX_TEXT_SCALE, (new AccessibilitySettings())->with(textScale: 99.0)->textScale);
        $this->assertSame(AccessibilitySettings::MIN_TEXT_SCALE, AccessibilitySettings::fromJson(['textScale' => 0.01])->textScale);
        $this->assertSame(1.0, AccessibilitySettings::fromJson(['textScale' => 'big'])->textScale, 'a field of the wrong type keeps its default');
        $this->assertSame(1.0, AccessibilitySettings::fromJson([])->textScale, 'a save from before the option loads at 1.0');
        $this->assertTrue((new AccessibilitySettings())->isDefault());
        $this->assertFalse($settings->isDefault());
        $this->assertFalse($settings->changesImage(), 'text size changes no pixel of the 3D image');
    }

    public function testHelperForFixedGridLayouts(): void
    {
        $scale = TextScale::fromSettings((new AccessibilitySettings())->with(textScale: 1.5));
        $renderer = new WidgetTestHelper();

        $this->assertSame(24.0, $scale->font(16.0));
        $this->assertSame(150.0, $scale->box(100.0));
        $this->assertSame(1.25, $scale->capped(1.25)->factor);
        $this->assertSame(1.5, $scale->capped(2.0)->factor);
        $this->assertEquals($renderer->measureText('abc', 24.0), $scale->measure($renderer, 'abc', 16.0));
        $this->assertFalse($scale->isIdentity());
        $this->assertTrue((new TextScale())->isIdentity());
        $this->assertSame(UIStyle::dark()->fontSize * 1.5, $scale->apply(UIStyle::dark())->fontSize);
    }

    public function testIdentityApplyReturnsTheSameStyleObject(): void
    {
        $style = UIStyle::dark();

        $this->assertSame($style, (new TextScale(1.0))->apply($style));
    }

    public function testComposesWithUiScaleInsteadOfReplacingIt(): void
    {
        $inner = new WidgetTestHelper();
        $screen = new ScaledRenderer2D($inner, new UIScale(2.0));
        $text = new TextScale(1.5);

        $screen->drawText('x', 0, 0, $text->font(16.0), new Color(1, 1, 1, 1));

        $this->assertSame(48.0, $inner->calls[0]['args'][3], '16 design px x 1.5 player x 2.0 screen');
    }
}