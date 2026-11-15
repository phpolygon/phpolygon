<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\Rendering\Color;

class UIStyle
{
    public function __construct(
        public Color $textColor = new Color(1.0, 1.0, 1.0, 1.0),
        public Color $backgroundColor = new Color(0.15, 0.15, 0.15, 0.9),
        public Color $borderColor = new Color(0.4, 0.4, 0.4, 1.0),
        public Color $hoverColor = new Color(0.25, 0.25, 0.25, 0.9),
        public Color $activeColor = new Color(0.35, 0.35, 0.35, 0.9),
        public Color $accentColor = new Color(0.26, 0.59, 0.98, 1.0),
        public Color $disabledColor = new Color(0.3, 0.3, 0.3, 0.5),
        public Color $disabledTextColor = new Color(0.5, 0.5, 0.5, 0.4),
        public float $fontSize = 16.0,
        public float $padding = 6.0,
        public float $buttonPaddingH = 16.0,
        public float $borderRadius = 4.0,
        public float $borderWidth = 1.0,
        public float $itemSpacing = 4.0,
        public string $fontName = 'default',
        /** Line height of wrapped widget text, as a factor of the font size. */
        public float $lineHeight = 1.3,
    ) {}

    /**
     * This style with the font size multiplied by the player's text-size factor
     * (see AccessibilitySettings::$textScale). Factor 1.0 returns the same
     * object, so a tree that never changes the factor lays out exactly as before.
     * Only the font size changes: paddings and borders stay, so widgets that size
     * themselves from their text grow and fixed-size ones do not.
     */
    public function withTextScale(float $factor): self
    {
        if ($factor === 1.0) {
            return $this;
        }
        $scaled = clone $this;
        $scaled->fontSize = $this->fontSize * $factor;

        return $scaled;
    }

    public static function dark(): self
    {
        return new self();
    }

    public static function light(): self
    {
        return new self(
            textColor: new Color(0.1, 0.1, 0.1, 1.0),
            backgroundColor: new Color(0.92, 0.92, 0.92, 0.95),
            borderColor: new Color(0.7, 0.7, 0.7, 1.0),
            hoverColor: new Color(0.85, 0.85, 0.85, 0.95),
            activeColor: new Color(0.78, 0.78, 0.78, 0.95),
            accentColor: new Color(0.2, 0.47, 0.84, 1.0),
        );
    }
}
