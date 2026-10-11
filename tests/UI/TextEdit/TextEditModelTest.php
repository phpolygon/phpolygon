<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\TextEdit;

use PHPolygon\UI\TextEdit\IndentRules;
use PHPolygon\UI\TextEdit\TextEditModel;
use PHPUnit\Framework\TestCase;

/**
 * The pure editing core behind UIContext::textArea(): buffer, caret,
 * selection, indentation and undo. Positions are code points, never bytes.
 *
 * Notation in the comments: "|" marks the caret, "[...]" a selection.
 */
final class TextEditModelTest extends TestCase
{
    // ── typing ──────────────────────────────────────────────────────

    public function testTypingInsertsAtTheCaret(): void
    {
        $m = new TextEditModel('ac', 1);
        $m->insert('b');

        self::assertSame('abc', $m->text());
        self::assertSame(2, $m->caret());
        self::assertFalse($m->hasSelection());
    }

    public function testPositionsAreCodePointsNotBytes(): void
    {
        $m = new TextEditModel('ä漢😀', 2);
        $m->insert('字');

        self::assertSame('ä漢字😀', $m->text());
        self::assertSame(3, $m->caret());
        $m->moveRight();
        self::assertSame(4, $m->caret(), 'one step over the emoji');
        $m->backspace();
        self::assertSame('ä漢字', $m->text());
    }

    public function testTypingReplacesTheSelection(): void
    {
        $m = new TextEditModel('hello world');
        $m->setCaret(0);
        $m->setCaret(5, extend: true);
        $m->insert('bye');

        self::assertSame('bye world', $m->text());
        self::assertSame(3, $m->caret());
        self::assertFalse($m->hasSelection());
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        $m = new TextEditModel('');
        $m->insert("a\xFFb");

        self::assertTrue(mb_check_encoding($m->text(), 'UTF-8'));
        self::assertStringStartsWith('a', $m->text());
        self::assertStringEndsWith('b', $m->text());
    }

    // ── deleting ────────────────────────────────────────────────────

    public function testBackspaceAndDeleteRemoveOneCharacter(): void
    {
        $m = new TextEditModel('abcd', 2);
        $m->backspace();
        self::assertSame('acd', $m->text());
        self::assertSame(1, $m->caret());

        $m->delete();
        self::assertSame('ad', $m->text());
        self::assertSame(1, $m->caret());
    }

    public function testBackspaceAtTheStartAndDeleteAtTheEndDoNothing(): void
    {
        $m = new TextEditModel('ab', 0);
        $m->backspace();
        self::assertSame('ab', $m->text());
        self::assertFalse($m->canUndo(), 'a no-op is no undo step');

        $m->setCaret(2);
        $m->delete();
        self::assertSame('ab', $m->text());
        self::assertFalse($m->canUndo());
    }

    public function testBackspaceAndDeleteRemoveTheSelection(): void
    {
        $m = new TextEditModel('one two three');
        $m->setCaret(4);
        $m->setCaret(8, extend: true);
        $m->backspace();
        self::assertSame('one three', $m->text());
        self::assertSame(4, $m->caret());

        $m->setCaret(9);
        $m->setCaret(3, extend: true);
        $m->delete();
        self::assertSame('one', $m->text());
        self::assertSame(3, $m->caret());
    }

    public function testBackspaceMergesLines(): void
    {
        $m = new TextEditModel("ab\ncd", 3);
        $m->backspace();
        self::assertSame('abcd', $m->text());
        self::assertSame(2, $m->caret());
    }

    public function testBackspaceInLeadingSpacesRemovesOneIndentLevel(): void
    {
        $m = new TextEditModel("if (x) {\n        y", 17);
        $m->setCaret(17); // after 8 spaces, before y
        $m->backspace();

        self::assertSame("if (x) {\n    y", $m->text());
        self::assertSame(13, $m->caret());
    }

    public function testWordBackspaceAndWordDelete(): void
    {
        $m = new TextEditModel('foo bar_baz qux', 11); // after "bar_baz"
        $m->backspace(word: true);
        self::assertSame('foo  qux', $m->text());
        self::assertSame(4, $m->caret());

        $m->delete(word: true);
        self::assertSame('foo ', $m->text(), 'whitespace then the word');
    }

    // ── caret movement and selection ────────────────────────────────

    public function testLeftRightCollapseASelectionToItsEdge(): void
    {
        $m = new TextEditModel('abcdef');
        $m->setCaret(2);
        $m->setCaret(4, extend: true);
        $m->moveLeft();
        self::assertSame(2, $m->caret());
        self::assertFalse($m->hasSelection());

        $m->setCaret(4, extend: true);
        $m->moveRight();
        self::assertSame(4, $m->caret());
        self::assertFalse($m->hasSelection());
    }

    public function testShiftArrowsExtendTheSelection(): void
    {
        $m = new TextEditModel('abcdef', 2);
        $m->moveRight(extend: true);
        $m->moveRight(extend: true);
        self::assertSame([2, 4], $m->selectionRange());
        self::assertSame('cd', $m->selectedText());

        $m->moveLeft(extend: true);
        $m->moveLeft(extend: true);
        $m->moveLeft(extend: true);
        self::assertSame([1, 2], $m->selectionRange(), 'the anchor stays, the caret crosses it');
        self::assertSame(1, $m->caret());
    }

    public function testWordJumps(): void
    {
        $m = new TextEditModel('foo.bar  baz', 0);
        $m->moveRight(word: true);
        self::assertSame(3, $m->caret());
        $m->moveRight(word: true);
        self::assertSame(4, $m->caret(), 'punctuation is its own run');
        $m->moveRight(word: true);
        self::assertSame(7, $m->caret());
        $m->moveRight(word: true);
        self::assertSame(12, $m->caret(), 'spaces are skipped before the next word');

        $m->moveLeft(word: true);
        self::assertSame(9, $m->caret());
    }

    public function testUpDownKeepTheColumnAcrossShortLines(): void
    {
        $m = new TextEditModel("abcdef\nab\nabcdef", 5);
        $m->moveDown();
        self::assertSame(9, $m->caret(), 'clamped to the short line');
        $m->moveDown();
        self::assertSame(15, $m->caret(), 'the column is remembered');
        $m->moveUp(extend: true);
        self::assertSame([9, 15], $m->selectionRange());
    }

    public function testUpOnTheFirstLineGoesToTheStartAndDownOnTheLastToTheEnd(): void
    {
        $m = new TextEditModel("abc\ndef", 2);
        $m->moveUp();
        self::assertSame(0, $m->caret());
        $m->setCaret(5);
        $m->moveDown();
        self::assertSame(7, $m->caret());
    }

    public function testSmartHomeTogglesBetweenTheIndentAndColumnZero(): void
    {
        $m = new TextEditModel("x\n    foo()", 11);
        $m->home();
        self::assertSame(6, $m->caret(), 'first non-space');
        $m->home();
        self::assertSame(2, $m->caret(), 'column 0');
        $m->home();
        self::assertSame(6, $m->caret());

        $m->end();
        self::assertSame(11, $m->caret());
        $m->home(extend: true);
        self::assertSame('foo()', $m->selectedText());
    }

    public function testDocumentStartAndEnd(): void
    {
        $m = new TextEditModel("ab\ncd\nef", 4);
        $m->documentEnd(extend: true);
        self::assertSame("d\nef", $m->selectedText());
        $m->documentStart();
        self::assertSame(0, $m->caret());
        self::assertFalse($m->hasSelection());
    }

    public function testSelectAllAndWordSelection(): void
    {
        $m = new TextEditModel('let total = sum(a, b);', 0);
        $m->selectAll();
        self::assertSame('let total = sum(a, b);', $m->selectedText());

        $m->selectWordAt(6);
        self::assertSame('total', $m->selectedText());
        $m->selectWordAt(15);
        self::assertSame('(', $m->selectedText(), 'punctuation selects itself');
        $m->selectWordAt(22);
        self::assertSame(';', $m->selectedText(), 'past the end: the last character');
    }

    public function testWordSelectionHandlesNonLatinLetters(): void
    {
        $m = new TextEditModel('x = größe + 漢字;', 0);
        $m->selectWordAt(6);
        self::assertSame('größe', $m->selectedText());
        $m->selectWordAt(13);
        self::assertSame('漢字', $m->selectedText());
    }

    // ── Enter: auto-indent ──────────────────────────────────────────

    public function testEnterKeepsTheIndentation(): void
    {
        $m = new TextEditModel('    $a = 1;', 11);
        $m->newline();

        self::assertSame("    \$a = 1;\n    ", $m->text());
        self::assertSame(16, $m->caret());
    }

    public function testEnterAfterAnOpenerIndentsOneLevel(): void
    {
        foreach (['{', '(', '[', ':'] as $opener) {
            $m = new TextEditModel("  x {$opener}", 5);
            $m->newline();
            self::assertSame("  x {$opener}\n      ", $m->text(), "after {$opener}");
        }
    }

    public function testEnterAfterAnOpenerIgnoresTrailingSpaces(): void
    {
        $m = new TextEditModel('if (a) {  ', 10);
        $m->newline();
        self::assertSame("if (a) {  \n    ", $m->text());
    }

    public function testBraceRulesDoNotIndentAfterAColon(): void
    {
        $m = new TextEditModel('a ? b :', 7, IndentRules::braces());
        $m->newline();
        self::assertSame("a ? b :\n", $m->text());
    }

    public function testEnterBetweenMatchingBracketsPutsTheCloserOnItsOwnLine(): void
    {
        $m = new TextEditModel('  f() {}', 7);
        $m->newline();

        self::assertSame("  f() {\n      \n  }", $m->text());
        self::assertSame(14, $m->caret(), 'on the indented middle line');
    }

    public function testEnterOnAWhitespaceOnlyLineLeavesNoTrailingSpaces(): void
    {
        $m = new TextEditModel("{\n    ", 6);
        $m->newline();

        self::assertSame("{\n\n    ", $m->text());
        self::assertSame(7, $m->caret());
    }

    public function testEnterReplacesTheSelection(): void
    {
        $m = new TextEditModel('ab cd', 0);
        $m->setCaret(2);
        $m->setCaret(3, extend: true);
        $m->newline();
        self::assertSame("ab\ncd", $m->text());
        self::assertSame(3, $m->caret());
    }

    public function testTypingACloserOnAnIndentOnlyLineDedentsIt(): void
    {
        $m = new TextEditModel("if (a) {\n        ", 17);
        $m->insert('}');
        self::assertSame("if (a) {\n    }", $m->text());
        self::assertSame(14, $m->caret());

        $m = new TextEditModel("f(\n    a,\n    ", 14);
        $m->insert(')');
        self::assertSame("f(\n    a,\n)", $m->text());
    }

    public function testACloserAfterCodeIsJustInserted(): void
    {
        $m = new TextEditModel('    x', 5);
        $m->insert('}');
        self::assertSame('    x}', $m->text());
    }

    // ── Tab / Shift+Tab ─────────────────────────────────────────────

    public function testTabInsertsSpacesToTheNextStop(): void
    {
        $m = new TextEditModel('ab', 2);
        $m->indent();
        self::assertSame('ab  ', $m->text());
        self::assertSame(4, $m->caret());
        $m->indent();
        self::assertSame('ab      ', $m->text());
    }

    public function testTheIndentWidthIsConfigurable(): void
    {
        $m = new TextEditModel('', 0, new IndentRules(width: 2));
        $m->indent();
        self::assertSame('  ', $m->text());

        $m = new TextEditModel("x\n  y", 0, new IndentRules(width: 2));
        $m->setCaret(5);
        $m->outdent();
        self::assertSame("x\ny", $m->text());
    }

    public function testTabsInsteadOfSpacesWhenAsked(): void
    {
        $m = new TextEditModel('{', 1, new IndentRules(useTabs: true));
        $m->newline();
        self::assertSame("{\n\t", $m->text());
        $m->indent();
        self::assertSame("{\n\t\t", $m->text());
    }

    public function testTabOverALineSelectionIndentsEveryLine(): void
    {
        $m = new TextEditModel("a\n\nb\nc", 0);
        $m->setCaret(0);
        $m->setCaret(5, extend: true); // a, empty, b
        $m->indent();

        self::assertSame("    a\n\n    b\nc", $m->text(), 'empty lines stay empty');
        self::assertSame("    a\n\n    b", $m->selectedText(), 'the selection covers the whole lines');
    }

    public function testASelectionEndingAtColumnZeroDoesNotTouchThatLine(): void
    {
        $m = new TextEditModel("a\nb\nc", 0);
        $m->setCaret(4, extend: true); // "a\nb\n" up to column 0 of "c"
        $m->indent();
        self::assertSame("    a\n    b\nc", $m->text());
    }

    public function testShiftTabOutdentsTheCurrentLineAndKeepsTheCaretOnItsCharacter(): void
    {
        $m = new TextEditModel("x\n      foo", 10); // between the two o
        $m->outdent();
        self::assertSame("x\n  foo", $m->text());
        self::assertSame(6, $m->caret());

        $m->outdent();
        self::assertSame("x\nfoo", $m->text(), 'only what is there');
        self::assertSame(4, $m->caret());
    }

    public function testShiftTabRemovesALeadingTab(): void
    {
        $m = new TextEditModel("\t\tfoo", 5);
        $m->outdent();
        self::assertSame("\tfoo", $m->text());
    }

    public function testShiftTabOverASelectionOutdentsEveryLine(): void
    {
        $m = new TextEditModel("    a\n  b\nc", 0);
        $m->selectAll();
        $m->outdent();
        self::assertSame("a\nb\nc", $m->text());
        self::assertSame("a\nb\nc", $m->selectedText());
    }

    public function testShiftTabWithoutIndentationIsNoUndoStep(): void
    {
        $m = new TextEditModel('foo', 1);
        $m->outdent();
        self::assertFalse($m->canUndo());
    }

    // ── clipboard operations ────────────────────────────────────────

    public function testPasteNormalisesLineEndingsAndReplacesTheSelection(): void
    {
        $m = new TextEditModel('A_B', 0);
        $m->setCaret(1);
        $m->setCaret(2, extend: true);
        $m->paste("x\r\ny\rz");

        self::assertSame("Ax\ny\nzB", $m->text());
        self::assertSame(6, $m->caret());
    }

    public function testCutReturnsAndRemovesTheSelection(): void
    {
        $m = new TextEditModel('keep cut keep', 0);
        $m->setCaret(5);
        $m->setCaret(9, extend: true);

        self::assertSame('cut ', $m->cut());
        self::assertSame('keep keep', $m->text());
        self::assertSame('', $m->cut(), 'nothing selected: nothing cut');
        self::assertSame('keep keep', $m->text());
    }

    // ── undo / redo ─────────────────────────────────────────────────

    public function testATypingRunIsOneUndoStep(): void
    {
        $m = new TextEditModel('', 0);
        foreach (['a', 'b', 'c'] as $ch) {
            $m->insert($ch);
        }
        self::assertTrue($m->undo());
        self::assertSame('', $m->text());
        self::assertSame(0, $m->caret());
        self::assertFalse($m->canUndo());
    }

    public function testMovingTheCaretEndsATypingRun(): void
    {
        $m = new TextEditModel('', 0);
        $m->insert('a');
        $m->insert('b');
        $m->moveLeft();
        $m->moveRight();
        $m->insert('c');

        $m->undo();
        self::assertSame('ab', $m->text());
        $m->undo();
        self::assertSame('', $m->text());
    }

    public function testEnterPasteIndentAndCutAreStepsOfTheirOwn(): void
    {
        $m = new TextEditModel('', 0);
        $m->insert('a');
        $m->newline();
        $m->paste('xy');
        $m->indent();
        $m->selectAll();
        $m->cut();

        $expected = ["a\nxy  ", "a\nxy", "a\n", 'a', ''];
        foreach ($expected as $text) {
            self::assertTrue($m->undo());
            self::assertSame($text, $m->text());
        }
        self::assertFalse($m->undo());
    }

    public function testABackspaceRunIsOneStep(): void
    {
        $m = new TextEditModel('abcdef', 6);
        $m->backspace();
        $m->backspace();
        $m->backspace();
        $m->undo();
        self::assertSame('abcdef', $m->text());
        self::assertSame(6, $m->caret());
    }

    public function testTypingAfterDeletingStartsANewStep(): void
    {
        $m = new TextEditModel('abc', 3);
        $m->backspace();
        $m->insert('X');
        $m->undo();
        self::assertSame('ab', $m->text());
    }

    public function testUndoRestoresTheSelectionAndRedoReappliesTheEdit(): void
    {
        $m = new TextEditModel('hello', 0);
        $m->selectAll();
        $m->insert('X');

        $m->undo();
        self::assertSame('hello', $m->text());
        self::assertSame([0, 5], $m->selectionRange());

        self::assertTrue($m->redo());
        self::assertSame('X', $m->text());
        self::assertSame(1, $m->caret());
        self::assertFalse($m->redo());
    }

    public function testANewEditClearsTheRedoHistory(): void
    {
        $m = new TextEditModel('', 0);
        $m->insert('a');
        $m->undo();
        $m->insert('b');
        self::assertFalse($m->canRedo());
        self::assertSame('b', $m->text());
    }

    public function testUndoAfterRedoKeepsGroupsApart(): void
    {
        $m = new TextEditModel('', 0);
        $m->insert('a');
        $m->undo();
        $m->redo();
        $m->insert('b'); // must not merge into the redone step
        $m->undo();
        self::assertSame('a', $m->text());
    }

    public function testTheHistoryIsBounded(): void
    {
        $m = new TextEditModel('', 0);
        for ($i = 0; $i < TextEditModel::MAX_UNDO + 20; $i++) {
            $m->paste('x');
        }
        $steps = 0;
        while ($m->undo()) {
            $steps++;
        }
        self::assertSame(TextEditModel::MAX_UNDO, $steps);
    }

    public function testSetTextStartsAFreshHistory(): void
    {
        $m = new TextEditModel('', 0);
        $m->insert('a');
        $m->setText('other', 2);

        self::assertSame('other', $m->text());
        self::assertSame(2, $m->caret());
        self::assertFalse($m->canUndo());
        self::assertFalse($m->hasSelection());
    }

    public function testSetCaretIsClamped(): void
    {
        $m = new TextEditModel('abc', 99);
        self::assertSame(3, $m->caret());
        $m->setCaret(-5);
        self::assertSame(0, $m->caret());
    }

    public function testCaretLineAndColumn(): void
    {
        $m = new TextEditModel("ab\ncde", 5);
        self::assertSame([1, 2], $m->lineAndColumn(5));
        self::assertSame([0, 2], $m->lineAndColumn(2));
        self::assertSame(3, $m->indexAt(1, 0));
        self::assertSame(6, $m->indexAt(1, 99), 'the column is clamped to the line');
    }
}
