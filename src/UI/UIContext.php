<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Runtime\Input;

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
    private Input $input;
    private UIStyle $style;

    /** Layout cursor — auto-advances after each widget */
    private float $cursorX = 0.0;
    private float $cursorY = 0.0;

    /** Current layout region width */
    private float $regionWidth = 300.0;

    /** Tracks the hot (hovered) and active (pressed) widget IDs */
    private string $hotWidget = '';
    private string $activeWidget = '';

    /** Text input state for the currently focused text field */
    private string $focusedTextField = '';
    private string $textFieldBuffer = '';
    private int $textFieldCursor = 0;

    /** Whether any widget was hovered this frame */
    private bool $anyHovered = false;

    /** Viewport offset for letterboxing — mouse coords are adjusted by this */
    private float $viewportOffsetX = 0.0;
    private float $viewportOffsetY = 0.0;

    public function __construct(
        Renderer2DInterface $renderer,
        Input $input,
        ?UIStyle $style = null,
    ) {
        $this->renderer = $renderer;
        $this->input = $input;
        $this->style = $style ?? UIStyle::dark();
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
     * Begin a UI frame. Call before any widgets.
     */
    public function begin(float $x = 10.0, float $y = 10.0, float $width = 300.0): void
    {
        $this->cursorX = $x;
        $this->cursorY = $y;
        $this->regionWidth = $width;
        $this->anyHovered = false;
        $this->renderer->setFont($this->style->fontName);
    }

    /**
     * End a UI frame.
     */
    public function end(): void
    {
        // Note: We no longer suppress/unsuppress input here.
        // Game code can check isAnyHovered() and suppress manually if needed.
    }

    // ── Widgets ──────────────────────────────────────────────────

    /**
     * Static text label.
     */
    public function label(string $text, ?Color $color = null): void
    {
        $color ??= $this->style->textColor;
        $h = $this->style->fontSize + $this->style->padding * 2;

        $this->renderer->drawText(
            $text,
            $this->cursorX + $this->style->padding,
            $this->cursorY + $this->style->padding,
            $this->style->fontSize,
            $color,
        );

        $this->advance($h);
    }

    /**
     * Clickable button. Returns true on the frame it was clicked.
     */
    public function button(string $id, string $label): bool
    {
        $s = $this->style;
        $h = $s->fontSize + $s->padding * 2;
        $rect = new Rect($this->cursorX, $this->cursorY, $this->regionWidth, $h);

        $hovered = $this->isHovered($rect);
        $clicked = false;
        $pressing = false;

        if ($hovered) {
            $this->hotWidget = $id;
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
            // Release outside cancels active state
            if ($this->activeWidget === $id && !$this->input->isMouseButtonDown(0)) {
                $this->activeWidget = '';
            }
        }

        $bg = $pressing ? $s->activeColor
            : ($hovered ? $s->hoverColor : $s->backgroundColor);

        $this->renderer->drawRoundedRect($rect->x, $rect->y, $rect->width, $rect->height, $s->borderRadius, $bg);
        $this->renderer->drawText(
            $label,
            $rect->x + $s->padding,
            $rect->y + $s->padding,
            $s->fontSize,
            $s->textColor,
        );

        $this->advance($h);
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

        if ($hovered && $this->input->isMouseButtonDown(0)) {
            $this->activeWidget = $id;
        }
        if ($this->activeWidget === $id) {
            if ($this->input->isMouseButtonDown(0)) {
                $mouseX = $this->input->getMouseX();
                $t = max(0.0, min(1.0, ($mouseX - $barX) / $barW));
                $value = $min + ($max - $min) * $t;
            } else {
                $this->activeWidget = '';
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
        $labelH = $s->fontSize + $s->padding;
        $fieldH = $s->fontSize + $s->padding * 2;
        $totalH = $labelH + $fieldH + $s->padding;

        // Label
        $this->renderer->drawText(
            $label,
            $this->cursorX + $s->padding,
            $this->cursorY + $s->padding,
            $s->fontSize,
            $s->textColor,
        );

        $fieldX = $this->cursorX + $s->padding;
        $fieldY = $this->cursorY + $labelH + $s->padding;
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
            $this->textFieldCursor = mb_strlen($value);
            $focused = true;
        } elseif (!$hovered && $this->input->isMouseButtonReleased(0) && $focused) {
            // Click outside → unfocus
            $this->focusedTextField = '';
            $focused = false;
        }

        // Process typed characters
        if ($focused) {
            foreach ($this->input->getCharsTyped() as $char) {
                $this->textFieldBuffer = mb_substr($this->textFieldBuffer, 0, $this->textFieldCursor)
                    . $char
                    . mb_substr($this->textFieldBuffer, $this->textFieldCursor);
                $this->textFieldCursor++;
            }

            // Backspace (GLFW_KEY_BACKSPACE = 259)
            if ($this->input->isKeyPressed(259) && $this->textFieldCursor > 0) {
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

        $this->advance($totalH);
        return $value;
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
     * Vertical spacing.
     */
    public function space(float $height = 0.0): void
    {
        $this->advance($height > 0.0 ? $height : $this->style->itemSpacing * 2);
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

    private function advance(float $height): void
    {
        $this->cursorY += $height + $this->style->itemSpacing;
    }
}
