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

        // Re-adopt text-field focus from the persistent widget state. The same
        // rebuild-every-frame host starts each tree with focusedWidget === null,
        // but a focused TextInput carries ->focused on the cached instance. Without
        // this, keyboard input (below) and the focus-based input suppression only
        // fire on the exact frame the field is clicked — so typing appears dead and
        // game hotkeys leak through while a field is focused.
        if ($this->focusedWidget === null) {
            $this->focusedWidget = $this->findFocusedTextInput($this->root);
        }

        $mouse = $this->input->getMousePosition();

        // Clamp every open dropdown's floating list to the viewport so a long
        // option set becomes a scrollable window rather than running off the
        // bottom of the screen. Must run before hit-testing the list below, since
        // listBounds() depends on the clamp.
        $this->clampOpenDropdownLists($this->root);

        // An open dropdown's option list floats above the tree in BOTH draw and
        // input: a point inside the expanded list belongs to the dropdown, not
        // whatever sibling (a ScrollView, a card list) happens to sit beneath it.
        // Resolve that first, otherwise widgetAt() would hand the click to the
        // widget underneath and the list could be neither clicked nor scrolled.
        $listDropdown = $this->openDropdownListAt($mouse);

        // Hit test
        $hit = $listDropdown ?? $this->root->widgetAt($mouse);
        $this->updateHover($hit);

        if ($listDropdown !== null) {
            // Release selects the option under the cursor. Press is swallowed so
            // it neither toggles the list shut (the field's job) nor reaches the
            // sibling below. The wheel scrolls the floating list (and is consumed
            // so it doesn't also scroll a ScrollView beneath it).
            if ($this->input->isMouseButtonReleased(0)) {
                $this->selectDropdownOption($listDropdown, $mouse);
            }
            $scrollDelta = $this->input->getScrollY();
            if (abs($scrollDelta) > 0.01) {
                $listDropdown->scrollListBy(-$scrollDelta * 30.0);
            }
            $this->input->suppress();
            return;
        }

        // A press outside every open dropdown (its field or its list) dismisses
        // it — the field being clicked is exempted so it can still toggle.
        if ($this->input->isMouseButtonPressed(0)) {
            $this->closeOpenDropdownsExcept($hit instanceof Dropdown ? $hit : null);
        }

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

    /**
     * Find the focused TextInput in the tree, if any. Focus lives on the widget
     * instance (->focused), which survives a host that rebuilds the tree every
     * frame around a cached root; this re-derives the tree's focusedWidget from
     * that persistent state. Returns the first match in pre-order.
     */
    private function findFocusedTextInput(Widget $widget): ?TextInput
    {
        if ($widget instanceof TextInput && $widget->focused) {
            return $widget;
        }
        foreach ($widget->getChildren() as $child) {
            $found = $this->findFocusedTextInput($child);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
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
            if ($hit->open) {
                // Reopen at the top of the list, not wherever it was last scrolled.
                $hit->listScrollY = 0.0;
            }
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

    /**
     * Clamp every open dropdown's floating list to the viewport height so a long
     * option set scrolls within a bounded window instead of overflowing offscreen.
     */
    private function clampOpenDropdownLists(Widget $widget): void
    {
        if ($widget instanceof Dropdown && $widget->open) {
            $widget->clampListToViewport($this->viewportHeight);
        }
        foreach ($widget->getChildren() as $child) {
            $this->clampOpenDropdownLists($child);
        }
    }

    /**
     * The open dropdown whose floating option list contains $mouse, or null.
     * Descends children-first so a deeper dropdown wins over an ancestor.
     */
    private function openDropdownListAt(Vec2 $mouse): ?Dropdown
    {
        return $this->findOpenDropdownList($this->root, $mouse);
    }

    private function findOpenDropdownList(Widget $widget, Vec2 $mouse): ?Dropdown
    {
        foreach ($widget->getChildren() as $child) {
            $found = $this->findOpenDropdownList($child, $mouse);
            if ($found !== null) {
                return $found;
            }
        }
        if ($widget instanceof Dropdown && $widget->open) {
            $list = $widget->listBounds();
            if ($list !== null && $list->contains($mouse)) {
                return $widget;
            }
        }
        return null;
    }

    /** Pick the option under the cursor in an open dropdown's floating list. */
    private function selectDropdownOption(Dropdown $dropdown, Vec2 $mouse): void
    {
        foreach ($dropdown->options as $i => $opt) {
            if ($dropdown->getOptionRect($i)->contains($mouse)) {
                $dropdown->selectedIndex = $i;
                $dropdown->open = false;
                $dropdown->emit('change', $i);
                return;
            }
        }
    }

    /** Close every open dropdown except $keep (the one being clicked). */
    private function closeOpenDropdownsExcept(?Dropdown $keep): void
    {
        $this->closeDropdowns($this->root, $keep);
    }

    private function closeDropdowns(Widget $widget, ?Dropdown $keep): void
    {
        if ($widget instanceof Dropdown && $widget !== $keep) {
            $widget->open = false;
        }
        foreach ($widget->getChildren() as $child) {
            $this->closeDropdowns($child, $keep);
        }
    }
}
