<?php

declare(strict_types=1);

namespace PHPolygon\UI;

/**
 * Where the parts of a {@see DefinitionRow} go at a given width.
 *
 * The head line carries term, note, tag and the right-aligned value. When that
 * does not fit, parts give way in this order, decided by measuring and never
 * by language or a breakpoint:
 *
 *   1. the value moves below the body,
 *   2. note and tag move to a line of their own under the term,
 *   3. a term wider than the row wraps.
 *
 * Text is never cut. A part that does not fit on its own line wraps
 * ({@see WrappedText}), and the row grows.
 *
 * Pure: measuring comes from callbacks, so the result agrees with whatever
 * renderer and font fallback chain will draw the text. All positions are
 * relative to the row's top left corner.
 */
final readonly class DefinitionRowLayout
{
    /** Gap between parts of one line. */
    public const float GAP = 8.0;

    /** Height of the head line, as a factor of the body size. */
    public const float HEAD_LINE = 1.4;

    /**
     * @param list<array{part: string, text: string, x: float, y: float, width: float, size: float, font: string}> $items
     *        $part is term, note, tag, valueLabel, value or body; $width the widest line
     * @param float $height the whole row, its gap below included
     */
    private function __construct(
        public array $items,
        public float $height,
    ) {}

    /**
     * @param string $baseFont font of the parts that do not name one
     * @param callable(string, string, float): float $textWidth width of (text, font, size)
     * @param callable(string, float): float $linePitch line distance of (font, size)
     */
    public static function compute(DefinitionRow $row, float $width, string $baseFont, callable $textWidth, callable $linePitch): self
    {
        $width = max(1.0, $width);
        $termFont = $row->termFont !== '' ? $row->termFont : $baseFont;
        $valueFont = $row->valueFont !== '' ? $row->valueFont : $baseFont;
        $tagFont = $row->tagFont !== '' ? $row->tagFont : $baseFont;
        $body = $row->bodySize;
        $small = $row->noteSize;

        $measure = static fn(string $text, string $font, float $size): float
            => $text === '' ? 0.0 : $textWidth($text, $font, $size);
        $termW = $measure($row->term, $termFont, $body);
        $noteW = $measure($row->note, $baseFont, $small);
        $tagW = $measure($row->tag, $tagFont, $small);
        $labelW = $measure($row->valueLabel, $baseFont, $small);
        $valueW = $measure($row->value, $valueFont, $body);

        $metaW = self::joined($noteW, $tagW);
        $valueTotal = self::joined($labelW, $valueW);
        $headW = self::joined($termW, $metaW);
        $metaInline = $headW <= $width;
        $valueInline = $valueTotal > 0.0 && self::joined($headW, $valueTotal) <= $width;

        $items = [];
        // Places one part, its lines wrapped to $room; returns its height.
        $place = static function (string $part, string $text, float $x, float $y, float $room, string $font, float $size)
            use (&$items, $textWidth, $linePitch): float {
            if ($text === '') {
                return 0.0;
            }
            $wrapped = WrappedText::fit(
                $text,
                $room,
                static fn(string $line): float => $textWidth($line, $font, $size),
                $linePitch($font, $size),
            );
            $items[] = ['part' => $part, 'text' => $wrapped->text, 'x' => $x, 'y' => $y, 'width' => $wrapped->width, 'size' => $size, 'font' => $font];

            return $wrapped->height;
        };
        // One-line parts of a line of height $h share a centre, so sizes line up.
        $centred = static fn(float $top, float $h, string $font, float $size): float
            => $top + ($h - $linePitch($font, $size)) * 0.5;
        // A line of small parts next to each other, where they are known to fit.
        $line = static function (float $y, float $h, array $parts) use ($place, $centred): void {
            /** @var list<array{string, string, float, float, string, float}> $parts */
            foreach ($parts as [$part, $text, $x, $w, $font, $size]) {
                $place($part, $text, $x, $centred($y, $h, $font, $size), $w + 1.0, $font, $size);
            }
        };

        $y = 0.0;
        $headLine = round($body * self::HEAD_LINE);
        if ($termW > 0.0 || ($metaInline && $metaW > 0.0) || $valueInline) {
            $headH = $headLine;
            if ($termW > $width) {
                $headH = max($headLine, $place('term', $row->term, 0.0, 0.0, $width, $termFont, $body));
            } else {
                $line(0.0, $headLine, [['term', $row->term, 0.0, $termW, $termFont, $body]]);
            }
            if ($metaInline) {
                $noteX = $termW > 0.0 ? $termW + self::GAP : 0.0;
                $line(0.0, $headLine, [
                    ['note', $row->note, $noteX, $noteW, $baseFont, $small],
                    ['tag', $row->tag, $noteW > 0.0 ? $noteX + $noteW + self::GAP : $noteX, $tagW, $tagFont, $small],
                ]);
            }
            if ($valueInline) {
                $labelX = $width - $valueTotal;
                $line(0.0, $headLine, [
                    ['valueLabel', $row->valueLabel, $labelX, $labelW, $baseFont, $small],
                    ['value', $row->value, $width - $valueW, $valueW, $valueFont, $body],
                ]);
            }
            $y = $headH;
        }

        if (!$metaInline) {
            if ($metaW <= $width) {
                $h = max(self::pitchOf($row->note, $baseFont, $small, $linePitch), self::pitchOf($row->tag, $tagFont, $small, $linePitch));
                $line($y, $h, [
                    ['note', $row->note, 0.0, $noteW, $baseFont, $small],
                    ['tag', $row->tag, $noteW > 0.0 ? $noteW + self::GAP : 0.0, $tagW, $tagFont, $small],
                ]);
                $y += $h;
            } else {
                $y += $place('note', $row->note, 0.0, $y, $width, $baseFont, $small);
                $y += $place('tag', $row->tag, 0.0, $y, $width, $tagFont, $small);
            }
        }

        $y += $place('body', $row->body, 0.0, $y, $width, $baseFont, $body);

        if ($valueTotal > 0.0 && !$valueInline) {
            if ($valueTotal <= $width) {
                $h = max(self::pitchOf($row->valueLabel, $baseFont, $small, $linePitch), self::pitchOf($row->value, $valueFont, $body, $linePitch));
                $line($y, $h, [
                    ['valueLabel', $row->valueLabel, 0.0, $labelW, $baseFont, $small],
                    ['value', $row->value, $labelW > 0.0 ? $labelW + self::GAP : 0.0, $valueW, $valueFont, $body],
                ]);
                $y += $h;
            } else {
                $y += $place('valueLabel', $row->valueLabel, 0.0, $y, $width, $baseFont, $small);
                $y += $place('value', $row->value, 0.0, $y, $width, $valueFont, $body);
            }
        }

        return new self($items, $y + $row->rowGap);
    }

    /** Width of two parts next to each other; an absent part takes no gap. */
    private static function joined(float $a, float $b): float
    {
        return $a > 0.0 && $b > 0.0 ? $a + self::GAP + $b : $a + $b;
    }

    /** @param callable(string, float): float $linePitch */
    private static function pitchOf(string $text, string $font, float $size, callable $linePitch): float
    {
        return $text === '' ? 0.0 : $linePitch($font, $size);
    }
}
