<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Runtime\InputInterface;

/**
 * Immediate-mode UI context.
 *
 * Provides widget methods that return interaction results immediately.
 * Renders via the engine's Renderer2D (NanoVG). Input is read from
 * the engine's Input class and suppressed while the UI is hovered.
 */
class UIContext
{
    private Renderer2DInterface $renderer;
    private InputInterface $input;
    private UIStyle $style;

    /** Layout cursor — auto-advances after each widget */
    private float $cursorX = 0.0;
    private float $cursorY = 0.0;

    /** Current layout region width */
    private float $regionWidth = 300.0;

    /** Current flow direction: 'vertical' or 'horizontal' */
    private string $flow = 'vertical';

    /** @var list<array{x: float, y: float, w: float, flow: string, hovered: bool}> Layout stack for nested begin/end pairs */
    private array $layoutStack = [];

    /** Tracks the active (pressed) widget ID */
    private string $activeWidget = '';

    /**
     * True once the left mouse button has been fully released since the last
     * slider activation. Prevents synthetic GLFW_PRESS events (macOS fullscreen)
     * from activating sliders — synthetic presses never produce a RELEASE event,
     * so this guard is never satisfied by them.
     */
    private bool $mouseReleasedSinceLastSlider = true;

    /** Text input state for the currently focused text field */
    private string $focusedTextField = '';
    private string $textFieldBuffer = '';
    private int $textFieldCursor = 0;

    /** ID of the currently open dropdown (empty = none open) */
    private string $openDropdown = '';

    /** @var array<string, int> Scroll offset per dropdown ID */
    private array $dropdownScrollOffset = [];

    /** Whether any widget was hovered this frame */
    private bool $anyHovered = false;

    /** Id of the leaf widget hovered this frame ('' = none). Tracked alongside
     *  $anyHovered through begin/end region nesting so callers can detect
     *  per-widget hover-enter (e.g. for hover SFX). */
    private string $hoveredWidget = '';

    /** @var list<\Closure> Deferred overlay draws (dropdowns) — rendered last for correct z-order */
    private array $deferredOverlays = [];

    /** Viewport offset for letterboxing — mouse coords are adjusted by this */
    private float $viewportOffsetX = 0.0;
    private float $viewportOffsetY = 0.0;

    /** Content scale for resolution-independent rendering (e.g. 2.0 = game renders at 2x) */
    private float $contentScale = 1.0;

    /**
     * When false, hover detection short-circuits to false. Used by callers
     * that want to render an underlying scene visually while a modal overlay
     * (e.g. a confirm dialog) absorbs input — set to false before drawing
     * the blocked layer, back to true before the modal itself draws.
     *
     * Affects hover for every widget. Since click triggers require hover,
     * clicks are blocked too. Visual states (button hover color, slider
     * grab) stay in their resting appearance.
     */
    private bool $interactive = true;

    public function __construct(
        Renderer2DInterface $renderer,
        InputInterface $input,
        ?UIStyle $style = null,
    ) {
        $this->renderer = $renderer;
        $this->input = $input;
        $this->style = $style ?? UIStyle::dark();
    }

    public function getInput(): InputInterface
    {
        return $this->input;
    }

    public function getStyle(): UIStyle
    {
        return $this->style;
    }

    public function setStyle(UIStyle $style): void
    {
        $this->style = $style;
    }

    /**
     * Begin a UI layout region.
     *
     * @param string $flow 'vertical' (default) or 'horizontal'
     */
    public function begin(float $x = 10.0, float $y = 10.0, float $width = 300.0, string $flow = 'vertical'): void
    {
        // Push current state onto stack for nesting
        $this->layoutStack[] = [
            'x' => $this->cursorX,
            'y' => $this->cursorY,
            'w' => $this->regionWidth,
            'flow' => $this->flow,
            'hovered' => $this->anyHovered,
            'hoveredWidget' => $this->hoveredWidget,
        ];

        $this->cursorX = $x;
        $this->cursorY = $y;
        $this->regionWidth = $width;
        $this->flow = $flow;
        $this->anyHovered = false;
        $this->hoveredWidget = '';
        $this->renderer->setFont($this->style->fontName);
        $this->renderer->setTextAlign(\PHPolygon\Rendering\TextAlign::LEFT | \PHPolygon\Rendering\TextAlign::TOP);
    }

    /**
     * End a UI layout region. Restores parent layout state.
     * When the outermost region ends, deferred overlays (dropdowns) are drawn
     * so they always appear on top of all other widgets.
     */
    public function end(): void
    {
        $wasHovered = $this->anyHovered;
        $childHoveredWidget = $this->hoveredWidget;

        if (count($this->layoutStack) > 0) {
            $prev = array_pop($this->layoutStack);
            $this->cursorX = $prev['x'];
            $this->cursorY = $prev['y'];
            $this->regionWidth = $prev['w'];
            $this->flow = $prev['flow'];
            // Propagate hover state to parent: a hovered child wins, otherwise
            // keep whatever the parent region already had hovered.
            $this->anyHovered = $prev['hovered'] || $wasHovered;
            $this->hoveredWidget = $wasHovered ? $childHoveredWidget : $prev['hoveredWidget'];
        }

    }

    // ── Widgets ──────────────────────────────────────────────────

    /**
     * Static text label.
     */
    public function label(string $text, ?Color $color = null): void
    {
        $color ??= $this->style->textColor;
        $h = $this->style->fontSize + $this->style->padding * 2;
        $w = mb_strlen($text) * $this->style->fontSize * 0.55 + $this->style->padding * 2;

        $this->renderer->drawText(
            $text,
            $this->cursorX + $this->style->padding,
            $this->cursorY + $this->style->padding,
            $this->style->fontSize,
            $color,
        );

        $this->advance($this->flow === 'horizontal' ? $w : $h);
    }

    /**
     * Like {@see label()} but word-wraps to the current region width and grows
     * to as many lines as the text needs — for descriptions, output and hints
     * that would otherwise overflow on a single line.
     */
    public function labelWrapped(string $text, ?Color $color = null): void
    {
        $color ??= $this->style->textColor;
        $size = $this->style->fontSize;
        $pad = $this->style->padding;
        $breakWidth = max(1.0, $this->regionWidth - $pad * 2);

        $metrics = $this->renderer->measureTextBox($text, $breakWidth, $size);
        $this->renderer->drawTextBox(
            $text,
            $this->cursorX + $pad,
            $this->cursorY + $pad,
            $breakWidth,
            $size,
            $color,
        );
        $this->advance($metrics->height + $pad * 2);
    }

    /**
     * Clickable button. Returns true on the frame it was clicked.
     *
     * @param float $width    Override width (0 = auto: regionWidth in vertical, text-fit in horizontal)
     * @param bool  $disabled When true, button is grayed out and not clickable
     */
    public function button(string $id, string $label, float $width = 0.0, bool $disabled = false): bool
    {
        $s = $this->style;
        $h = $s->fontSize + $s->padding * 2;

        // Calculate button width — auto-sized text gets horizontal padding
        if ($width > 0.0) {
            $w = $width;
        } elseif ($this->flow === 'horizontal') {
            $w = mb_strlen($label) * $s->fontSize * 0.55 + $s->buttonPaddingH * 2;
        } else {
            $w = $this->regionWidth;
        }

        // Clamp to region width to prevent overflow
        $w = min($w, $this->regionWidth);

        $rect = new Rect($this->cursorX, $this->cursorY, $w, $h);

        $hovered = !$disabled && $this->isHovered($rect);
        $clicked = false;
        $pressing = false;

        if ($hovered) {
            $this->anyHovered = true; $this->hoveredWidget = $id;

            if ($this->input->isMouseButtonDown(0)) {
                $this->activeWidget = $id;
                $pressing = true;
            }
            if ($this->input->isMouseButtonReleased(0)) {
                $clicked = true;
                $this->activeWidget = '';
            }
        } else {
            if ($this->activeWidget === $id && !$this->input->isMouseButtonDown(0)) {
                $this->activeWidget = '';
            }
        }

        // Visual state
        if ($disabled) {
            $bg = $s->disabledColor;
            $textColor = $s->disabledTextColor;
        } else {
            $bg = $pressing ? $s->activeColor
                : ($hovered ? $s->backgroundColor : $s->hoverColor);
            $textColor = $s->textColor;
        }

        $this->renderer->drawRoundedRect($rect->x, $rect->y, $w, $h, $s->borderRadius, $bg);
        $this->renderer->drawTextCentered($label, $rect->x + $w * 0.5, $rect->y + $h * 0.5, $s->fontSize, $textColor);

        $this->advance($this->flow === 'horizontal' ? $w : $h);
        return $clicked;
    }

    /**
     * Checkbox toggle. Returns the new value.
     */
    public function checkbox(string $id, string $label, bool $value): bool
    {
        $s = $this->style;
        $boxSize = $s->fontSize;
        $h = $boxSize + $s->padding * 2;
        $fullRect = new Rect($this->cursorX, $this->cursorY, $this->regionWidth, $h);

        $hovered = $this->isHovered($fullRect);
        if ($hovered) {
            $this->anyHovered = true; $this->hoveredWidget = $id;
            if ($this->input->isMouseButtonReleased(0)) {
                $value = !$value;
            }
        }

        $boxX = $this->cursorX + $s->padding;
        $boxY = $this->cursorY + $s->padding;

        $this->renderer->drawRoundedRect($boxX, $boxY, $boxSize, $boxSize, $s->borderRadius * 0.5, $s->backgroundColor);
        $this->renderer->drawRectOutline($boxX, $boxY, $boxSize, $boxSize, $s->borderColor, $s->borderWidth);

        if ($value) {
            $inner = $boxSize * 0.3;
            $this->renderer->drawRoundedRect(
                $boxX + $inner,
                $boxY + $inner,
                $boxSize - $inner * 2,
                $boxSize - $inner * 2,
                2.0,
                $s->accentColor,
            );
        }

        $this->renderer->drawText(
            $label,
            $boxX + $boxSize + $s->padding,
            $boxY,
            $s->fontSize,
            $s->textColor,
        );

        $this->advance($h);
        return $value;
    }

    /**
     * Horizontal slider. Returns the new value.
     */
    public function slider(string $id, string $label, float $value, float $min = 0.0, float $max = 1.0): float
    {
        $s = $this->style;
        $labelH = $s->fontSize + $s->padding;
        $barH = $s->fontSize * 0.5;
        $totalH = $labelH + $barH + $s->padding * 2;

        // Label + value
        $this->renderer->drawText(
            sprintf('%s: %.2f', $label, $value),
            $this->cursorX + $s->padding,
            $this->cursorY + $s->padding,
            $s->fontSize,
            $s->textColor,
        );

        $barX = $this->cursorX + $s->padding;
        $barY = $this->cursorY + $labelH + $s->padding;
        $barW = $this->regionWidth - $s->padding * 2;
        $barRect = new Rect($barX, $barY, $barW, $barH);

        $hovered = $this->isHovered($barRect);
        if ($hovered) {
            $this->anyHovered = true; $this->hoveredWidget = $id;
        }

        if (!$this->input->isMouseButtonDown(0)) {
            $this->mouseReleasedSinceLastSlider = true;
        }

        if ($hovered && $this->input->isMouseButtonPressed(0) && $this->mouseReleasedSinceLastSlider) {
            $this->activeWidget = $id;
            $this->mouseReleasedSinceLastSlider = false;
        }

        if ($this->activeWidget === $id) {
            if (!$this->input->isMouseButtonDown(0)) {
                // Released — deactivate and allow next real press
                $this->activeWidget = '';
                $this->mouseReleasedSinceLastSlider = true;
            } elseif ($hovered) {
                // Only track value while cursor is over the bar.
                // If cursor leaves (e.g. phantom stuck press), slider deactivates next branch.
                $mouseX = ($this->input->getMouseX() - $this->viewportOffsetX) / $this->contentScale;
                $t = max(0.0, min(1.0, ($mouseX - $barX) / $barW));
                $value = $min + ($max - $min) * $t;
            } else {
                // Button held but cursor left bar — treat as phantom/accidental,
                // deactivate without allowing re-activation until real release arrives.
                $this->activeWidget = '';
                $this->mouseReleasedSinceLastSlider = false;
            }
        }

        // Track background
        $this->renderer->drawRoundedRect($barX, $barY, $barW, $barH, $barH * 0.5, $s->backgroundColor);

        // Filled portion
        $t = ($max > $min) ? ($value - $min) / ($max - $min) : 0.0;
        $fillW = $barW * $t;
        if ($fillW > 1.0) {
            $this->renderer->drawRoundedRect($barX, $barY, $fillW, $barH, $barH * 0.5, $s->accentColor);
        }

        // Thumb
        $thumbR = $barH;
        $thumbX = $barX + $fillW;
        $thumbY = $barY + $barH * 0.5;
        $this->renderer->drawCircle($thumbX, $thumbY, $thumbR, $s->accentColor);

        $this->advance($totalH);
        return $value;
    }

    /**
     * Single-line text field. Returns the current text content.
     */
    public function textField(string $id, string $label, string $value): string
    {
        $s = $this->style;
        $hasLabel = $label !== '';
        $labelH = $hasLabel ? $s->fontSize + $s->padding : 0.0;
        $fieldH = $s->fontSize + $s->padding * 2;
        $totalH = $labelH + $fieldH + $s->padding;

        // Label (only if non-empty)
        if ($hasLabel) {
            $this->renderer->drawText(
                $label,
                $this->cursorX + $s->padding,
                $this->cursorY + $s->padding,
                $s->fontSize,
                $s->textColor,
            );
        }

        $fieldX = $this->cursorX + $s->padding;
        $fieldY = $this->cursorY + ($hasLabel ? $labelH + $s->padding : 0.0);
        $fieldW = $this->regionWidth - $s->padding * 2;
        $fieldRect = new Rect($fieldX, $fieldY, $fieldW, $fieldH);

        $hovered = $this->isHovered($fieldRect);
        if ($hovered) {
            $this->anyHovered = true; $this->hoveredWidget = $id;
        }

        $focused = $this->focusedTextField === $id;
        // Click to focus
        if ($hovered && $this->input->isMouseButtonReleased(0)) {
            $this->setTextFieldFocus($id);
            $this->textFieldBuffer = $value;
            $this->textFieldCursor = mb_strlen($value);
            $focused = true;
        } elseif (!$hovered && $this->input->isMouseButtonReleased(0) && $focused) {
            // Click outside → unfocus
            $this->setTextFieldFocus('');
            $focused = false;
        }

        // Process typed characters
        if ($focused) {
            foreach ($this->input->getCharsTyped() as $char) {
                // Insert at cursor position
                $this->textFieldBuffer = mb_substr($this->textFieldBuffer, 0, $this->textFieldCursor)
                    . $char
                    . mb_substr($this->textFieldBuffer, $this->textFieldCursor);
                $this->textFieldCursor++;
            }
            // Backspace (GLFW_KEY_BACKSPACE = 259)
            if ($this->input->isKeyDown(259) && $this->input->isKeyReleased(259) && $this->textFieldCursor > 0 && !$this->input->isSuppressed()) {
                $this->textFieldBuffer = mb_substr($this->textFieldBuffer, 0, $this->textFieldCursor - 1)
                    . mb_substr($this->textFieldBuffer, $this->textFieldCursor);
                $this->textFieldCursor--;
                $this->input->suppress(1, 0.1);
            }

            // On-screen-keyboard backspaces (iOS): no physical key edge, so the
            // soft keyboard's delete key is delivered as a per-frame count.
            for ($bs = $this->input->getBackspaceCount(); $bs > 0 && $this->textFieldCursor > 0; $bs--) {
                $this->textFieldBuffer = mb_substr($this->textFieldBuffer, 0, $this->textFieldCursor - 1)
                    . mb_substr($this->textFieldBuffer, $this->textFieldCursor);
                $this->textFieldCursor--;
            }

            // Delete (GLFW_KEY_DELETE = 261)
            if ($this->input->isKeyPressed(261) && $this->textFieldCursor < mb_strlen($this->textFieldBuffer)) {
                $this->textFieldBuffer = mb_substr($this->textFieldBuffer, 0, $this->textFieldCursor)
                    . mb_substr($this->textFieldBuffer, $this->textFieldCursor + 1);
            }

            // Arrow keys (LEFT = 263, RIGHT = 262)
            if ($this->input->isKeyPressed(263) && $this->textFieldCursor > 0) {
                $this->textFieldCursor--;
            }
            if ($this->input->isKeyPressed(262) && $this->textFieldCursor < mb_strlen($this->textFieldBuffer)) {
                $this->textFieldCursor++;
            }

            $value = $this->textFieldBuffer;
        }

        // Draw field background
        $borderCol = $focused ? $s->accentColor : $s->borderColor;
        $this->renderer->drawRoundedRect($fieldX, $fieldY, $fieldW, $fieldH, $s->borderRadius, $s->backgroundColor);
        $this->renderer->drawRectOutline($fieldX, $fieldY, $fieldW, $fieldH, $borderCol, $focused ? 2.0 : $s->borderWidth);
        // Draw text content
        $this->renderer->drawText(
            $value,
            $fieldX + $s->padding,
            $fieldY + $s->padding,
            $s->fontSize,
            $s->textColor,
        );

        // Blinking cursor when focused
        if ($focused) {
            $blinkOn = fmod(\PHPolygon\Runtime\Clock::now() / 1_000_000_000, 1.0) < 0.5;
            if ($blinkOn) {
                $textBeforeCursor = mb_substr($value, 0, $this->textFieldCursor);
                $metrics = $this->renderer->measureText($textBeforeCursor, $s->fontSize);
                $cursorX = $fieldX + $s->padding + $metrics->width;
                $cursorY1 = $fieldY + $s->padding;
                $cursorY2 = $cursorY1 + $s->fontSize;
                $this->renderer->drawLine(
                    new Vec2($cursorX, $cursorY1),
                    new Vec2($cursorX, $cursorY2),
                    $s->accentColor,
                    1.5,
                );
            }
        }

        $this->advance($totalH);
        return $value;
    }

    /**
     * Multi-line text input (code editor). Like {@see textField()} but renders
     * and edits across several lines: Enter inserts a newline, backspace can
     * merge lines, and the caret tracks its line + column. $rows sets the
     * visible height. Call from the render phase (reads keys once per frame).
     */
    public function textArea(string $id, string $value, int $rows = 8): string
    {
        $s = $this->style;
        $lineH = $s->fontSize * 1.35;

        $fieldX = $this->cursorX + $s->padding;
        $fieldY = $this->cursorY;
        $fieldW = $this->regionWidth - $s->padding * 2;
        $fieldH = $rows * $lineH + $s->padding * 2;
        $fieldRect = new Rect($fieldX, $fieldY, $fieldW, $fieldH);

        $hovered = $this->isHovered($fieldRect);
        if ($hovered) {
            $this->anyHovered = true; $this->hoveredWidget = $id;
        }

        $focused = $this->focusedTextField === $id;
        if ($hovered && $this->input->isMouseButtonReleased(0)) {
            $this->setTextFieldFocus($id);
            $this->textFieldBuffer = $value;
            $this->textFieldCursor = mb_strlen($value);
            $focused = true;
        } elseif (!$hovered && $this->input->isMouseButtonReleased(0) && $focused) {
            $this->setTextFieldFocus('');
            $focused = false;
        }

        if ($focused) {
            $buf = $this->textFieldBuffer;
            $cur = $this->textFieldCursor;

            foreach ($this->input->getCharsTyped() as $char) {
                $buf = mb_substr($buf, 0, $cur) . $char . mb_substr($buf, $cur);
                $cur++;
            }
            // Enter / keypad enter → newline
            if ($this->input->isKeyPressed(257) || $this->input->isKeyPressed(335)) {
                $buf = mb_substr($buf, 0, $cur) . "\n" . mb_substr($buf, $cur);
                $cur++;
            }
            // Backspace (259)
            if ($this->input->isKeyDown(259) && $this->input->isKeyReleased(259) && $cur > 0 && !$this->input->isSuppressed()) {
                $buf = mb_substr($buf, 0, $cur - 1) . mb_substr($buf, $cur);
                $cur--;
                $this->input->suppress(1, 0.1);
            }
            // On-screen-keyboard backspaces (iOS): delivered as a per-frame count.
            for ($bs = $this->input->getBackspaceCount(); $bs > 0 && $cur > 0; $bs--) {
                $buf = mb_substr($buf, 0, $cur - 1) . mb_substr($buf, $cur);
                $cur--;
            }
            // Delete (261)
            if ($this->input->isKeyPressed(261) && $cur < mb_strlen($buf)) {
                $buf = mb_substr($buf, 0, $cur) . mb_substr($buf, $cur + 1);
            }
            // Left / Right (263 / 262)
            if ($this->input->isKeyPressed(263) && $cur > 0) {
                $cur--;
            }
            if ($this->input->isKeyPressed(262) && $cur < mb_strlen($buf)) {
                $cur++;
            }
            // Up / Down (265 / 264) — keep the column on the neighbouring line.
            if ($this->input->isKeyPressed(265)) {
                $cur = $this->moveCaretVertically($buf, $cur, -1);
            }
            if ($this->input->isKeyPressed(264)) {
                $cur = $this->moveCaretVertically($buf, $cur, 1);
            }

            $this->textFieldBuffer = $buf;
            $this->textFieldCursor = $cur;
            $value = $buf;
        }

        // Background
        $borderCol = $focused ? $s->accentColor : $s->borderColor;
        $this->renderer->drawRoundedRect($fieldX, $fieldY, $fieldW, $fieldH, $s->borderRadius, $s->backgroundColor);
        $this->renderer->drawRectOutline($fieldX, $fieldY, $fieldW, $fieldH, $borderCol, $focused ? 2.0 : $s->borderWidth);

        // Lines
        $lines = explode("\n", $value);
        foreach ($lines as $i => $line) {
            $this->renderer->drawText(
                $line,
                $fieldX + $s->padding,
                $fieldY + $s->padding + $i * $lineH,
                $s->fontSize,
                $s->textColor,
            );
        }

        // Caret (line + column)
        if ($focused) {
            $blinkOn = fmod(\PHPolygon\Runtime\Clock::now() / 1_000_000_000, 1.0) < 0.5;
            if ($blinkOn) {
                $before = mb_substr($value, 0, $this->textFieldCursor);
                $beforeLines = explode("\n", $before);
                $row = count($beforeLines) - 1;
                $colText = $beforeLines[$row];
                $metrics = $this->renderer->measureText($colText, $s->fontSize);
                $caretX = $fieldX + $s->padding + $metrics->width;
                $caretY = $fieldY + $s->padding + $row * $lineH;
                $this->renderer->drawLine(
                    new Vec2($caretX, $caretY),
                    new Vec2($caretX, $caretY + $s->fontSize),
                    $s->accentColor,
                    1.5,
                );
            }
        }

        $this->advance($fieldH + $s->padding);
        return $value;
    }

    /**
     * Move a caret index up/down one line, keeping the column where possible.
     * $dir = -1 for up, +1 for down.
     */
    private function moveCaretVertically(string $buf, int $cursor, int $dir): int
    {
        $before = mb_substr($buf, 0, $cursor);
        $beforeLines = explode("\n", $before);
        $row = count($beforeLines) - 1;
        $col = mb_strlen($beforeLines[$row]);

        $allLines = explode("\n", $buf);
        $targetRow = $row + $dir;
        if ($targetRow < 0 || $targetRow >= count($allLines)) {
            return $cursor;
        }

        $offset = 0;
        for ($i = 0; $i < $targetRow; $i++) {
            $offset += mb_strlen($allLines[$i]) + 1; // +1 for the newline
        }
        return $offset + min($col, mb_strlen($allLines[$targetRow]));
    }

    /**
     * Set (or clear, with '') the focused text field, raising/dismissing the
     * on-screen keyboard on the transition. No-op for the keyboard on desktop;
     * on iOS it brings up / hides the soft keyboard so touch users can type.
     */
    private function setTextFieldFocus(string $id): void
    {
        if ($id === $this->focusedTextField) {
            return;
        }
        $this->focusedTextField = $id;
        if ($id !== '') {
            $this->input->showSoftKeyboard();
        } else {
            $this->input->hideSoftKeyboard();
        }
    }

    /**
     * Programmatically focus a text field / area (e.g. so a freshly-opened
     * editor accepts typing without a click first). Seeds the edit buffer with
     * $value and puts the caret at the end.
     */
    public function focusTextField(string $id, string $value = ''): void
    {
        $this->setTextFieldFocus($id);
        $this->textFieldBuffer = $value;
        $this->textFieldCursor = mb_strlen($value);
    }

    /**
     * Dropdown selector. Returns the (possibly new) selected index.
     *
     * Click on the closed field toggles the option list open.
     * Clicking an option selects it and closes the list.
     * Clicking outside closes the list without changing the selection.
     *
     * The open list is drawn as an overlay — only the closed button height
     * is advanced in the layout so widgets below are not pushed down.
     *
     * @param list<string> $options
     * @param float        $width   Override width (0 = regionWidth)
     */
    public function dropdown(string $id, array $options, int $selectedIndex, float $width = 0.0, int $maxVisibleItems = 0): int
    {
        $s = $this->style;
        $h = $s->fontSize + $s->padding * 2;
        $w = $width > 0.0 ? min($width, $this->regionWidth) : $this->regionWidth;

        $fieldRect = new Rect($this->cursorX, $this->cursorY, $w, $h);
        $isOpen = $this->openDropdown === $id;
        $hovered = $this->isHovered($fieldRect);

        if ($hovered) {
            $this->anyHovered = true; $this->hoveredWidget = $id;
            if ($this->input->isMouseButtonReleased(0)) {
                $this->openDropdown = $isOpen ? '' : $id;
                $isOpen = !$isOpen;
                if ($isOpen) {
                    // Reset scroll to show selected item
                    $this->dropdownScrollOffset[$id] = max(0, $selectedIndex - (int)(($maxVisibleItems > 0 ? $maxVisibleItems : count($options)) / 2));
                }
            }
        }

        // Draw the closed button
        $bg = ($hovered || $isOpen) ? $s->backgroundColor : $s->hoverColor;
        $this->renderer->drawRoundedRect($fieldRect->x, $fieldRect->y, $w, $h, $s->borderRadius, $bg);
        $this->renderer->drawRectOutline($fieldRect->x, $fieldRect->y, $w, $h, $s->borderColor, $s->borderWidth);

        $selected = $options[$selectedIndex] ?? '';
        $this->renderer->drawText($selected, $fieldRect->x + $s->padding, $fieldRect->y + $s->padding, $s->fontSize, $s->textColor);

        $arrow = $isOpen ? '▲' : '▼';
        $this->renderer->drawText($arrow, $fieldRect->right() - $s->fontSize - $s->padding, $fieldRect->y + $s->padding, $s->fontSize * 0.75, $s->textColor);

        // Process input for the open option list (inline for responsiveness)
        // but defer rendering so the overlay draws on top of all widgets.
        if ($isOpen && count($options) > 0) {
            $rowH = $h;
            $totalCount = count($options);
            $visibleCount = ($maxVisibleItems > 0 && $maxVisibleItems < $totalCount) ? $maxVisibleItems : $totalCount;
            $scrollable = $visibleCount < $totalCount;

            $scrollOffset = $this->dropdownScrollOffset[$id] ?? 0;
            $maxOffset = $totalCount - $visibleCount;
            $scrollOffset = max(0, min($scrollOffset, $maxOffset));

            $listY = $fieldRect->y + $h + 2.0;
            $listH = $rowH * $visibleCount;
            $listRect = new Rect($fieldRect->x, $listY, $w, $listH);

            $listHovered = $this->isHovered($listRect);
            if ($listHovered) {
                $this->anyHovered = true; $this->hoveredWidget = $id;
            }

            // Mouse wheel scrolling
            if ($scrollable && ($hovered || $listHovered)) {
                $scrollY = $this->input->getScrollY();
                if (abs($scrollY) > 0.001) {
                    $scrollOffset -= ($scrollY > 0.0) ? 1 : -1;
                    $scrollOffset = max(0, min($scrollOffset, $maxOffset));
                    $this->dropdownScrollOffset[$id] = $scrollOffset;
                }
            }

            // Per-item input (hover highlight state + click selection)
            $hoveredItem = -1;
            for ($vi = 0; $vi < $visibleCount; $vi++) {
                $i = $vi + $scrollOffset;
                if ($i >= $totalCount) break;
                $optRect = new Rect($fieldRect->x, $listY + $vi * $rowH, $w, $rowH);
                if ($this->isHovered($optRect)) {
                    $this->anyHovered = true; $this->hoveredWidget = $id;
                    $hoveredItem = $vi;
                    if ($this->input->isMouseButtonReleased(0)) {
                        $selectedIndex = $i;
                        $this->openDropdown = '';
                        $isOpen = false;
                    }
                }
            }

            // Click outside closes
            if (!$hovered && !$listHovered && $this->input->isMouseButtonReleased(0)) {
                $this->openDropdown = '';
            }

            // Defer all rendering to after the outermost end() call
            $drawScrollOffset = $scrollOffset;
            $drawSelectedIndex = $selectedIndex;
            $drawHoveredItem = $hoveredItem;
            $this->deferredOverlays[] = function () use (
                $s, $fieldRect, $w, $listY, $listH, $rowH,
                $visibleCount, $totalCount, $scrollable,
                $drawScrollOffset, $drawSelectedIndex, $drawHoveredItem,
                $options, $maxOffset,
            ) {
                $overlayBg = new Color(
                    max(0.0, $s->backgroundColor->r * 0.5),
                    max(0.0, $s->backgroundColor->g * 0.5),
                    max(0.0, $s->backgroundColor->b * 0.5),
                    1.0,
                );
                $this->renderer->drawRoundedRect($fieldRect->x, $listY, $w, $listH, $s->borderRadius, $overlayBg);
                $this->renderer->drawRectOutline($fieldRect->x, $listY, $w, $listH, $s->accentColor, $s->borderWidth);

                for ($vi = 0; $vi < $visibleCount; $vi++) {
                    $i = $vi + $drawScrollOffset;
                    if ($i >= $totalCount) break;
                    $optY = $listY + $vi * $rowH;

                    if ($vi === $drawHoveredItem) {
                        $this->renderer->drawRoundedRect($fieldRect->x, $optY, $w, $rowH, $s->borderRadius, $s->hoverColor);
                    } elseif ($i === $drawSelectedIndex) {
                        $this->renderer->drawRoundedRect($fieldRect->x, $optY, $w, $rowH, $s->borderRadius, $s->accentColor->withAlpha(0.25));
                    }

                    $this->renderer->drawText($options[$i], $fieldRect->x + $s->padding, $optY + $s->padding, $s->fontSize, $s->textColor);
                }

                if ($scrollable && $maxOffset > 0) {
                    $scrollbarW = 4.0;
                    $scrollbarX = $fieldRect->x + $w - $scrollbarW - 2.0;
                    $thumbH = max(20.0, $listH * ($visibleCount / (float)$totalCount));
                    $thumbY = $listY + ($listH - $thumbH) * ($drawScrollOffset / (float)$maxOffset);
                    $this->renderer->drawRoundedRect($scrollbarX, $thumbY, $scrollbarW, $thumbH, 2.0, $s->textColor->withAlpha(0.3));
                }
            };
        }

        $this->advance($h);
        return $selectedIndex;
    }

    /**
     * Progress bar (read-only). Value between 0.0 and 1.0.
     */
    public function progressBar(string $label, float $value): void
    {
        $s = $this->style;
        $barH = $s->fontSize * 0.6;
        $totalH = $s->fontSize + $barH + $s->padding * 3;

        $this->renderer->drawText(
            $label,
            $this->cursorX + $s->padding,
            $this->cursorY + $s->padding,
            $s->fontSize,
            $s->textColor,
        );

        $barX = $this->cursorX + $s->padding;
        $barY = $this->cursorY + $s->fontSize + $s->padding * 2;
        $barW = $this->regionWidth - $s->padding * 2;

        $this->renderer->drawRoundedRect($barX, $barY, $barW, $barH, $barH * 0.5, $s->backgroundColor);

        $fillW = $barW * max(0.0, min(1.0, $value));
        if ($fillW > 1.0) {
            $this->renderer->drawRoundedRect($barX, $barY, $fillW, $barH, $barH * 0.5, $s->accentColor);
        }

        $this->advance($totalH);
    }

    /**
     * Horizontal separator line.
     */
    public function separator(): void
    {
        $y = $this->cursorY + $this->style->itemSpacing;
        $this->renderer->drawRect(
            $this->cursorX,
            $y,
            $this->regionWidth,
            1.0,
            $this->style->borderColor,
        );
        $this->advance($this->style->itemSpacing * 2 + 1.0);
    }

    /**
     * Spacing in the current flow direction.
     * In vertical flow: adds vertical space. In horizontal flow: adds horizontal space.
     */
    public function space(float $size = 0.0): void
    {
        $this->advance($size > 0.0 ? $size : $this->style->itemSpacing * 2);
    }

    /**
     * Panel/window background with title bar.
     */
    public function panel(string $title, float $width, float $height): void
    {
        $s = $this->style;
        $titleH = $s->fontSize + $s->padding * 2;

        // Background
        $this->renderer->drawRoundedRect($this->cursorX, $this->cursorY, $width, $height, $s->borderRadius, $s->backgroundColor);

        // Title bar
        $this->renderer->drawRoundedRect($this->cursorX, $this->cursorY, $width, $titleH, $s->borderRadius, $s->activeColor);

        $this->renderer->drawText(
            $title,
            $this->cursorX + $s->padding,
            $this->cursorY + $s->padding,
            $s->fontSize,
            $s->textColor,
        );

        // Border
        $this->renderer->drawRectOutline($this->cursorX, $this->cursorY, $width, $height, $s->borderColor, $s->borderWidth);

        // Move cursor inside panel
        $this->cursorX += $s->padding;
        $this->cursorY += $titleH + $s->padding;
        $this->regionWidth = $width - $s->padding * 2;
    }

    /**
     * Draw deferred overlays (open dropdown lists). Call once at the end
     * of the frame, after all begin/end pairs, so overlays render on top.
     */
    public function flushOverlays(): void
    {
        if (count($this->deferredOverlays) === 0) {
            return;
        }
        $this->renderer->setFont($this->style->fontName);
        $overlays = $this->deferredOverlays;
        $this->deferredOverlays = [];
        foreach ($overlays as $draw) {
            $draw();
        }
    }

    /**
     * Returns true if any widget was hovered this frame.
     */
    public function isAnyHovered(): bool
    {
        return $this->anyHovered;
    }

    /**
     * Id of the leaf widget currently hovered, or '' when none. Lets callers
     * detect per-widget hover-enter across frames (e.g. to play a hover SFX
     * each time the cursor moves onto a different widget).
     */
    public function hoveredWidgetId(): string
    {
        return $this->hoveredWidget;
    }

    /**
     * Check if any text field currently has input focus.
     */
    public function hasTextFieldFocus(): bool
    {
        return $this->focusedTextField !== '';
    }

    /**
     * Get the current cursor Y position (useful for custom layouts).
     */
    public function getCursorY(): float
    {
        return $this->cursorY;
    }

    public function setCursorPosition(float $x, float $y): void
    {
        $this->cursorX = $x;
        $this->cursorY = $y;
    }

    /**
     * Set viewport offset for letterboxing. Mouse coordinates will be
     * adjusted by this offset when hit-testing widgets.
     */
    public function setViewportOffset(float $x, float $y): void
    {
        $this->viewportOffsetX = $x;
        $this->viewportOffsetY = $y;
    }

    /**
     * Set content scale for resolution-independent rendering.
     * When set, mouse coordinates are divided by this factor after
     * viewport-offset correction, so hit-testing works in virtual coordinates.
     */
    public function setContentScale(float $scale): void
    {
        $this->contentScale = max(0.001, $scale);
    }

    /**
     * Toggle whether widgets respond to hover/click. False makes every
     * widget rendered after the call non-interactive — hover misses, button
     * release-triggers never fire, sliders/text fields can't be grabbed.
     * Use to gate an underlying scene while a modal overlay is open:
     *
     *   $ui->setInteractive(false);
     *   drawPanelsBehindModal();
     *   $ui->setInteractive(true);
     *   ConfirmDialog::draw($engine, $w, $h);
     *
     * Widgets still render — only input is blocked. Defaults to true on
     * construction; callers must restore to true before drawing the modal
     * itself or its own widgets will be dead.
     */
    public function setInteractive(bool $on): void
    {
        $this->interactive = $on;
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    // ── Internals ────────────────────────────────────────────────

    private function isHovered(Rect $rect): bool
    {
        if (!$this->interactive) {
            return false;
        }
        $mouse = $this->input->getMousePosition();
        // Adjust mouse position for viewport offset (letterboxing) and content scale
        $adjustedMouse = new Vec2(
            ($mouse->x - $this->viewportOffsetX) / $this->contentScale,
            ($mouse->y - $this->viewportOffsetY) / $this->contentScale,
        );
        return $rect->contains($adjustedMouse);
    }

    /**
     * Advance the cursor after rendering a widget.
     * In vertical flow: moves Y down. In horizontal flow: moves X right.
     *
     * @param float $size The size to advance (height in vertical, width in horizontal).
     *                    In horizontal mode, this is treated as the widget width.
     */
    private function advance(float $size): void
    {
        if ($this->flow === 'horizontal') {
            $this->cursorX += $size + $this->style->itemSpacing;
        } else {
            $this->cursorY += $size + $this->style->itemSpacing;
        }
    }
}
