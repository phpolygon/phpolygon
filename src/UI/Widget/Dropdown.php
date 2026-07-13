<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\UI\UIStyle;

class Dropdown extends Widget
{
    public string $label;
    /** @var list<string> */
    public array $options;
    public int $selectedIndex;
    public bool $open = false;

    /**
     * @param list<string> $options
     */
    public function __construct(string $label = '', array $options = [], int $selectedIndex = 0)
    {
        parent::__construct();
        $this->label = $label;
        $this->options = $options;
        $this->selectedIndex = $selectedIndex;
        $this->padding = EdgeInsets::symmetric(horizontal: 8.0, vertical: 6.0);
    }

    public function getSelectedValue(): ?string
    {
        return $this->options[$this->selectedIndex] ?? null;
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $labelH = $this->label !== '' ? $style->fontSize + 4.0 : 0.0;
        $rowH = $style->fontSize + $this->padding->vertical();

        $maxTextW = 0.0;
        foreach ($this->options as $opt) {
            $w = mb_strlen($opt) * $style->fontSize * 0.55;
            if ($w > $maxTextW) $maxTextW = $w;
        }

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width
                : $maxTextW + $this->padding->horizontal() + 20.0); // 20px for arrow
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height : $labelH + $rowH);
    }

    public function layout(UIStyle $style): void {}

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $b = $this->bounds;

        $labelH = $this->label !== '' ? $style->fontSize + 4.0 : 0.0;
        $fieldY = $b->y + $labelH;
        $fieldH = $b->height - $labelH;

        // All dropdown text is left/top anchored; set it once (the renderer's
        // align is sticky global state shared with every other widget).
        $renderer->setTextAlign(TextAlign::LEFT | TextAlign::TOP);

        // Label
        if ($this->label !== '') {
            $renderer->drawText($this->label, $b->x, $b->y, $style->fontSize, $style->textColor);
        }

        // Main box
        $renderer->drawRoundedRect($b->x, $fieldY, $b->width, $fieldH, $style->borderRadius, $style->backgroundColor);
        $renderer->drawRectOutline($b->x, $fieldY, $b->width, $fieldH, $style->borderColor, $style->borderWidth);

        // Selected text
        $selected = $this->getSelectedValue() ?? '';
        $renderer->drawText($selected, $b->x + $this->padding->left, $fieldY + $this->padding->top, $style->fontSize, $style->textColor);

        // Arrow indicator
        $arrowX = $b->right() - 14.0;
        $arrowY = $fieldY + $fieldH * 0.5 - $style->fontSize * 0.25;
        $renderer->drawText($this->open ? '^' : 'v', $arrowX, $arrowY, $style->fontSize * 0.7, $style->textColor);

        // Dropdown list (when open)
        if ($this->open) {
            $listY = $fieldY + $fieldH + 2.0;
            $rowH = $style->fontSize + $this->padding->vertical();
            $listH = $rowH * count($this->options);

            $renderer->drawRoundedRect($b->x, $listY, $b->width, $listH, $style->borderRadius, $style->backgroundColor);
            $renderer->drawRectOutline($b->x, $listY, $b->width, $listH, $style->borderColor, $style->borderWidth);

            foreach ($this->options as $i => $opt) {
                $optY = $listY + $i * $rowH;
                if ($i === $this->selectedIndex) {
                    $renderer->drawRect($b->x + 1.0, $optY, $b->width - 2.0, $rowH, $style->accentColor->withAlpha(0.3));
                }
                $renderer->drawText($opt, $b->x + $this->padding->left, $optY + $this->padding->top, $style->fontSize, $style->textColor);
            }
        }
    }

    /**
     * Get the option rect at a given index (for hit testing the dropdown list).
     */
    public function getOptionRect(int $index): \PHPolygon\Math\Rect
    {
        $style = $this->styleOverride ?? UIStyle::dark();
        $labelH = $this->label !== '' ? $style->fontSize + 4.0 : 0.0;
        $fieldH = $this->bounds->height - $labelH;
        $rowH = $style->fontSize + $this->padding->vertical();
        $listY = $this->bounds->y + $labelH + $fieldH + 2.0;

        return new \PHPolygon\Math\Rect(
            $this->bounds->x,
            $listY + $index * $rowH,
            $this->bounds->width,
            $rowH,
        );
    }
}
