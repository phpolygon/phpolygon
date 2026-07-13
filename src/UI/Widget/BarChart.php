<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\UI\UIStyle;

/**
 * A declarative, data-bound bar chart.
 *
 * The panel supplies the numbers through the view-model; the widget only draws
 * them. It stays declarative: `series` (and the optional second `series2`) are
 * bound to collections of rows via `{"$bind": <path>}`, and the widget scales
 * the bars against {@see $maxValue} (or the auto-detected data maximum) and
 * renders x-axis labels below the plot.
 *
 * Each row of a series is one of:
 *   - `['label' => 'Jan', 'value' => 42.0]`
 *   - `['Jan', 42.0]`            (positional)
 *   - `42.0`                     (bare number; label falls back to the index)
 * or the object equivalent (public `label`/`value` properties).
 *
 * When {@see $series2} is non-empty the bars are grouped: each x-axis slot draws
 * one bar per series side by side (e.g. revenue vs. expenses). The primary
 * series uses the style accent colour; the second series uses {@see $barColor2}
 * (defaults to a muted grey) so a panel gets a sensible dual-series look with no
 * extra configuration.
 *
 * JSON usage:
 * ```json
 * {
 *   "_widget": "PHPolygon\\UI\\Widget\\BarChart",
 *   "series":  {"$bind": "monthly"},
 *   "series2": {"$bind": "monthlyExpenses"},
 *   "maxValue": null,
 *   "sizing":  {"fillWidth": true, "height": 180}
 * }
 * ```
 */
class BarChart extends Widget
{
    /**
     * Primary data series. A list of rows; see the class docblock for the
     * accepted row shapes. Bind via `{"series": {"$bind": "monthly"}}`.
     *
     * @var list<mixed>
     */
    public array $series = [];

    /**
     * Optional second data series for grouped/dual bars. Empty = single-series.
     *
     * @var list<mixed>
     */
    public array $series2 = [];

    /**
     * Fixed value that maps to full bar height. When null the widget auto-scales
     * from the largest value across both series.
     */
    public ?float $maxValue = null;

    /** Whether to draw the x-axis label row beneath the plot. */
    public bool $showLabels = true;

    /** Fill colour for the second series (the first uses the style accent). */
    public Color $barColor2;

    public function __construct()
    {
        parent::__construct();
        $this->padding = EdgeInsets::all(6.0);
        $this->barColor2 = new Color(0.55, 0.57, 0.62, 1.0);
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width : 240.0 + $this->padding->horizontal());
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height : 160.0 + $this->padding->vertical());
    }

    public function layout(UIStyle $style): void {}

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $content = $this->contentRect();

        $labelH = $this->showLabels ? $style->fontSize + 4.0 : 0.0;
        $plotX = $content->x;
        $plotY = $content->y;
        $plotW = $content->width;
        $plotH = max(0.0, $content->height - $labelH);

        $count = max(count($this->series), count($this->series2));
        if ($count === 0 || $plotW <= 0.0 || $plotH <= 0.0) {
            return;
        }

        $max = $this->effectiveMaxValue();
        if ($max <= 0.0) {
            return; // nothing to scale against — leave the plot empty
        }

        $grouped = $this->series2 !== [];
        $barsPerSlot = $grouped ? 2 : 1;

        // Each x-slot gets an even share of the plot width; 20% of a slot is gap.
        $slotW = $plotW / $count;
        $gap = $slotW * 0.2;
        $innerW = max(1.0, $slotW - $gap);
        $barW = $innerW / $barsPerSlot;

        for ($i = 0; $i < $count; $i++) {
            $slotX = $plotX + $i * $slotW + $gap * 0.5;

            $this->drawBar($renderer, $this->valueAt($this->series, $i), $max, $slotX, $plotY, $barW, $plotH, $style->accentColor);
            if ($grouped) {
                $this->drawBar($renderer, $this->valueAt($this->series2, $i), $max, $slotX + $barW, $plotY, $barW, $plotH, $this->barColor2);
            }

            if ($this->showLabels) {
                $label = $this->labelAt($this->series, $i, (string) $i);
                $renderer->drawTextCentered(
                    $label,
                    $slotX + $innerW * 0.5,
                    $plotY + $plotH + $labelH * 0.5,
                    $style->fontSize * 0.8,
                    $style->textColor,
                );
            }
        }
    }

    /**
     * Baseline (y = plotY + plotH) up scaled bars, so growth reads upward.
     */
    private function drawBar(
        Renderer2DInterface $renderer,
        float $value,
        float $max,
        float $x,
        float $plotY,
        float $barW,
        float $plotH,
        Color $color,
    ): void {
        $ratio = max(0.0, min(1.0, $value / $max));
        $barH = $plotH * $ratio;
        if ($barH <= 0.0) {
            return;
        }
        $renderer->drawRect($x, $plotY + $plotH - $barH, $barW, $barH, $color);
    }

    /** Largest value across both series, or the explicit override. */
    private function effectiveMaxValue(): float
    {
        if ($this->maxValue !== null) {
            return $this->maxValue;
        }
        $max = 0.0;
        foreach ([$this->series, $this->series2] as $s) {
            for ($i = 0, $n = count($s); $i < $n; $i++) {
                $v = $this->valueAt($s, $i);
                if ($v > $max) {
                    $max = $v;
                }
            }
        }

        return $max;
    }

    /**
     * @param list<mixed> $series
     */
    private function valueAt(array $series, int $index): float
    {
        if (! array_key_exists($index, $series)) {
            return 0.0;
        }
        $row = $series[$index];

        if (is_int($row) || is_float($row)) {
            return (float) $row;
        }
        if (is_array($row)) {
            $v = $row['value'] ?? ($row[1] ?? null);

            return is_numeric($v) ? (float) $v : 0.0;
        }
        if (is_object($row) && isset($row->value) && is_numeric($row->value)) {
            return (float) $row->value;
        }

        return 0.0;
    }

    /**
     * @param list<mixed> $series
     */
    private function labelAt(array $series, int $index, string $fallback): string
    {
        $row = $series[$index] ?? null;
        if (is_array($row)) {
            $l = $row['label'] ?? ($row[0] ?? null);
            if (is_scalar($l)) {
                return (string) $l;
            }
        }
        if (is_object($row) && isset($row->label) && is_scalar($row->label)) {
            return (string) $row->label;
        }

        return $fallback;
    }

    /** Suppress the default hit-test — a chart is purely presentational. */
    public function hitTest(Vec2 $point): bool
    {
        return false;
    }
}
