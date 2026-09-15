<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

/**
 * Where text may wrap, shared by the widgets that wrap it.
 *
 * Latin-style scripts break at spaces. Scripts written without spaces
 * (Chinese, Japanese) break between any two full-width characters, except
 * before closing punctuation and small kana, which must not begin a line.
 */
final class LineBreaks
{
    /** Code point ranges drawn as full-width glyphs, for a regex character class. */
    public const WIDE = '\x{1100}-\x{115F}\x{2E80}-\x{A4CF}\x{AC00}-\x{D7A3}\x{F900}-\x{FAFF}'
        . '\x{FE30}-\x{FE4F}\x{FF00}-\x{FF60}\x{FFE0}-\x{FFE6}\x{20000}-\x{3FFFD}';

    /** Full-width characters that must not begin a line. */
    public const NO_LINE_START = '、。，．！？：；）」』】〉》〕｝ー々ぁぃぅぇぉっゃゅょァィゥェォッャュョ';

    private function __construct() {}

    public static function isWide(string $char): bool
    {
        return preg_match('/^[' . self::WIDE . ']$/u', $char) === 1;
    }

    /**
     * A combining mark (a Thai tone or vowel sign, an accent) belongs to the
     * letter before it and must never begin a line on its own.
     */
    public static function isMark(string $char): bool
    {
        return preg_match('/^\p{M}$/u', $char) === 1;
    }

    /**
     * Whether a line may end between $chars[$index - 1] and $chars[$index].
     *
     * @param list<string> $chars single characters
     */
    public static function canBreakBefore(array $chars, int $index): bool
    {
        if ($index <= 0 || $index >= count($chars)) {
            return false;
        }
        $before = $chars[$index - 1];
        $char = $chars[$index];
        if (self::isMark($char)) {
            return false;
        }
        if ($before === ' ') {
            return true;
        }
        if ($char === ' ' || $char === "\n" || str_contains(self::NO_LINE_START, $char)) {
            return false;
        }

        return self::isWide($before) || self::isWide($char);
    }
}
