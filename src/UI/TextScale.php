<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\Rendering\Accessibility\AccessibilitySettings;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextMetrics;

/**
 * The player's text-size factor as a value a UI can read and apply.
 *
 * It is separate from {@see UIScale}: UIScale follows the screen (a layout drawn
 * for 1080 lines keeps its proportions on 4K), TextScale follows the player and
 * makes text larger than designed. The two compose: a game that wraps its
 * renderer in a ScaledRenderer2D keeps doing so, and multiplies its font sizes by
 * {@see font()}; the renderer then multiplies by the screen factor.
 *
 * What it can and cannot do depends on the layout:
 *  - A fluid layout (WidgetTree, UIContext flow) measures text, so a bigger font
 *    makes buttons, rows and wrapped boxes grow: use {@see apply()} / WidgetTree::setTextScale().
 *  - A layout on a fixed design grid (every rectangle at hard-coded pixel
 *    coordinates, e.g. 1280x720) cannot reflow. Scaling its text alone makes it
 *    overflow its boxes. Such a game should read {@see $factor}, use {@see font()}
 *    and {@see measure()} for text it can give room to (tooltips, dialogs, log and
 *    description panels), and scale its boxes by {@see box()}, or cap the factor
 *    with {@see capped()} where there is no room.
 *
 * Factor 1.0 changes nothing anywhere.
 */
final class TextScale
{
    public readonly float $factor;

    public function __construct(float $factor = 1.0)
    {
        $this->factor = max(AccessibilitySettings::MIN_TEXT_SCALE, min(AccessibilitySettings::MAX_TEXT_SCALE, $factor));
    }

    public static function fromSettings(AccessibilitySettings $settings): self
    {
        return new self($settings->textScale);
    }

    public function isIdentity(): bool
    {
        return $this->factor === 1.0;
    }

    /** A font size as the player wants to read it. */
    public function font(float $size): float
    {
        return $size * $this->factor;
    }

    /** A length that must grow with the text (a box around it). */
    public function box(float $length): float
    {
        return $length * $this->factor;
    }

    /** The same factor limited to $max, for a spot of a fixed layout that has no room for more. */
    public function capped(float $max): self
    {
        return new self(min($this->factor, $max));
    }

    /**
     * Size of $text at the scaled font size, in the renderer's own units. Use this
     * instead of measuring at the design size, or the measured box is too small.
     */
    public function measure(Renderer2DInterface $renderer, string $text, float $size): TextMetrics
    {
        return $renderer->measureText($text, $this->font($size));
    }

    /** Size of word-wrapped text at the scaled font size; $breakWidth is not scaled. */
    public function measureBox(Renderer2DInterface $renderer, string $text, float $breakWidth, float $size): TextMetrics
    {
        return $renderer->measureTextBox($text, $breakWidth, $this->font($size));
    }

    /** A style whose font size follows the factor (the same object at 1.0). */
    public function apply(UIStyle $style): UIStyle
    {
        return $style->withTextScale($this->factor);
    }
}
