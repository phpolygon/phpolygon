<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\Runtime\InputInterface;
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
    private InputInterface $input;
    private UIStyle $style;

    private ?Widget $hoveredWidget = null;

    /** The hoverable-card Stack (if any) currently under the pointer. */
    private ?Stack $hoveredStack = null;
    private ?Widget $focusedWidget = null;
    private ?Widget $pressedWidget = null;
    private ?WidgetBinder $binder = null;

    private float $viewportWidth;
    private float $viewportHeight;

    public function __construct(
        Widget $root,
        Renderer2DInterface $renderer,
        InputInterface $input,
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

    /**
     * Load an editor-authored widget tree from a serialized `*.ui.json`.
     */
    public static function fromFile(
        string $path,
        Renderer2DInterface $renderer,
        InputInterface $input,
        float $viewportWidth,
        float $viewportHeight,
        ?UIStyle $style = null,
    ): self {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            throw new \RuntimeException("Invalid widget layout: {$path}");
        }
        /** @var array<string, mixed> $data */
        $root = (new WidgetSerializer)->fromArray($data);

        return new self($root, $renderer, $input, $viewportWidth, $viewportHeight, $style);
    }

    /**
     * Bind (or re-bind) this tree to a {@see WidgetContext} — the seam to game
     * logic. Resolves value bindings, wires two-way inputs and action bindings,
     * and expands repeaters. Call after the bound data may have changed.
     */
    public function bind(WidgetContext $context): void
    {
        $this->binder ??= new WidgetBinder;
        $this->binder->bind($this->root, $context);
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
        // Layout BEFORE input: hit-testing reads each widget's bounds, and a
        // host that rebuilds the tree every frame (the data-bound pattern) has
        // not laid it out yet on this instance — so processing input first would
        // hit-test against zero bounds and never register a click. Draw last so
        // it reflects the hover/pressed state this frame's input produced.
        $this->performLayout();
        $this->processInput();
        $this->draw();
    }

    /**
     * Process mouse and keyboard input through the widget tree.
     */
    public function processInput(): void
    {
        // Clear stale hover/press flags first. A host may rebuild the tree every
        // frame while the widget instances persist (cached root); a fresh tree's
        // hoveredWidget is null, so updateHover would never reset a button that
        // was hovered on an earlier frame — leaving it stuck "hovered" (filled).
        $this->clearInteractionFlags($this->root);

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

        // Suppress game input only when the pointer is over an actual control
        // (or a text field has focus) — not merely over a layout container. A
        // full-screen panel's root/box/labels would otherwise suppress the whole
        // frame's remaining input, killing immediate-mode UI drawn on top of the
        // panel (dialogs, toasts, the tutorial strip) in the same frame.
        $hoverIsControl = $this->hoveredWidget instanceof Button
            || $this->hoveredWidget instanceof Slider
            || $this->hoveredWidget instanceof Checkbox
            || $this->hoveredWidget instanceof Toggle
            || $this->hoveredWidget instanceof Dropdown
            || $this->hoveredWidget instanceof TextInput;
        if ($hoverIsControl || $this->focusedWidget !== null) {
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
        // Open dropdown lists and tooltips float above the whole tree so they
        // are never covered by a sibling drawn later (e.g. a ScrollView below
        // the filter row). Dropdowns first, tooltip last (truly top-most).
        $this->drawOpenDropdowns();
        $this->drawTooltip();
    }

    /**
     * Top-most overlay pass for expanded dropdown lists. A Dropdown draws only
     * its field inline; the open list is drawn here, after the entire tree, so
     * it floats above any following sibling instead of being painted over.
     * Draws every open dropdown (opening one does not force-close others).
     */
    private function drawOpenDropdowns(): void
    {
        $this->renderer->setFont($this->style->fontName);
        $this->forEachOpenDropdown($this->root);
    }

    private function forEachOpenDropdown(Widget $widget): void
    {
        if ($widget instanceof Dropdown && $widget->open) {
            $widget->drawOpenList($this->renderer, $this->style);
        }
        foreach ($widget->getChildren() as $child) {
            $this->forEachOpenDropdown($child);
        }
    }

    /**
     * Render the hovered widget's tooltip (if any) as a word-wrapped box near the
     * cursor, clamped to the viewport. Drawn after the tree — so it floats above
     * everything, including a ScrollView's clipped content — with the first line
     * shown as a heading.
     */
    private function drawTooltip(): void
    {
        $text = $this->hoveredWidget !== null ? $this->hoveredWidget->tooltip : '';
        if ($text === '') {
            return;
        }

        $r = $this->renderer;
        $r->setFont($this->style->fontName);

        $fontSize = 13.0;
        $pad = 8.0;
        $boxW = 270.0;
        $inner = $boxW - $pad * 2.0;

        // Split heading (first line) from the body so the heading can stand out.
        $nl = strpos($text, "\n");
        $heading = $nl === false ? $text : substr($text, 0, $nl);
        $body = $nl === false ? '' : substr($text, $nl + 1);

        $headingH = $fontSize + 6.0;
        $bodyH = $body !== '' ? $r->measureTextBox($body, $inner, $fontSize)->height : 0.0;
        $boxH = $pad * 2.0 + $headingH + $bodyH;

        $mouse = $this->input->getMousePosition();
        $tx = min($mouse->x + 16.0, $this->viewportWidth - $boxW - 8.0);
        $ty = min($mouse->y + 18.0, $this->viewportHeight - $boxH - 8.0);
        $tx = max(8.0, $tx);
        $ty = max(8.0, $ty);

        $r->drawRoundedRect($tx, $ty, $boxW, $boxH, 6.0, new Color(0.04, 0.07, 0.06, 0.98));

        $r->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
        $r->drawText($heading, $tx + $pad, $ty + $pad, $fontSize, $this->style->accentColor);
        if ($body !== '') {
            $r->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
            $r->drawTextBox($body, $tx + $pad, $ty + $pad + $headingH, $inner, $fontSize, $this->style->textColor);
        }
    }

    // ── Internals ────────────────────────────────────────────────

    /** Recursively clear transient hover/press flags before this frame's input. */
    private function clearInteractionFlags(Widget $widget): void
    {
        if ($widget instanceof Button) {
            $widget->hovered = false;
            $widget->pressed = false;
        }
        if ($widget instanceof Stack) {
            $widget->hovered = false;
        }
        foreach ($widget->getChildren() as $child) {
            $this->clearInteractionFlags($child);
        }
    }

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

        // Card-hover: tint the nearest ancestor Stack that opted in via hoverColor
        // (e.g. a rich list card whose whole-card click target is a flat overlay).
        $stack = $hit !== null ? $this->hoverableStackAncestor($hit) : null;
        if ($stack !== $this->hoveredStack) {
            if ($this->hoveredStack !== null) {
                $this->hoveredStack->hovered = false;
            }
            $this->hoveredStack = $stack;
            if ($stack !== null) {
                $stack->hovered = true;
            }
        }
    }

    /** The nearest ancestor (or self) Stack that set a hoverColor, or null. */
    private function hoverableStackAncestor(Widget $widget): ?Stack
    {
        for ($node = $widget; $node !== null; $node = $node->getParent()) {
            if ($node instanceof Stack && $node->hoverColor !== null) {
                return $node;
            }
        }

        return null;
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

        // TabView — clicking a tab in the bar switches the active child.
        if ($hit instanceof TabView) {
            $tab = $hit->tabIndexAt($mouse);
            if ($tab !== null) {
                $hit->selectTab($tab);
            }
        }

        $hit->emit('press');
    }

    private function handleRelease(?Widget $hit): void
    {
        // Release over an enabled Button = click. This intentionally does NOT
        // require the release to land on the same instance a press captured:
        // a retained host may rebuild the tree and re-expand repeater rows every
        // frame (the click's press and release then fall on different Button
        // instances), which would make a press+release pairing never fire.
        // Release-only also matches the immediate-mode UIContext and sidesteps
        // macOS's phantom synthetic PRESS events. The press frame still sets
        // Button::$pressed for visual feedback when the tree does persist.
        if ($this->pressedWidget instanceof Button) {
            $this->pressedWidget->pressed = false;
        }
        $this->pressedWidget = null;

        if ($hit instanceof Button && $hit->enabled) {
            $hit->emit('click');
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
