<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Runtime\Input;
use PHPolygon\UI\UIStyle;

/**
 * Root manager for the retained-mode widget system.
 *
 * Owns the root widget, drives the measure → layout → draw pipeline,
 * and dispatches input events through the widget tree.
 */
class WidgetTree
{
    private Widget $root;
    private Renderer2DInterface $renderer;
    private Input $input;
    private UIStyle $style;

    private ?Widget $hoveredWidget = null;
    private ?Widget $focusedWidget = null;
    private ?Widget $pressedWidget = null;

    private float $viewportWidth;
    private float $viewportHeight;

    public function __construct(
        Widget $root,
        Renderer2DInterface $renderer,
        Input $input,
        float $viewportWidth,
        float $viewportHeight,
        ?UIStyle $style = null,
    ) {
        $this->root = $root;
        $this->renderer = $renderer;
        $this->input = $input;
        $this->viewportWidth = $viewportWidth;
        $this->viewportHeight = $viewportHeight;
        $this->style = $style ?? UIStyle::dark();
    }

    public function getRoot(): Widget
    {
        return $this->root;
    }

    public function setRoot(Widget $root): void
    {
        $this->root = $root;
        $this->hoveredWidget = null;
        $this->focusedWidget = null;
        $this->pressedWidget = null;
    }

    public function getStyle(): UIStyle
    {
        return $this->style;
    }

    public function setStyle(UIStyle $style): void
    {
        $this->style = $style;
    }

    public function getFocusedWidget(): ?Widget
    {
        return $this->focusedWidget;
    }

    public function setFocus(?Widget $widget): void
    {
        if ($this->focusedWidget instanceof TextInput) {
            $this->focusedWidget->focused = false;
        }
        $this->focusedWidget = $widget;
        if ($widget instanceof TextInput) {
            $widget->focused = true;
            $widget->cursorPos = mb_strlen($widget->text);
        }
    }

    public function setViewportSize(float $width, float $height): void
    {
        $this->viewportWidth = $width;
        $this->viewportHeight = $height;
    }

    /**
     * Full update cycle: process input → measure → layout → draw.
     */
    public function update(): void
    {
        $this->processInput();
        $this->performLayout();
        $this->draw();
    }

    /**
     * Process mouse and keyboard input through the widget tree.
     */
    public function processInput(): void
    {
        $mouse = $this->input->getMousePosition();

        // Hit test
        $hit = $this->root->widgetAt($mouse);
        $this->updateHover($hit);

        // Mouse press
        if ($this->input->isMouseButtonPressed(0)) {
            $this->handlePress($hit, $mouse);
        }

        // Mouse release
        if ($this->input->isMouseButtonReleased(0)) {
            $this->handleRelease($hit);
        }

        // Slider dragging
        if ($this->pressedWidget instanceof Slider && $this->input->isMouseButtonDown(0)) {
            $this->pressedWidget->value = $this->pressedWidget->valueFromMouseX($mouse->x);
            $this->pressedWidget->dragging = true;
            $this->pressedWidget->emit('change', $this->pressedWidget->value);
        } elseif ($this->pressedWidget instanceof Slider && !$this->input->isMouseButtonDown(0)) {
            $this->pressedWidget->dragging = false;
            $this->pressedWidget = null;
        }

        // Keyboard input for focused text input
        if ($this->focusedWidget instanceof TextInput) {
            $this->handleTextInput($this->focusedWidget);
        }

        // Scroll handling
        if ($this->hoveredWidget !== null) {
            $scrollView = $this->findParentScrollView($this->hoveredWidget);
            if ($scrollView !== null) {
                $scrollView->handleScroll($this->input);
            }
        }

        // Suppress game input when UI is interacted with
        if ($this->hoveredWidget !== null || $this->focusedWidget !== null) {
            $this->input->suppress();
        }
    }

    /**
     * Measure and layout the widget tree.
     */
    public function performLayout(): void
    {
        $this->root->measure($this->viewportWidth, $this->viewportHeight, $this->style);
        $this->root->setBounds(new Rect(0, 0, $this->viewportWidth, $this->viewportHeight));
        $this->root->layout($this->style);
    }

    /**
     * Draw the widget tree.
     */
    public function draw(): void
    {
        $this->renderer->setFont($this->style->fontName);
        $this->root->draw($this->renderer, $this->style);
    }

    // ── Internals ────────────────────────────────────────────────

    private function updateHover(?Widget $hit): void
    {
        if ($hit !== $this->hoveredWidget) {
            if ($this->hoveredWidget instanceof Button) {
                $this->hoveredWidget->hovered = false;
                $this->hoveredWidget->emit('hoverend');
            }
            $this->hoveredWidget = $hit;
            if ($hit instanceof Button) {
                $hit->hovered = true;
                $hit->emit('hoverstart');
            }
        }
    }

    private function handlePress(?Widget $hit, Vec2 $mouse): void
    {
        // Focus management
        if ($hit instanceof TextInput) {
            $this->setFocus($hit);
        } else {
            $this->setFocus(null);
        }

        if ($hit === null || !$hit->enabled) return;

        // Button
        if ($hit instanceof Button) {
            $hit->pressed = true;
            $this->pressedWidget = $hit;
        }

        // Checkbox
        if ($hit instanceof Checkbox) {
            $hit->checked = !$hit->checked;
            $hit->emit('change', $hit->checked);
        }

        // Toggle
        if ($hit instanceof Toggle) {
            $hit->on = !$hit->on;
            $hit->emit('change', $hit->on);
        }

        // Slider
        if ($hit instanceof Slider) {
            $hit->value = $hit->valueFromMouseX($mouse->x);
            $hit->dragging = true;
            $this->pressedWidget = $hit;
            $hit->emit('change', $hit->value);
        }

        // Dropdown
        if ($hit instanceof Dropdown) {
            $hit->open = !$hit->open;
        }

        $hit->emit('press');
    }

    private function handleRelease(?Widget $hit): void
    {
        // Button click
        if ($this->pressedWidget instanceof Button) {
            $this->pressedWidget->pressed = false;
            if ($hit === $this->pressedWidget) {
                $this->pressedWidget->emit('click');
            }
            $this->pressedWidget = null;
        }

        // Dropdown option selection
        if ($hit !== null) {
            $dropdown = $this->findParentDropdown($hit);
            if ($dropdown !== null && $dropdown->open) {
                foreach ($dropdown->options as $i => $opt) {
                    $optRect = $dropdown->getOptionRect($i);
                    if ($optRect->contains($this->input->getMousePosition())) {
                        $dropdown->selectedIndex = $i;
                        $dropdown->open = false;
                        $dropdown->emit('change', $i);
                        break;
                    }
                }
            }
        }
    }

    private function handleTextInput(TextInput $ti): void
    {
        $ti->insertChars($this->input->getCharsTyped());

        // GLFW key codes
        if ($this->input->isKeyPressed(259)) $ti->backspace();  // BACKSPACE
        if ($this->input->isKeyPressed(261)) $ti->delete();     // DELETE
        if ($this->input->isKeyPressed(263)) $ti->moveCursorLeft();  // LEFT
        if ($this->input->isKeyPressed(262)) $ti->moveCursorRight(); // RIGHT

        $ti->emit('input', $ti->text);
    }

    private function findParentScrollView(Widget $widget): ?ScrollView
    {
        $current = $widget;
        while ($current !== null) {
            if ($current instanceof ScrollView) {
                return $current;
            }
            $current = $current->getParent();
        }
        return null;
    }

    private function findParentDropdown(Widget $widget): ?Dropdown
    {
        $current = $widget;
        while ($current !== null) {
            if ($current instanceof Dropdown) {
                return $current;
            }
            $current = $current->getParent();
        }
        return null;
    }
}
