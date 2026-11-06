<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Text;

/**
 * Visual order of one line of mixed-direction text, for a layout that draws a
 * string in several pieces (one per font of a fallback chain).
 *
 * php-vio resolves bidi inside a single vio_text call. A string split into runs
 * per font loses that: each run is drawn on its own, and placing the runs one
 * after the other in logical order turns an Arabic or Hebrew sentence around word
 * by word (the first word lands on the left, the closing "!" on the right).
 * {@see levels()} gives every character its embedding level, {@see visualOrder()}
 * the order to place them in; a run of right-to-left characters is still handed
 * to vio_text in logical order, so php-vio shapes and reverses it itself.
 *
 * A reduced form of the Unicode bidi algorithm (UAX #9) for plain UI strings, no
 * explicit embeddings or isolates: paragraph direction from the first strong
 * character (P2/P3, like vio's SBLevelDefaultLTR), numbers stay left to right
 * (W4/W5/W7, Arabic-Indic digits counted as European ones), neutrals take the
 * direction of their strong neighbours or the paragraph's (N1/N2), resolved
 * levels (I1/I2), trailing whitespace at the paragraph level (L1) and reversal
 * from the highest level down to the lowest odd one (L2). One deliberate addition:
 * a number terminator directly before a letter belongs to that word, so "$language"
 * in an Arabic sentence keeps its sigil in front.
 */
final class BidiOrder
{
    private const int L = 0;
    private const int R = 1;
    private const int EN = 2;
    private const int ES = 3;   // + -
    private const int CS = 4;   // , . : / and the no-break space
    private const int ET = 5;   // % $ # and other number terminators
    private const int WS = 6;
    private const int ON = 7;   // other neutrals
    private const int NSM = 8;  // combining marks

    /** Pairs swapped when a neutral is shown right to left (Bidi_Mirrored, the common ones). */
    private const array MIRROR = [
        '(' => ')', ')' => '(', '[' => ']', ']' => '[', '{' => '}', '}' => '{',
        '<' => '>', '>' => '<', '«' => '»', '»' => '«', '‹' => '›', '›' => '‹',
    ];

    private function __construct() {}

    /** True when $text holds a right-to-left character (Hebrew, Arabic, Syriac, Thaana, NKo, …). */
    public static function hasRtl(string $text): bool
    {
        return preg_match('/[\x{0590}-\x{08FF}\x{200F}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text) === 1;
    }

    /** True when $ch is a strong right-to-left character. */
    public static function isStrongRtl(string $ch): bool
    {
        return self::classOf($ch) === self::R;
    }

    /** The character shown in place of $ch when it is drawn right to left. */
    public static function mirror(string $ch): string
    {
        return self::MIRROR[$ch] ?? $ch;
    }

    /**
     * Embedding level of every character: even = left to right, odd = right to left.
     *
     * @param list<string> $chars one line, single characters in logical order
     * @param int|null     $base  paragraph level (0 or 1) when the line is part of a longer
     *                            paragraph - a wrapped line takes the paragraph's direction,
     *                            not that of its own first word; null = from this line
     * @return array{base: int, levels: list<int>}
     */
    public static function levels(array $chars, ?int $base = null): array
    {
        $n = count($chars);
        $types = [];
        $prev = null;
        foreach ($chars as $ch) {
            $t = self::classOf($ch);
            if ($t === self::NSM) {
                $t = $prev ?? self::ON;
            }
            $types[] = $t;
            $prev = $t;
        }

        // P2/P3: the first strong character sets the paragraph direction.
        $base ??= self::paragraphLevel($chars);
        $baseDir = $base === 1 ? self::R : self::L;

        // W4: one separator between two digits joins them; W5: terminators next to digits are digits.
        for ($i = 1; $i < $n - 1; $i++) {
            if (($types[$i] === self::CS || $types[$i] === self::ES) && $types[$i - 1] === self::EN && $types[$i + 1] === self::EN) {
                $types[$i] = self::EN;
            }
        }
        for ($i = 0; $i < $n; $i++) {
            if ($types[$i] !== self::ET) {
                continue;
            }
            $j = $i;
            while ($j < $n && $types[$j] === self::ET) {
                $j++;
            }
            if (($i > 0 && $types[$i - 1] === self::EN) || ($j < $n && $types[$j] === self::EN)) {
                for ($k = $i; $k < $j; $k++) {
                    $types[$k] = self::EN;
                }
            }
            $i = $j - 1;
        }

        // Not in UAX #9: a terminator glued to the word after it ("$language", "#id", "%s")
        // is part of that word. A code identifier inside a right-to-left sentence keeps
        // its sigil in front instead of showing as "language$".
        for ($i = $n - 2; $i >= 0; $i--) {
            if ($types[$i] === self::ET && $types[$i + 1] === self::L) {
                $types[$i] = self::L;
            }
        }

        // W7: digits after a left-to-right character (or at the start of a left-to-right paragraph) are L.
        $lastStrong = $baseDir;
        for ($i = 0; $i < $n; $i++) {
            if ($types[$i] === self::L || $types[$i] === self::R) {
                $lastStrong = $types[$i];
            } elseif ($types[$i] === self::EN && $lastStrong === self::L) {
                $types[$i] = self::L;
            }
        }

        // N1/N2: a run of neutrals between two of the same direction (digits count as R) takes it,
        // otherwise the paragraph direction.
        $dir = static fn (int $t): ?int => match ($t) {
            self::L => self::L,
            self::R, self::EN => self::R,
            default => null,
        };
        for ($i = 0; $i < $n; $i++) {
            if ($dir($types[$i]) !== null) {
                continue;
            }
            $j = $i;
            while ($j < $n && $dir($types[$j]) === null) {
                $j++;
            }
            $before = $i > 0 ? $dir($types[$i - 1]) : $baseDir;
            $after = $j < $n ? $dir($types[$j]) : $baseDir;
            $resolved = $before !== null && $before === $after ? $before : $baseDir;
            for ($k = $i; $k < $j; $k++) {
                $types[$k] = $resolved;
            }
            $i = $j - 1;
        }

        // I1/I2.
        $levels = [];
        foreach ($types as $t) {
            if ($base === 0) {
                $levels[] = match ($t) { self::R => 1, self::EN => 2, default => 0 };
            } else {
                $levels[] = $t === self::R ? 1 : 2;
            }
        }

        // L1: whitespace at the end of the line goes back to the paragraph level.
        for ($i = $n - 1; $i >= 0 && self::classOf($chars[$i]) === self::WS; $i--) {
            $levels[$i] = $base;
        }

        return ['base' => $base, 'levels' => array_values($levels)];
    }

    /**
     * P2/P3: 1 when the first strong character of $chars is right to left, else 0.
     *
     * @param list<string> $chars
     */
    public static function paragraphLevel(array $chars): int
    {
        foreach ($chars as $ch) {
            $t = self::classOf($ch);
            if ($t === self::L || $t === self::R) {
                return $t === self::R ? 1 : 0;
            }
        }

        return 0;
    }

    /**
     * The wrapped lines of one paragraph, each drawn on its own, keep the paragraph's
     * direction: a line whose own first strong character points the other way gets a
     * right-to-left mark (U+200F) or left-to-right mark (U+200E) in front. Without it the
     * second line of an Arabic sentence that starts with "$language" runs left to right.
     * Lines that need no mark come back unchanged, as does every line of a paragraph
     * without right-to-left text. The marks have no width (fonts that cover them map them
     * to an empty glyph); measure lines before marking them.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    public static function keepParagraphDirection(string $paragraph, array $lines): array
    {
        if (!self::hasRtl($paragraph)) {
            return $lines;
        }
        $base = self::paragraphLevel(mb_str_split($paragraph));
        foreach ($lines as $i => $line) {
            if ($line !== '' && self::paragraphLevel(mb_str_split($line)) !== $base) {
                $lines[$i] = ($base === 1 ? "\u{200F}" : "\u{200E}") . $line;
            }
        }

        return $lines;
    }

    /**
     * L2: logical indices in the order they appear from left to right.
     *
     * @param list<int> $levels
     * @return list<int>
     */
    public static function visualOrder(array $levels): array
    {
        $order = array_keys($levels);
        if ($levels === []) {
            return $order;
        }
        $max = max($levels);
        $minOdd = PHP_INT_MAX;
        foreach ($levels as $level) {
            if ($level % 2 === 1 && $level < $minOdd) {
                $minOdd = $level;
            }
        }
        if ($minOdd === PHP_INT_MAX) {
            return $order;
        }
        $n = count($levels);
        for ($level = $max; $level >= $minOdd; $level--) {
            for ($i = 0; $i < $n; $i++) {
                if ($levels[$order[$i]] < $level) {
                    continue;
                }
                $j = $i;
                while ($j < $n && $levels[$order[$j]] >= $level) {
                    $j++;
                }
                $slice = array_reverse(array_slice($order, $i, $j - $i));
                array_splice($order, $i, $j - $i, $slice);
                $i = $j;
            }
        }

        return $order;
    }

    private static function classOf(string $ch): int
    {
        if ($ch === '') {
            return self::ON;
        }
        $cp = mb_ord($ch, 'UTF-8');
        if ($cp >= 0x30 && $cp <= 0x39) {
            return self::EN;
        }
        if ($cp < 0x80) {
            return match (true) {
                ($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0x61 && $cp <= 0x7A) => self::L,
                $cp === 0x2B || $cp === 0x2D => self::ES,
                $cp === 0x2C || $cp === 0x2E || $cp === 0x3A || $cp === 0x2F => self::CS,
                $cp === 0x23 || $cp === 0x24 || $cp === 0x25 => self::ET,
                $cp === 0x20 || $cp === 0x09 => self::WS,
                default => self::ON,
            };
        }
        // Arabic-Indic and extended Arabic-Indic digits: numbers, shown left to right.
        if (($cp >= 0x0660 && $cp <= 0x0669) || ($cp >= 0x06F0 && $cp <= 0x06F9)) {
            return self::EN;
        }
        if ($cp === 0x066A || $cp === 0x00B0 || $cp === 0x00A3 || $cp === 0x20AC || $cp === 0x2030) {
            return self::ET;
        }
        if ($cp === 0x00A0 || $cp === 0x060C) {
            return self::CS;
        }
        if ($cp === 0x200F) {
            return self::R;
        }
        if ($cp === 0x200E) {
            return self::L;
        }
        if (($cp >= 0x0591 && $cp <= 0x05BD) || ($cp >= 0x064B && $cp <= 0x065F) || $cp === 0x0670
            || ($cp >= 0x06D6 && $cp <= 0x06ED && $cp !== 0x06DD && $cp !== 0x06DE && $cp !== 0x06E5 && $cp !== 0x06E6)) {
            return self::NSM;
        }
        if (($cp >= 0x0590 && $cp <= 0x08FF) || ($cp >= 0xFB1D && $cp <= 0xFDFF) || ($cp >= 0xFE70 && $cp <= 0xFEFF)) {
            return self::R;
        }
        if (preg_match('/\p{Mn}|\p{Me}/u', $ch) === 1) {
            return self::NSM;
        }
        if (preg_match('/\p{Zs}/u', $ch) === 1) {
            return self::WS;
        }
        if (preg_match('/[\p{L}\p{Mc}\p{Nd}]/u', $ch) === 1) {
            return self::L;
        }

        return self::ON;
    }
}
