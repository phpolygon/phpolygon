<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Greedy line breaking, shared by every renderer that wraps text.
 *
 * WHY THIS EXISTS
 * ---------------
 * Wrapping used to be `explode(' ', $paragraph)` — repeated in three places,
 * and wrong for every script that does not separate words with spaces.
 * Japanese and Chinese write a whole sentence as one uninterrupted run of
 * characters: to a space-splitter it is a single "word", so a line longer than
 * the column has no break opportunity at all and simply overflows. The text
 * does not clip, it runs off the panel.
 *
 * HOW A BREAK OPPORTUNITY IS FOUND
 * --------------------------------
 * Two kinds of opportunity, following the spirit of UAX #14 without shipping
 * its full table:
 *
 *   1. Between space-separated words, as before.
 *   2. Between adjacent CJK characters (Han, Hiragana, Katakana, Hangul and
 *      the full-width forms), because each one is a legitimate line start.
 *
 * Two exceptions keep punctuation where a reader expects it:
 *
 *   - A line never STARTS with closing punctuation — the small kana, the
 *     sound mark, the full stop, the closing bracket. Those glue to the
 *     character in front of them.
 *   - A line never ENDS with opening punctuation — an opening bracket glues
 *     to the character behind it.
 *
 * Latin words are never split mid-word: a single word wider than the column
 * keeps its own line and overflows, which is the conventional (and least
 * surprising) behaviour.
 *
 * The measuring callback is supplied by the caller, so wrapping always agrees
 * with what that particular renderer will actually draw — including its font
 * fallback chain.
 */
final class TextWrap
{
    /**
     * Characters that may not begin a line; they attach to what precedes them.
     *
     * Small kana and the prolonged sound mark are included because a Japanese
     * reader treats them as part of the preceding syllable, not as a character
     * that can start a line.
     */
    private const string NO_LINE_START =
        '、。，．・：；？！ー〜―）］｝〉》」』】〕｣»›”’%‰°℃'
        . 'ぁぃぅぇぉっゃゅょゎゕゖァィゥェォッャュョヮヵヶ';

    /** Characters that may not end a line; they attach to what follows. */
    private const string NO_LINE_END = '（［｛〈《「『【〔｢«‹“‘＄￥£€#';

    /**
     * Break $text into rendered lines no wider than $breakWidth.
     *
     * Hard breaks (\r\n, \r, \n) always split; an empty paragraph yields an
     * empty entry so callers can advance a blank line.
     *
     * @param  callable(string): float $lineWidth measures a candidate line
     * @return list<string>
     */
    public static function lines(string $text, float $breakWidth, callable $lineWidth): array
    {
        return self::wrap($text, $breakWidth, $lineWidth);
    }

    /**
     * Like {@see lines()}, but no line is ever wider than $breakWidth: a single
     * word that does not fit on its own is split between two graphemes
     * instead of overflowing. For fixed-size boxes (buttons, chips) where an
     * overflowing word would run past the edge and truncating would lose text.
     * Graphemes, not code points: a Thai vowel or tone mark, a combining accent
     * and the like stay on their base character, and a single grapheme wider
     * than the box keeps its line.
     *
     * @param  callable(string): float $lineWidth measures a candidate line
     * @return list<string>
     */
    public static function linesWithin(string $text, float $breakWidth, callable $lineWidth): array
    {
        $out = [];
        foreach (self::wrap($text, $breakWidth, $lineWidth) as $line) {
            if ($line === '' || $lineWidth($line) <= $breakWidth) {
                $out[] = $line;
                continue;
            }
            $part = '';
            $graphemes = preg_match_all('/\X/u', $line, $matches) > 0 ? $matches[0] : [$line];
            foreach ($graphemes as $char) {
                if ($part !== '' && $lineWidth($part . $char) > $breakWidth) {
                    $out[] = $part;
                    $part = '';
                }
                $part .= $char;
            }
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @param  callable(string): float $lineWidth
     * @return list<string>
     */
    private static function wrap(string $text, float $breakWidth, callable $lineWidth): array
    {
        $paragraphs = preg_split('/\r\n?|\n/', $text);
        if ($paragraphs === false) {
            $paragraphs = [$text];
        }

        $lines = [];
        foreach ($paragraphs as $paragraph) {
            if ($paragraph === '') {
                $lines[] = '';
                continue;
            }

            $line = '';
            foreach (self::segments($paragraph) as [$piece, $glue]) {
                $candidate = $line === '' ? $piece : $line . $glue . $piece;

                if ($line !== '' && $lineWidth($candidate) > $breakWidth) {
                    $lines[] = $line;
                    $line = $piece;
                } else {
                    $line = $candidate;
                }
            }

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Split one paragraph into pieces that may each start a line.
     *
     * Every piece carries the glue that rejoins it to the piece before it: a
     * single space where a space was consumed, an empty string where the break
     * sits between two characters and must not introduce one.
     *
     * @return list<array{string, string}> [piece, glue]
     */
    private static function segments(string $paragraph): array
    {
        $chars = preg_split('//u', $paragraph, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || $chars === []) {
            return [[$paragraph, '']];
        }

        $out = [];
        $current = '';
        $glue = '';

        foreach ($chars as $i => $char) {
            if ($char === ' ') {
                if ($current !== '') {
                    $out[] = [$current, $glue];
                    $current = '';
                }
                $glue = ' ';
                continue;
            }

            $prev = $i > 0 ? $chars[$i - 1] : '';
            if ($current !== '' && self::breakBetween($prev, $char)) {
                $out[] = [$current, $glue];
                $current = '';
                $glue = '';
            }

            $current .= $char;
        }

        if ($current !== '') {
            $out[] = [$current, $glue];
        }

        return $out;
    }

    /** May a line break sit between these two adjacent characters? */
    private static function breakBetween(string $before, string $after): bool
    {
        if (!self::isCjk($before) && !self::isCjk($after)) {
            return false; // ordinary Latin run — only spaces break it
        }

        if (self::contains(self::NO_LINE_START, $after)) {
            return false;
        }

        if (self::contains(self::NO_LINE_END, $before)) {
            return false;
        }

        return true;
    }

    /**
     * Is this a character from a script written without spaces?
     *
     * Hangul is included: modern Korean does use spaces, but it also breaks
     * between syllable blocks, and treating it this way never produces a break
     * a reader would call wrong.
     */
    private static function isCjk(string $char): bool
    {
        return $char !== ''
            && preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]/u', $char) === 1;
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_contains($haystack, $needle);
    }
}
