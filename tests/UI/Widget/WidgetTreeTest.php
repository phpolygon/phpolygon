<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Runtime\Input;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\Stack;
use PHPolygon\UI\Widget\Checkbox;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Sizing;
use PHPolygon\UI\Widget\TextInput;
use PHPolygon\UI\Widget\Toggle;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\WidgetTree;

class WidgetTreeTest extends TestCase
{
    private Input $input;
    private WidgetTestHelper $renderer;
    private UIStyle $style;

    protected function setUp(): void
    {
        $this->input = new Input();
        $this->renderer = new WidgetTestHelper();
        $this->style = UIStyle::dark();
    }

    private function tree(VBox $root): WidgetTree
    {
        return new WidgetTree($root, $this->renderer, $this->input, 800, 600, $this->style);
    }

    public function testPerformLayoutSetsRootBounds(): void
    {
        $root = new VBox();
        $tree = $this->tree($root);
        $tree->performLayout();

        $this->assertEquals(800.0, $root->getBounds()->width);
        $this->assertEquals(600.0, $root->getBounds()->height);
    }

    public function testAcceptsSiblingInputInterfaceNotJustConcreteInput(): void
    {
        // The game's VioInput implements InputInterface but does NOT extend the
        // concrete Input — WidgetTree must accept it (this was the runtime blocker).
        $input = new class implements InputInterface
        {
            public function isKeyDown(int $key): bool
            {
                return false;
            }

            public function isKeyPressed(int $key): bool
            {
                return false;
            }

            public function isKeyReleased(int $key): bool
            {
                return false;
            }

            public function isMouseButtonDown(int $button): bool
            {
                return false;
            }

            public function isMouseButtonPressed(int $button): bool
            {
                return false;
            }

            public function isMouseButtonReleased(int $button): bool
            {
                return false;
            }

            public function getMousePosition(): Vec2
            {
                return new Vec2(0.0, 0.0);
            }

            public function getMouseX(): float
            {
                return 0.0;
            }

            public function getMouseY(): float
            {
                return 0.0;
            }

            public function getScrollX(): float
            {
                return 0.0;
            }

            public function getScrollY(): float
            {
                return 0.0;
            }

            /** @return list<string> */
            public function getCharsTyped(): array
            {
                return [];
            }

            public function getTextInput(): string
            {
                return '';
            }

            public function getBackspaceCount(): int
            {
                return 0;
            }

            public function showSoftKeyboard(): void {}

            public function hideSoftKeyboard(): void {}

            public function suppress(int $frames = 0, float $seconds = 0.0): void {}

            public function unsuppress(): void {}

            public function isSuppressed(): bool
            {
                return false;
            }

            public function clearKeyEdges(): void {}

            public function endFrame(): void {}
        };

        $this->assertNotInstanceOf(Input::class, $input);

        $root = new VBox();
        $root->addChild(new Label('Hi'));
        $tree = new WidgetTree($root, $this->renderer, $input, 800, 600, $this->style);
        $tree->update(); // full input → layout → draw cycle must run

        $this->assertInstanceOf(WidgetTree::class, $tree);
    }

    public function testDrawProducesRenderCalls(): void
    {
        $root = new VBox();
        $root->addChild((new Label('Hi'))->size(Sizing::fixed(100, 20)));

        $tree = $this->tree($root);
        $tree->performLayout();
        $tree->draw();

        $textCalls = array_filter($this->renderer->calls, fn($c) => $c['method'] === 'drawText');
        $this->assertNotEmpty($textCalls);
    }

    public function testButtonClickEvent(): void
    {
        $root = new VBox();
        $btn = (new Button('OK'))->size(Sizing::fixed(100, 30));
        $root->addChild($btn);

        $tree = $this->tree($root);
        $tree->performLayout();

        $clicked = false;
        $btn->on('click', function () use (&$clicked) { $clicked = true; });

        // Press on button
        $this->input->handleCursorPosEvent(
            $btn->getBounds()->x + 5,
            $btn->getBounds()->y + 5,
        );
        $this->input->handleMouseButtonEvent(0, 1);
        $tree->processInput();
        $this->assertTrue($btn->pressed);

        // Release on button
        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleMouseButtonEvent(0, 0);
        $tree->processInput();

        $this->assertTrue($clicked);
        $this->assertFalse($btn->pressed);
    }

    public function testButtonClickFiresOnReleaseWithoutMatchingPress(): void
    {
        // Models a retained host that rebuilds the tree / re-expands repeater
        // rows each frame: the press frame's Button instance is gone by the
        // release frame, so there is no pressedWidget to match. A release over an
        // enabled button must still fire click (release-only, like UIContext).
        $root = new VBox();
        $btn = (new Button('OK'))->size(Sizing::fixed(100, 30));
        $root->addChild($btn);

        $tree = $this->tree($root);
        $tree->performLayout();

        $clicked = false;
        $btn->on('click', function () use (&$clicked) { $clicked = true; });

        // Press OUTSIDE the button so no pressedWidget is captured for it
        // (stands in for a press that landed on a now-discarded instance).
        $this->input->handleCursorPosEvent(400.0, 400.0);
        $this->input->handleMouseButtonEvent(0, 1);
        $tree->processInput();
        $this->assertFalse($btn->pressed, 'press outside did not arm the button');

        // Release OVER the button on the next frame — must still click.
        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleCursorPosEvent(
            $btn->getBounds()->x + 5,
            $btn->getBounds()->y + 5,
        );
        $this->input->handleMouseButtonEvent(0, 0);
        $tree->processInput();

        $this->assertTrue($clicked, 'release over an enabled button clicks it without a matching press');
    }

    public function testUpdateLaysOutBeforeInputSoAFreshTreeClicks(): void
    {
        // The data-bound host builds + binds a NEW tree each frame and calls a
        // single update() — it never calls performLayout() itself. update() must
        // lay out before hit-testing, or the click tests against zero bounds and
        // misses. This is the exact desktop-launcher regression.
        $root = new VBox();
        $btn = (new Button('OK'))->size(Sizing::fixed(100, 30));
        $root->addChild($btn);

        $clicked = false;
        $btn->on('click', function () use (&$clicked) { $clicked = true; });

        $tree = $this->tree($root); // deliberately NOT laid out

        $this->input->handleCursorPosEvent(10.0, 10.0);
        $this->input->handleMouseButtonEvent(0, 1);
        $this->input->endFrame();
        $this->input->handleMouseButtonEvent(0, 0);
        $tree->update(); // performLayout() must run before processInput()

        $this->assertTrue($clicked, 'a fresh tree lays out before input so the release hits the button');
    }

    public function testHoveringPlainContainerDoesNotSuppressInput(): void
    {
        // Hovering a non-control (label/layout) must NOT suppress the frame's
        // remaining input — a full-screen panel would otherwise kill any
        // immediate-mode UI (dialogs, toasts) drawn on top of it this frame.
        $root = new VBox();
        $root->addChild((new Label('Hi'))->size(Sizing::fixed(100, 20)));
        $tree = $this->tree($root);

        $this->input->handleCursorPosEvent(10.0, 10.0);
        $tree->update();

        $this->assertFalse($this->input->isSuppressed(), 'hovering a non-control does not suppress');
    }

    public function testButtonLabelAlignment(): void
    {
        $center = (new Button('Hi'))->size(Sizing::fixed(200, 30));
        $center->setBounds(new Rect(0.0, 0.0, 200.0, 30.0));
        $center->draw($this->renderer, $this->style);

        $left = (new Button('Hi'))->size(Sizing::fixed(200, 30));
        $left->align = 'left';
        $left->setBounds(new Rect(0.0, 0.0, 200.0, 30.0));
        $left->draw($this->renderer, $this->style);

        $texts = array_values(array_filter($this->renderer->calls, fn ($c) => $c['method'] === 'drawText'));
        $this->assertCount(2, $texts);
        $this->assertSame(100.0, $texts[0]['args'][1], 'centered label draws at the box center');
        $this->assertSame(12.0, $texts[1]['args'][1], 'left-aligned label draws at padding.left');
    }

    public function testHoverFlagClearsAcrossRebuiltTrees(): void
    {
        // The data-bound host rebuilds the tree each frame while the widget
        // instances persist. Hovering a button then moving away on a fresh tree
        // must clear its hover flag — otherwise it stays stuck "filled".
        $root = new VBox();
        $btn = (new Button('OK'))->size(Sizing::fixed(100, 30));
        $root->addChild($btn);

        $this->input->handleCursorPosEvent(10.0, 10.0);
        $this->tree($root)->update();
        $this->assertTrue($btn->hovered, 'hovered while the pointer is over it');

        // New tree, same root, pointer moved away.
        $this->input->handleCursorPosEvent(400.0, 400.0);
        $this->tree($root)->update();
        $this->assertFalse($btn->hovered, 'hover clears on a rebuilt tree when the pointer leaves');
    }

    public function testHoverableStackHighlightsWhilePointerOverCard(): void
    {
        // A rich card — content + a flat whole-card click overlay — opts into a
        // hover tint via Stack::$hoverColor. The overlay is the hit target; its
        // ancestor Stack is what highlights, so the fill sits behind the content.
        $root = new VBox();
        $card = new Stack();
        $card->hoverColor = new Color(0.1, 0.4, 0.25, 1.0);
        $card->sizing = Sizing::fixed(120, 40);
        $overlay = new Button('');
        $overlay->flat = true;
        $overlay->sizing = new Sizing(fillWidth: true, fillHeight: true);
        $card->addChild($overlay);
        $root->addChild($card);

        $this->input->handleCursorPosEvent(10.0, 10.0);
        $this->tree($root)->update();
        $this->assertTrue($card->hovered, 'card highlights while the pointer is over it');

        $this->input->handleCursorPosEvent(400.0, 400.0);
        $this->tree($root)->update();
        $this->assertFalse($card->hovered, 'card highlight clears when the pointer leaves');
    }

    public function testHoveringControlSuppressesInput(): void
    {
        $root = new VBox();
        $root->addChild((new Button('OK'))->size(Sizing::fixed(100, 30)));
        $tree = $this->tree($root);

        $this->input->handleCursorPosEvent(10.0, 10.0);
        $tree->update();

        $this->assertTrue($this->input->isSuppressed(), 'hovering an interactive control suppresses game input');
    }

    public function testCheckboxToggleViaTree(): void
    {
        $root = new VBox();
        $cb = (new Checkbox('Sound', false))->size(Sizing::fixed(200, 20));
        $root->addChild($cb);

        $tree = $this->tree($root);
        $tree->performLayout();

        $changed = null;
        $cb->on('change', function (bool $val) use (&$changed) { $changed = $val; });

        // Click on checkbox
        $this->input->handleCursorPosEvent(
            $cb->getBounds()->x + 5,
            $cb->getBounds()->y + 5,
        );
        $this->input->handleMouseButtonEvent(0, 1);
        $tree->processInput();

        $this->assertTrue($cb->checked);
        $this->assertTrue($changed);
    }

    public function testToggleViaTree(): void
    {
        $root = new VBox();
        $toggle = (new Toggle('Fullscreen', false))->size(Sizing::fixed(200, 24));
        $root->addChild($toggle);

        $tree = $this->tree($root);
        $tree->performLayout();

        $this->input->handleCursorPosEvent(
            $toggle->getBounds()->x + 5,
            $toggle->getBounds()->y + 5,
        );
        $this->input->handleMouseButtonEvent(0, 1);
        $tree->processInput();

        $this->assertTrue($toggle->on);
    }

    public function testTextInputFocus(): void
    {
        $root = new VBox();
        $ti = (new TextInput('Name', '', 'Enter name'))->size(Sizing::fixed(200, 40));
        $root->addChild($ti);

        $tree = $this->tree($root);
        $tree->performLayout();

        // Click on text input to focus
        $this->input->handleCursorPosEvent(
            $ti->getBounds()->x + 5,
            $ti->getBounds()->y + 25, // below label
        );
        $this->input->handleMouseButtonEvent(0, 1);
        $tree->processInput();

        $this->assertTrue($ti->focused);
        $this->assertSame($ti, $tree->getFocusedWidget());
    }

    public function testTextInputTyping(): void
    {
        $root = new VBox();
        $ti = (new TextInput('', ''))->size(Sizing::fixed(200, 30));
        $root->addChild($ti);

        $tree = $this->tree($root);
        $tree->performLayout();

        // Focus
        $tree->setFocus($ti);

        // Type characters
        $this->input->handleCharEvent(72); // H
        $this->input->handleCharEvent(105); // i
        $tree->processInput();

        $this->assertEquals('Hi', $ti->text);
    }

    public function testInputSuppressedWhenHovering(): void
    {
        $root = new VBox();
        $btn = (new Button('Test'))->size(Sizing::fixed(100, 30));
        $root->addChild($btn);

        $tree = $this->tree($root);
        $tree->performLayout();

        $this->input->handleCursorPosEvent(
            $btn->getBounds()->x + 5,
            $btn->getBounds()->y + 5,
        );
        $tree->processInput();

        $this->assertTrue($this->input->isSuppressed());
    }

    public function testSetRootResetsState(): void
    {
        $root1 = new VBox();
        $btn = (new Button('A'))->size(Sizing::fixed(100, 30));
        $root1->addChild($btn);

        $tree = $this->tree($root1);
        $tree->performLayout();

        $root2 = new VBox();
        $tree->setRoot($root2);

        $this->assertSame($root2, $tree->getRoot());
        $this->assertNull($tree->getFocusedWidget());
    }

    public function testSetFocusUnfocusesPrevious(): void
    {
        $root = new VBox();
        $ti1 = new TextInput('A', '');
        $ti2 = new TextInput('B', '');
        $root->addChild($ti1)->addChild($ti2);

        $tree = $this->tree($root);

        $tree->setFocus($ti1);
        $this->assertTrue($ti1->focused);

        $tree->setFocus($ti2);
        $this->assertFalse($ti1->focused);
        $this->assertTrue($ti2->focused);
    }

    public function testFullUpdateCycle(): void
    {
        $root = new VBox();
        $root->addChild((new Label('Status'))->size(Sizing::fixed(100, 20)));

        $tree = $this->tree($root);

        // Should not throw — runs processInput + performLayout + draw
        $tree->update();

        $this->assertNotEmpty($this->renderer->calls);
    }
}
