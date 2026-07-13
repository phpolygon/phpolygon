<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Writing systems the engine can reason about for font selection. A renderer
 * knows which of its loaded fonts cover which script (see
 * {@see Renderer2DInterface::fontCoversScript()}), so a game can pick the right
 * face for a locale instead of hardcoding "font X can't render language Y".
 *
 * Latin is the catch-all default: every UI font covers it and it never needs
 * resolution, so {@see ofText()} omits it.
 */
enum Script: string
{
    case Latin = 'latin';
    case Cyrillic = 'cyrillic';
    case Greek = 'greek';
    case Han = 'han';          // CJK unified ideographs (zh/ja kanji)
    case Hangul = 'hangul';    // Korean
    case Kana = 'kana';        // Japanese hiragana + katakana
    case Arabic = 'arabic';
    case Hebrew = 'hebrew';
    case Thai = 'thai';
    case Devanagari = 'devanagari';

    /**
     * A codepoint a font must render to be considered a cover for this script.
     * A common, early-in-the-block letter is chosen so probing is reliable.
     */
    public function sampleCodepoint(): int
    {
        return match ($this) {
            self::Latin => 0x0041,       // A
            self::Cyrillic => 0x0410,    // А
            self::Greek => 0x0391,       // Α
            self::Han => 0x4E00,         // 一
            self::Hangul => 0xAC00,      // 가
            self::Kana => 0x3042,        // あ
            self::Arabic => 0x0627,      // ا
            self::Hebrew => 0x05D0,      // א
            self::Thai => 0x0E01,        // ก
            self::Devanagari => 0x0905,  // अ
        };
    }

    /** The sample codepoint as a UTF-8 string. */
    public function sampleChar(): string
    {
        return mb_chr($this->sampleCodepoint(), 'UTF-8');
    }

    /** The script a single codepoint belongs to (Latin for anything unmapped). */
    public static function ofCodepoint(int $cp): self
    {
        return match (true) {
            ($cp >= 0x0400 && $cp <= 0x04FF) || ($cp >= 0x0500 && $cp <= 0x052F) => self::Cyrillic,
            ($cp >= 0x0370 && $cp <= 0x03FF) || ($cp >= 0x1F00 && $cp <= 0x1FFF) => self::Greek,
            ($cp >= 0x0600 && $cp <= 0x06FF) || ($cp >= 0x0750 && $cp <= 0x077F) => self::Arabic,
            $cp >= 0x0590 && $cp <= 0x05FF => self::Hebrew,
            $cp >= 0x0E00 && $cp <= 0x0E7F => self::Thai,
            $cp >= 0x0900 && $cp <= 0x097F => self::Devanagari,
            $cp >= 0x3040 && $cp <= 0x30FF => self::Kana,
            ($cp >= 0xAC00 && $cp <= 0xD7AF) || ($cp >= 0x1100 && $cp <= 0x11FF) => self::Hangul,
            ($cp >= 0x4E00 && $cp <= 0x9FFF) || ($cp >= 0x3400 && $cp <= 0x4DBF) || ($cp >= 0xF900 && $cp <= 0xFAFF) => self::Han,
            default => self::Latin,
        };
    }

    /**
     * The distinct non-Latin scripts a string contains, in order of first
     * appearance. Latin is omitted (every font covers it). Empty for plain
     * Latin/ASCII text — a fast, allocation-free answer for the common case.
     *
     * @return list<self>
     */
    public static function ofText(string $text): array
    {
        $out = [];
        $seen = [];
        foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
            // mb_str_split yields valid UTF-8 characters, so mb_ord never fails
            // here; cast keeps the type int for the range checks.
            $cp = (int) mb_ord($ch, 'UTF-8');
            $s = self::ofCodepoint($cp);
            if ($s !== self::Latin && !isset($seen[$s->value])) {
                $seen[$s->value] = true;
                $out[] = $s;
            }
        }

        return $out;
    }
}
