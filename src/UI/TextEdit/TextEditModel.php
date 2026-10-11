<?php

declare(strict_types=1);

namespace PHPolygon\UI\TextEdit;

/**
 * Editing core of a multi-line text field: buffer, caret, selection,
 * indentation and an undo history - no input devices, no drawing, so every
 * rule is testable on its own. {@see \PHPolygon\UI\UIContext::textArea()}
 * maps keys, mouse and clipboard onto it.
 *
 * Positions are code points (mb_* semantics), never bytes, so multibyte and
 * CJK text edits one character at a time. Combining sequences (an emoji with
 * a skin tone, a ZWJ family) are several code points and are not treated as
 * one glyph.
 *
 * Undo groups the way editors do: a run of typed characters is one step, a
 * run of backspaces or deletes is one step, and every Enter, paste, cut and
 * (out)indent is a step of its own. Moving the caret ends a run.
 */
final class TextEditModel
{
    /** Undo steps kept; older ones are dropped. */
    public const int MAX_UNDO = 200;

    /** Edit kinds that extend the previous step when the caret did not move in between. */
    private const array RUN_KINDS = ['type', 'back', 'fwd'];

    private string $text = '';
    private int $length = 0;
    private int $caret = 0;
    private ?int $anchor = null;

    /** Column up/down movement aims for, kept across shorter lines. */
    private ?int $preferredColumn = null;

    /** @var list<array{0: string, 1: int, 2: ?int}> */
    private array $undoStack = [];

    /** @var list<array{0: string, 1: int, 2: ?int}> */
    private array $redoStack = [];

    private ?string $runKind = null;
    private int $runCaret = -1;

    public function __construct(
        string $text = '',
        ?int $caret = null,
        private IndentRules $rules = new IndentRules(),
    ) {
        $this->setText($text, $caret);
    }

    // ── state ──────────────────────────────────────────────────────

    public function text(): string
    {
        return $this->text;
    }

    public function length(): int
    {
        return $this->length;
    }

    public function caret(): int
    {
        return $this->caret;
    }

    /** The fixed end of the selection, null when nothing is selected. */
    public function anchor(): ?int
    {
        return $this->anchor;
    }

    public function hasSelection(): bool
    {
        return $this->anchor !== null && $this->anchor !== $this->caret;
    }

    /**
     * Ordered [start, end) of the selection; [caret, caret] without one.
     *
     * @return array{0: int, 1: int}
     */
    public function selectionRange(): array
    {
        if (!$this->hasSelection()) {
            return [$this->caret, $this->caret];
        }
        $anchor = (int) $this->anchor;
        return [min($anchor, $this->caret), max($anchor, $this->caret)];
    }

    public function selectedText(): string
    {
        [$start, $end] = $this->selectionRange();
        return mb_substr($this->text, $start, $end - $start);
    }

    public function rules(): IndentRules
    {
        return $this->rules;
    }

    public function setRules(IndentRules $rules): void
    {
        $this->rules = $rules;
    }

    /** Replace the whole text and start a fresh history; caret at the end when null. */
    public function setText(string $text, ?int $caret = null): void
    {
        $this->text = self::clean($text);
        $this->length = mb_strlen($this->text);
        $this->caret = $caret === null ? $this->length : $this->clamp($caret);
        $this->anchor = null;
        $this->preferredColumn = null;
        $this->undoStack = [];
        $this->redoStack = [];
        $this->runKind = null;
    }

    /**
     * Line (0-based) and column (code points) of position $index.
     *
     * @return array{0: int, 1: int}
     */
    public function lineAndColumn(int $index): array
    {
        $index = $this->clamp($index);
        $before = mb_substr($this->text, 0, $index);
        $line = substr_count($before, "\n");
        $lastBreak = mb_strrpos($before, "\n");
        return [$line, $lastBreak === false ? $index : $index - $lastBreak - 1];
    }

    /** Position of $line / $column, the column clamped to the line, the line to the text. */
    public function indexAt(int $line, int $column): int
    {
        $lines = explode("\n", $this->text);
        $line = max(0, min($line, count($lines) - 1));
        $offset = 0;
        for ($i = 0; $i < $line; $i++) {
            $offset += mb_strlen($lines[$i]) + 1;
        }
        return $offset + max(0, min($column, mb_strlen($lines[$line])));
    }

    // ── caret and selection ────────────────────────────────────────

    /** Put the caret at $index; $extend keeps (or starts) a selection from where it was. */
    public function setCaret(int $index, bool $extend = false): void
    {
        $this->moveTo($this->clamp($index), $extend);
    }

    /** Select from $anchor to $caret (the caret end moves on with Shift). */
    public function setSelection(int $anchor, int $caret): void
    {
        $this->endRun();
        $this->preferredColumn = null;
        $this->caret = $this->clamp($caret);
        $anchor = $this->clamp($anchor);
        $this->anchor = $anchor === $this->caret ? null : $anchor;
    }

    public function moveLeft(bool $extend = false, bool $word = false): void
    {
        if (!$extend && $this->hasSelection()) {
            $this->moveTo($this->selectionRange()[0], false);
            return;
        }
        $this->moveTo($word ? $this->wordLeft($this->caret) : max(0, $this->caret - 1), $extend);
    }

    public function moveRight(bool $extend = false, bool $word = false): void
    {
        if (!$extend && $this->hasSelection()) {
            $this->moveTo($this->selectionRange()[1], false);
            return;
        }
        $this->moveTo($word ? $this->wordRight($this->caret) : min($this->length, $this->caret + 1), $extend);
    }

    public function moveUp(bool $extend = false): void
    {
        $this->moveVertically(-1, $extend);
    }

    public function moveDown(bool $extend = false): void
    {
        $this->moveVertically(1, $extend);
    }

    /** Smart Home: first non-blank character of the line, from there column 0, and back. */
    public function home(bool $extend = false): void
    {
        [$line] = $this->lineAndColumn($this->caret);
        $lineStart = $this->indexAt($line, 0);
        $lineText = mb_substr($this->text, $lineStart, $this->indexAt($line, PHP_INT_MAX) - $lineStart);
        $firstNonBlank = $lineStart + mb_strlen($lineText) - mb_strlen(ltrim($lineText, " \t"));
        $this->moveTo($this->caret === $firstNonBlank ? $lineStart : $firstNonBlank, $extend);
    }

    public function end(bool $extend = false): void
    {
        [$line] = $this->lineAndColumn($this->caret);
        $this->moveTo($this->indexAt($line, PHP_INT_MAX), $extend);
    }

    public function documentStart(bool $extend = false): void
    {
        $this->moveTo(0, $extend);
    }

    public function documentEnd(bool $extend = false): void
    {
        $this->moveTo($this->length, $extend);
    }

    public function selectAll(): void
    {
        $this->setSelection(0, $this->length);
    }

    /** Select the word (or the run of blanks, or the single other character) at $index. */
    public function selectWordAt(int $index): void
    {
        if ($this->length === 0) {
            $this->setCaret(0);
            return;
        }
        $index = max(0, min($index, $this->length - 1));
        $char = $this->charAt($index);
        $class = self::classOf($char);
        if ($class === 'newline' || $class === 'other') {
            $this->setSelection($index, $index + ($class === 'other' ? 1 : 0));
            return;
        }
        $start = $index;
        while ($start > 0 && self::classOf($this->charAt($start - 1)) === $class) {
            $start--;
        }
        $end = $index + 1;
        while ($end < $this->length && self::classOf($this->charAt($end)) === $class) {
            $end++;
        }
        $this->setSelection($start, $end);
    }

    // ── editing ────────────────────────────────────────────────────

    /**
     * Type $text at the caret (replacing the selection). A closing bracket
     * typed as the first thing on an indented line takes one level away.
     */
    public function insert(string $text): void
    {
        $text = self::clean($text);
        if ($text === '') {
            return;
        }
        [$start, $end] = $this->selectionRange();

        if (!$this->hasSelection() && in_array($text, $this->rules->closers, true)) {
            [, $column] = $this->lineAndColumn($start);
            $lineStart = $start - $column;
            $before = mb_substr($this->text, $lineStart, $column);
            if ($before !== '' && trim($before, " \t") === '') {
                $indent = $this->withoutOneLevel($before);
                $this->commit('type', $lineStart, $start, $indent . $text, $lineStart + mb_strlen($indent) + 1);
                return;
            }
        }

        $this->commit('type', $start, $end, $text, $start + mb_strlen($text));
    }

    /** Paste $text: line endings normalised to "\n", one undo step. */
    public function paste(string $text): void
    {
        $text = str_replace(["\r\n", "\r"], "\n", self::clean($text));
        [$start, $end] = $this->selectionRange();
        if ($text === '' && $start === $end) {
            return;
        }
        $this->commit('paste', $start, $end, $text, $start + mb_strlen($text));
    }

    /** Remove the selection and return it ('' and no change without one). */
    public function cut(): string
    {
        if (!$this->hasSelection()) {
            return '';
        }
        $cut = $this->selectedText();
        [$start, $end] = $this->selectionRange();
        $this->commit('cut', $start, $end, '', $start);
        return $cut;
    }

    /**
     * Enter: a line break carrying the current line's indentation, one level
     * more after an opener, the closer on a line of its own when the caret
     * sits between a bracket pair. An indentation-only line is left empty.
     */
    public function newline(): void
    {
        [$start, $end] = $this->selectionRange();
        [, $column] = $this->lineAndColumn($start);
        $lineStart = $start - $column;
        $before = mb_substr($this->text, $lineStart, $column);
        $indent = substr($before, 0, strspn($before, " \t"));

        $from = $start;
        if ($before !== '' && trim($before, " \t") === '') {
            $from = $lineStart; // no trailing blanks on the line left behind
        }

        $trimmed = rtrim($before, " \t");
        $last = $trimmed === '' ? '' : mb_substr($trimmed, -1);
        $opens = $last !== '' && in_array($last, $this->rules->openers, true);
        $extra = $opens ? $this->rules->unit() : '';
        $closer = $opens ? $this->rules->closerFor($last) : null;

        if ($closer !== null && mb_substr($this->text, $end, 1) === $closer) {
            $insert = "\n" . $indent . $extra . "\n" . $indent;
            $caret = $from + 1 + mb_strlen($indent . $extra);
        } else {
            $insert = "\n" . $indent . $extra;
            $caret = $from + mb_strlen($insert);
        }
        $this->commit('newline', $from, $end, $insert, $caret);
    }

    /**
     * Backspace: the selection, else the character before the caret; in the
     * leading blanks of a line back to the previous indent stop. $word
     * removes the word (or run) before the caret.
     */
    public function backspace(bool $word = false): void
    {
        if ($this->hasSelection()) {
            [$start, $end] = $this->selectionRange();
            $this->commit('delete', $start, $end, '', $start);
            return;
        }
        if ($this->caret === 0) {
            return;
        }
        if ($word) {
            $target = $this->wordLeft($this->caret);
        } else {
            [, $column] = $this->lineAndColumn($this->caret);
            $before = mb_substr($this->text, $this->caret - $column, $column);
            if (!$this->rules->useTabs && $column > 0 && trim($before, ' ') === '') {
                $target = $this->caret - (($column - 1) % $this->rules->width + 1);
            } else {
                $target = $this->caret - 1;
            }
        }
        $this->commit('back', $target, $this->caret, '', $target);
    }

    /** Delete: the selection, else the character (or with $word the word) after the caret. */
    public function delete(bool $word = false): void
    {
        if ($this->hasSelection()) {
            [$start, $end] = $this->selectionRange();
            $this->commit('delete', $start, $end, '', $start);
            return;
        }
        if ($this->caret >= $this->length) {
            return;
        }
        $target = $word ? $this->wordRight($this->caret) : $this->caret + 1;
        $this->commit('fwd', $this->caret, $target, '', $this->caret);
    }

    /**
     * Tab: one level more for every line of a multi-line selection, else
     * blanks up to the next indent stop (replacing a selection within a line).
     */
    public function indent(): void
    {
        if ($this->hasSelection() && str_contains($this->selectedText(), "\n")) {
            $this->shiftLines(1);
            return;
        }
        [$start, $end] = $this->selectionRange();
        [, $column] = $this->lineAndColumn($start);
        $insert = $this->rules->useTabs ? "\t" : str_repeat(' ', $this->rules->width - $column % $this->rules->width);
        $this->commit('indent', $start, $end, $insert, $start + mb_strlen($insert));
    }

    /** Shift+Tab: one level less for the caret's line or every selected line. */
    public function outdent(): void
    {
        $this->shiftLines(-1);
    }

    // ── history ────────────────────────────────────────────────────

    public function canUndo(): bool
    {
        return $this->undoStack !== [];
    }

    public function canRedo(): bool
    {
        return $this->redoStack !== [];
    }

    public function undo(): bool
    {
        $state = array_pop($this->undoStack);
        if ($state === null) {
            return false;
        }
        $this->redoStack[] = $this->snapshot();
        $this->restore($state);
        return true;
    }

    public function redo(): bool
    {
        $state = array_pop($this->redoStack);
        if ($state === null) {
            return false;
        }
        $this->undoStack[] = $this->snapshot();
        $this->restore($state);
        return true;
    }

    // ── internals ──────────────────────────────────────────────────

    /**
     * Replace [$start, $end) with $insert and put the caret at $caret, as one
     * undo step of $kind (or as part of the running one). A change that
     * changes nothing records nothing.
     */
    private function commit(string $kind, int $start, int $end, string $insert, int $caret, ?int $anchor = null): void
    {
        if ($start === $end && $insert === '') {
            return;
        }
        $text = mb_substr($this->text, 0, $start) . $insert . mb_substr($this->text, $end);
        $this->commitText($kind, $text, $caret, $anchor);
    }

    private function commitText(string $kind, string $text, int $caret, ?int $anchor = null): void
    {
        if ($text === $this->text) {
            return;
        }
        $extendsRun = in_array($kind, self::RUN_KINDS, true)
            && $kind === $this->runKind
            && $this->caret === $this->runCaret
            && !$this->hasSelection();
        if (!$extendsRun) {
            $this->undoStack[] = $this->snapshot();
            if (count($this->undoStack) > self::MAX_UNDO) {
                array_shift($this->undoStack);
            }
        }
        $this->redoStack = [];

        $this->text = $text;
        $this->length = mb_strlen($text);
        $this->caret = $this->clamp($caret);
        $this->anchor = $anchor === null || $this->clamp($anchor) === $this->caret ? null : $this->clamp($anchor);
        $this->preferredColumn = null;
        $this->runKind = $kind;
        $this->runCaret = $this->caret;
    }

    /** Indent ($dir 1) or outdent ($dir -1) the lines the caret or selection touches. */
    private function shiftLines(int $dir): void
    {
        [$start, $end] = $this->selectionRange();
        [$firstLine] = $this->lineAndColumn($start);
        [$lastLine, $endColumn] = $this->lineAndColumn($end);
        if ($end > $start && $endColumn === 0 && $lastLine > $firstLine) {
            $lastLine--; // a selection ending at column 0 does not include that line
        }

        $lines = explode("\n", $this->text);
        $removed = [];
        for ($i = $firstLine; $i <= $lastLine; $i++) {
            if ($dir > 0) {
                if ($lines[$i] !== '') {
                    $lines[$i] = $this->rules->unit() . $lines[$i];
                }
                continue;
            }
            $kept = $this->withoutOneLevel($lines[$i], prefixOnly: true);
            $removed[$i] = mb_strlen($lines[$i]) - mb_strlen($kept);
            $lines[$i] = $kept;
        }
        $text = implode("\n", $lines);
        if ($text === $this->text) {
            return;
        }

        $multiLine = $this->hasSelection() && str_contains($this->selectedText(), "\n");
        $caretFirst = $this->caret < ($this->anchor ?? $this->caret);
        $before = $this->text;
        $kind = $dir > 0 ? 'indent' : 'outdent';

        if ($multiLine) {
            // The selection grows to whole lines, in the direction it had.
            $this->text = $text; // temporarily, to measure the new line ends
            $this->length = mb_strlen($text);
            $selStart = $this->indexAt($firstLine, 0);
            $selEnd = $this->indexAt($lastLine, PHP_INT_MAX);
            $this->text = $before;
            $this->length = mb_strlen($before);
            $this->commitText($kind, $text, $caretFirst ? $selStart : $selEnd, $caretFirst ? $selEnd : $selStart);
            return;
        }

        // One line: caret (and a selection inside the line) keep their characters.
        $shift = static function (int $pos, int $lineStart, int $delta): int {
            return $lineStart + max(0, $pos - $lineStart + $delta);
        };
        $lineStart = $this->indexAt($firstLine, 0);
        $delta = $dir > 0 ? mb_strlen($this->rules->unit()) : -($removed[$firstLine] ?? 0);
        $caret = $shift($this->caret, $lineStart, $delta);
        $anchor = $this->hasSelection() ? $shift((int) $this->anchor, $lineStart, $delta) : null;
        $this->commitText($kind, $text, $caret, $anchor);
    }

    /** $indent (or a line's leading blanks with $prefixOnly) minus one level: a tab or up to width spaces. */
    private function withoutOneLevel(string $text, bool $prefixOnly = false): string
    {
        if (str_starts_with($text, "\t")) {
            return substr($text, 1);
        }
        $spaces = strspn($text, ' ');
        if ($spaces === 0) {
            return $text;
        }
        $remove = $prefixOnly ? min($spaces, $this->rules->width) : (($spaces - 1) % $this->rules->width + 1);
        return substr($text, $remove);
    }

    private function moveVertically(int $dir, bool $extend): void
    {
        [$line, $column] = $this->lineAndColumn($this->caret);
        $want = $this->preferredColumn ?? $column;
        $lastLine = substr_count($this->text, "\n");
        $target = $line + $dir;
        if ($target < 0) {
            $index = 0;
        } elseif ($target > $lastLine) {
            $index = $this->length;
        } else {
            $index = $this->indexAt($target, $want);
        }
        $this->moveTo($index, $extend);
        $this->preferredColumn = $want;
    }

    private function moveTo(int $index, bool $extend): void
    {
        $this->endRun();
        $this->preferredColumn = null;
        if ($extend) {
            $this->anchor ??= $this->caret;
        } else {
            $this->anchor = null;
        }
        $this->caret = $index;
        if ($this->anchor === $this->caret) {
            $this->anchor = null;
        }
    }

    private function endRun(): void
    {
        $this->runKind = null;
    }

    private function wordLeft(int $index): int
    {
        $i = $index;
        while ($i > 0 && self::classOf($this->charAt($i - 1)) === 'blank') {
            $i--;
        }
        if ($i > 0 && $this->charAt($i - 1) === "\n") {
            return $i === $index ? $i - 1 : $i;
        }
        if ($i === 0) {
            return 0;
        }
        $class = self::classOf($this->charAt($i - 1));
        while ($i > 0 && self::classOf($this->charAt($i - 1)) === $class) {
            $i--;
        }
        return $i;
    }

    private function wordRight(int $index): int
    {
        $i = $index;
        while ($i < $this->length && self::classOf($this->charAt($i)) === 'blank') {
            $i++;
        }
        if ($i < $this->length && $this->charAt($i) === "\n") {
            return $i === $index ? $i + 1 : $i;
        }
        if ($i >= $this->length) {
            return $this->length;
        }
        $class = self::classOf($this->charAt($i));
        while ($i < $this->length && self::classOf($this->charAt($i)) === $class) {
            $i++;
        }
        return $i;
    }

    private function charAt(int $index): string
    {
        return mb_substr($this->text, $index, 1);
    }

    /** 'word' (letters, digits, '_'), 'blank', 'newline' or 'other'. */
    private static function classOf(string $char): string
    {
        if ($char === "\n") {
            return 'newline';
        }
        if ($char === ' ' || $char === "\t") {
            return 'blank';
        }
        return preg_match('/^[\p{L}\p{N}\p{M}_]$/u', $char) === 1 ? 'word' : 'other';
    }

    private function clamp(int $index): int
    {
        return max(0, min($index, $this->length));
    }

    /** Valid UTF-8: invalid byte sequences become '?'. */
    private static function clean(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
    }

    /** @return array{0: string, 1: int, 2: ?int} */
    private function snapshot(): array
    {
        return [$this->text, $this->caret, $this->anchor];
    }

    /** @param array{0: string, 1: int, 2: ?int} $state */
    private function restore(array $state): void
    {
        [$this->text, $this->caret, $this->anchor] = $state;
        $this->length = mb_strlen($this->text);
        $this->preferredColumn = null;
        $this->endRun();
    }
}
