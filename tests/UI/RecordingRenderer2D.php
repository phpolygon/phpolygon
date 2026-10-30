<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\TextMetrics;

/**
 * NullRenderer2D that remembers what a widget drew and measured. Text is
 * 0.6 * size wide per byte; a text box line is 1.2 * size high.
 */
final class RecordingRenderer2D extends NullRenderer2D
{
    /** @var list<array{text: string, x: float, y: float, break: float, size: float, color: Color, font: string}> */
    public array $boxes = [];

    /** @var list<array{from: Vec2, to: Vec2, color: Color}> */
    public array $lines = [];

    /** @var list<array{x: float, y: float, w: float, h: float, color: Color}> */
    public array $fills = [];

    public string $font = '';
    public int $measures = 0;

    public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void
    {
        $this->boxes[] = ['text' => $text, 'x' => $x, 'y' => $y, 'break' => $breakWidth, 'size' => $size, 'color' => $color, 'font' => $this->font];
    }

    public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void
    {
        $this->lines[] = ['from' => $from, 'to' => $to, 'color' => $color];
    }

    public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void
    {
        $this->fills[] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'color' => $color];
    }

    public function setFont(string $name): void
    {
        $this->font = $name;
    }

    public function measureText(string $text, float $size): TextMetrics
    {
        $this->measures++;

        return parent::measureText($text, $size);
    }

    public function measureTextBox(string $text, float $breakWidth, float $size): TextMetrics
    {
        $this->measures++;

        return parent::measureTextBox($text, $breakWidth, $size);
    }

    /** @return list<string> the drawn texts, in order */
    public function texts(): array
    {
        return array_map(static fn(array $b): string => $b['text'], $this->boxes);
    }

    /** @return array{text: string, x: float, y: float, break: float, size: float, color: Color, font: string} */
    public function box(string $text): array
    {
        foreach ($this->boxes as $box) {
            if ($box['text'] === $text) {
                return $box;
            }
        }
        throw new \RuntimeException("not drawn: $text");
    }

    public function reset(): void
    {
        $this->boxes = [];
        $this->lines = [];
        $this->fills = [];
    }
}
