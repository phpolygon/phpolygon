<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\UI\UIStyle;

class TextInput extends Widget
{
    public string $label;
    public string $text;
    public string $placeholder;
    public bool $focused = false;
    public int $cursorPos = 0;

    public function __construct(string $label = '', string $text = '', string $placeholder = '')
    {
        parent::__construct();
        $this->label = $label;
        $this->text = $text;
        $this->placeholder = $placeholder;
        $this->cursorPos = mb_strlen($text);
        $this->padding = EdgeInsets::all(6.0);
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $labelH = $this->label !== '' ? $style->fontSize + 4.0 : 0.0;
        $fieldH = $style->fontSize + $this->padding->vertical();

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width : 200.0);
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height : $labelH + $fieldH);
    }

    public function layout(UIStyle $style): void {}

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $b = $this->bounds;

        $labelH = $this->label !== '' ? $style->fontSize + 4.0 : 0.0;

        // All text here is left/top anchored; set it once (renderer align is
        // sticky global state shared with every other widget).
        $renderer->setTextAlign(TextAlign::LEFT | TextAlign::TOP);

        // Label
        if ($this->label !== '') {
            $renderer->drawText($this->label, $b->x, $b->y, $style->fontSize, $style->textColor);
        }

        // Field background
        $fieldY = $b->y + $labelH;
        $fieldH = $b->height - $labelH;
        $borderColor = $this->focused ? $style->accentColor : $style->borderColor;
        $borderW = $this->focused ? 2.0 : $style->borderWidth;

        $renderer->drawRoundedRect($b->x, $fieldY, $b->width, $fieldH, $style->borderRadius, $style->backgroundColor);
        $renderer->drawRectOutline($b->x, $fieldY, $b->width, $fieldH, $borderColor, $borderW);

        // Text or placeholder
        $displayText = $this->text !== '' ? $this->text : $this->placeholder;
        $textColor = $this->text !== '' ? $style->textColor : $style->textColor->withAlpha(0.4);

        $renderer->drawText(
            $displayText,
            $b->x + $this->padding->left,
            $fieldY + $this->padding->top,
            $style->fontSize,
            $textColor,
        );

        // Cursor (blinking would need a time param — draw solid for now)
        if ($this->focused) {
            $cursorX = $b->x + $this->padding->left + $this->cursorPos * $style->fontSize * 0.55;
            $renderer->drawRect($cursorX, $fieldY + $this->padding->top, 1.5, $style->fontSize, $style->accentColor);
        }
    }

    /**
     * Insert typed characters at cursor position.
     *
     * @param list<string> $chars
     */
    public function insertChars(array $chars): void
    {
        foreach ($chars as $char) {
            $this->text = mb_substr($this->text, 0, $this->cursorPos) . $char . mb_substr($this->text, $this->cursorPos);
            $this->cursorPos++;
        }
    }

    public function backspace(): void
    {
        if ($this->cursorPos > 0) {
            $this->text = mb_substr($this->text, 0, $this->cursorPos - 1) . mb_substr($this->text, $this->cursorPos);
            $this->cursorPos--;
        }
    }

    public function delete(): void
    {
        if ($this->cursorPos < mb_strlen($this->text)) {
            $this->text = mb_substr($this->text, 0, $this->cursorPos) . mb_substr($this->text, $this->cursorPos + 1);
        }
    }

    public function moveCursorLeft(): void
    {
        if ($this->cursorPos > 0) $this->cursorPos--;
    }

    public function moveCursorRight(): void
    {
        if ($this->cursorPos < mb_strlen($this->text)) $this->cursorPos++;
    }
}
