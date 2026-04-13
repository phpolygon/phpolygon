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

    /** Viewport offset for letterboxing — mouse coords are adjusted by this */
    private float $viewportOffsetX = 0.0;
    private float $viewportOffsetY = 0.0;

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
        ];

        $this->cursorX = $x;
        $this->cursorY = $y;
        $this->regionWidth = $width;
        $this->flow = $flow;
        $this->anyHovered = false;
        $this->renderer->setFont($this->style->fontName);
        $this->renderer->setTextAlign(\PHPolygon\Rendering\TextAlign::LEFT | \PHPolygon\Rendering\TextAlign::TOP);
    }

    /**
     * End a UI layout region. Restores parent layout state.
     */
    public function end(): void
    {
        $wasHovered = $this->anyHovered;

        if (count($this->layoutStack) > 0) {
            $prev = array_pop($this->layoutStack);
            $this->cursorX = $prev['x'];
            $this->cursorY = $prev['y'];
            $this->regionWidth = $prev['w'];
            $this->flow = $prev['flow'];
            // Propagate hover state to parent
            $this->anyHovered = $prev['hovered'] || $wasHovered;
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
            $this->anyHovered = true;

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
            $this->anyHovered = true;
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
            $this->anyHovered = true;
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
                $mouseX = $this->input->getMouseX() - $this->viewportOffsetX;
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
            $this->anyHovered = true;
        }

        $focused = $this->focusedTextField === $id;
        // Click to focus
        if ($hovered && $this->input->isMouseButtonReleased(0)) {
            $this->focusedTextField = $id;
            $this->textFieldBuffer = $value;
            $this->textFieldCursor = mb_strlen($value) + 1;
            $focused = true;
        } elseif (!$hovered && $this->input->isMouseButtonReleased(0) && $focused) {
            // Click outside → unfocus
            $this->focusedTextField = '';
            $focused = false;
        }

        // Process typed characters
        if ($focused) {
            $chars = $this->input->getCharsTyped();
            foreach ($this->input->getCharsTyped() as $char) {
                $this->textFieldBuffer .= array_pop($chars);
                $this->textFieldCursor++;
            }
            // Backspace (GLFW_KEY_BACKSPACE = 259)
            if ($this->input->isKeyDown(259) && $this->input->isKeyReleased(259) && $this->textFieldCursor > 0 && !$this->input->isSuppressed()) {
                $this->textFieldBuffer = mb_substr($this->textFieldBuffer, 0, $this->textFieldCursor - 1)
                    . mb_substr($this->textFieldBuffer, $this->textFieldCursor);
                $this->textFieldCursor--;
                $this->input->suppress(1, 0.1);
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

        $this->advance($totalH);
        return $value;
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
            $this->anyHovered = true;
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

        // Draw the open option list (overlay — does not affect layout cursor)
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
                $this->anyHovered = true;
            }

            // Mouse wheel scrolling — accept on button or list area
            if ($scrollable && ($hovered || $listHovered)) {
                $scrollY = $this->input->getScrollY();
                if (abs($scrollY) > 0.001) {
                    $scrollOffset -= ($scrollY > 0.0) ? 1 : -1;
                    $scrollOffset = max(0, min($scrollOffset, $maxOffset));
                    $this->dropdownScrollOffset[$id] = $scrollOffset;
                }
            }

            $this->renderer->drawRoundedRect($listRect->x, $listRect->y, $w, $listH, $s->borderRadius, $s->backgroundColor);
            $this->renderer->drawRectOutline($listRect->x, $listRect->y, $w, $listH, $s->borderColor, $s->borderWidth);

            for ($vi = 0; $vi < $visibleCount; $vi++) {
                $i = $vi + $scrollOffset;
                if ($i >= $totalCount) break;
                $opt = $options[$i];
                $optRect = new Rect($fieldRect->x, $listY + $vi * $rowH, $w, $rowH);
                $optHovered = $this->isHovered($optRect);

                if ($optHovered) {
                    $this->anyHovered = true;
                    $this->renderer->drawRoundedRect($optRect->x, $optRect->y, $w, $rowH, $s->borderRadius, $s->hoverColor);
                    if ($this->input->isMouseButtonReleased(0)) {
                        $selectedIndex = $i;
                        $this->openDropdown = '';
                        $isOpen = false;
                    }
                } elseif ($i === $selectedIndex) {
                    $this->renderer->drawRoundedRect($optRect->x, $optRect->y, $w, $rowH, $s->borderRadius, $s->accentColor->withAlpha(0.25));
                }

                $this->renderer->drawText($opt, $optRect->x + $s->padding, $optRect->y + $s->padding, $s->fontSize, $s->textColor);
            }

            // Scrollbar indicator
            if ($scrollable) {
                $scrollbarW = 4.0;
                $scrollbarX = $fieldRect->x + $w - $scrollbarW - 2.0;
                $thumbH = max(20.0, $listH * ($visibleCount / (float)$totalCount));
                $thumbY = $listY + ($listH - $thumbH) * ($scrollOffset / (float)$maxOffset);
                $this->renderer->drawRoundedRect($scrollbarX, $thumbY, $scrollbarW, $thumbH, 2.0, $s->textColor->withAlpha(0.3));
            }

            // Click outside closes without changing selection
            if (!$hovered && !$listHovered && $this->input->isMouseButtonReleased(0)) {
                $this->openDropdown = '';
            }
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
     * Returns true if any widget was hovered this frame.
     */
    public function isAnyHovered(): bool
    {
        return $this->anyHovered;
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

    // ── Internals ────────────────────────────────────────────────

    private function isHovered(Rect $rect): bool
    {
        $mouse = $this->input->getMousePosition();
        // Adjust mouse position for viewport offset (letterboxing)
        $adjustedMouse = new Vec2(
            $mouse->x - $this->viewportOffsetX,
            $mouse->y - $this->viewportOffsetY,
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
