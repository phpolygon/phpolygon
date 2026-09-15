<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPUnit\Framework\TestCase;
use PHPolygon\Runtime\Input;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Sizing;
use PHPolygon\UI\Widget\TextArea;
use PHPolygon\UI\Widget\TextInput;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\WidgetLayout;
use PHPolygon\UI\Widget\WidgetTree;

/**
 * A multi-line text field for the retained widget tree.
 *
 * It is a TextInput, so everything that already handles text fields — focus,
 * the two-way `text` binding, a host suppressing hotkeys while one is focused —
 * applies to it unchanged. What it adds is lines: Enter, wrapping, moving
 * between lines, a visible row count, scrolling, and an optional length limit.
 */
class TextAreaTest extends TestCase
{
    private const KEY_ENTER = 257;
    private const KEY_KP_ENTER = 335;
    private const KEY_BACKSPACE = 259;
    private const KEY_DOWN = 264;
    private const KEY_UP = 265;
    private const KEY_HOME = 268;
    private const KEY_END = 269;

    /** Every character 10 units wide, so wrapping is predictable. */
    private static function mono(): \Closure
    {
        return static fn (string $text): float => mb_strlen($text) * 10.0;
    }

    /**
     * @param list<array{int, int}> $lines
     * @return list<string>
     */
    private static function texts(string $text, array $lines): array
    {
        return array_map(static fn (array $l): string => mb_substr($text, $l[0], $l[1] - $l[0]), $lines);
    }

    // ── Wrapping ──────────────────────────────────────────────────────────────

    public function testHardLineBreaksStartNewLines(): void
    {
        $text = "one\ntwo\n\nfour";
        $lines = TextArea::wrap($text, 1000.0, self::mono());

        self::assertSame(['one', 'two', '', 'four'], self::texts($text, $lines));
    }

    public function testLongLinesWrapAtSpaces(): void
    {
        // 100 units = 10 characters per line.
        $text = 'the quick brown fox jumps';
        $lines = TextArea::wrap($text, 100.0, self::mono());

        self::assertSame(['the quick ', 'brown fox ', 'jumps'], self::texts($text, $lines));
    }

    public function testAWordLongerThanALineIsBrokenByCharacters(): void
    {
        $text = 'abcdefghijklmnopqrstuvwxy';
        $lines = TextArea::wrap($text, 100.0, self::mono());

        self::assertSame(['abcdefghij', 'klmnopqrst', 'uvwxy'], self::texts($text, $lines));
    }

    public function testWrappingCountsCharactersNotBytes(): void
    {
        $text = 'äöüäöüäöüäöü';
        $lines = TextArea::wrap($text, 60.0, self::mono());

        self::assertSame(['äöüäöü', 'äöüäöü'], self::texts($text, $lines));
    }

    public function testTextWithoutSpacesFillsTheLineBeforeBreaking(): void
    {
        // Between full-width characters a line may break anywhere, so a line
        // after a space-separated word is filled instead of left short.
        $text = 'F8 キーを押してください';
        $lines = TextArea::wrap($text, 60.0, self::mono());

        self::assertSame(['F8 キーを', '押してくださ', 'い'], self::texts($text, $lines));
    }

    public function testClosingPunctuationNeverStartsALine(): void
    {
        $text = 'あいうえお。かき';
        $lines = TextArea::wrap($text, 50.0, self::mono());

        self::assertSame(['あいうえ', 'お。かき'], self::texts($text, $lines));
    }

    public function testCombiningMarksStayWithTheirLetter(): void
    {
        $text = str_repeat("\u{0E01}\u{0E48}\u{0E32}", 8);

        foreach ([30.0, 40.0, 50.0] as $width) {
            $texts = self::texts($text, TextArea::wrap($text, $width, self::mono()));
            foreach ($texts as $line) {
                self::assertDoesNotMatchRegularExpression('/^\p{M}/u', $line, "width {$width}");
            }
            self::assertSame($text, implode('', $texts));
        }
    }

    public function testEmptyTextIsOneEmptyLine(): void
    {
        self::assertSame([[0, 0]], TextArea::wrap('', 100.0, self::mono()));
    }

    public function testATrailingNewlineOpensAnEmptyLastLine(): void
    {
        // Pressing Enter at the end must put the caret on a new, empty line.
        $text = "abc\n";
        $lines = TextArea::wrap($text, 1000.0, self::mono());

        self::assertSame(['abc', ''], self::texts($text, $lines));
        self::assertSame(1, TextArea::lineOf($lines, 4));
    }

    public function testTheCaretBelongsToTheLineItStandsOn(): void
    {
        $text = 'the quick brown';
        $lines = TextArea::wrap($text, 100.0, self::mono()); // "the quick " | "brown"

        self::assertSame(0, TextArea::lineOf($lines, 0));
        self::assertSame(0, TextArea::lineOf($lines, 9));
        self::assertSame(1, TextArea::lineOf($lines, 10), 'the first character of a wrapped line starts that line');
        self::assertSame(1, TextArea::lineOf($lines, 15));
    }

    // ── Editing ───────────────────────────────────────────────────────────────

    public function testMaxLengthStopsTypingButNotDeleting(): void
    {
        $area = new TextArea();
        $area->maxLength = 5;

        $area->insertChars(['a', 'b', 'c', 'd', 'e', 'f', 'g']);
        self::assertSame('abcde', $area->text);

        $area->newline();
        self::assertSame('abcde', $area->text, 'a newline is a character too');

        $area->backspace();
        $area->newline();
        self::assertSame("abcd\n", $area->text);
    }

    public function testNoMaxLengthMeansNoLimit(): void
    {
        $area = new TextArea();
        $area->insertChars(array_fill(0, 5000, 'x'));

        self::assertSame(5000, mb_strlen($area->text));
    }

    public function testUpAndDownKeepTheHorizontalPosition(): void
    {
        $area = new TextArea('', "abcdef\nabcdef\nab");
        $lines = TextArea::wrap($area->text, 1000.0, self::mono());

        $area->cursorPos = 4; // line 0, after "abcd"
        $area->moveCursorVertically(1, $lines, self::mono());
        self::assertSame(11, $area->cursorPos, 'line 1, after "abcd"');

        $area->moveCursorVertically(1, $lines, self::mono());
        self::assertSame(16, $area->cursorPos, 'line 2 is shorter: the caret stops at its end');

        $area->moveCursorVertically(1, $lines, self::mono());
        self::assertSame(16, $area->cursorPos, 'nothing below the last line');

        $area->cursorPos = 2;
        $area->moveCursorVertically(-1, $lines, self::mono());
        self::assertSame(0, $area->cursorPos, 'up from the first line goes to its start');
    }

    public function testHomeAndEndStayOnTheLine(): void
    {
        $area = new TextArea('', "abcdef\nghijkl");
        $lines = TextArea::wrap($area->text, 1000.0, self::mono());

        $area->cursorPos = 9;
        $area->moveCursorToLineEdge(false, $lines);
        self::assertSame(7, $area->cursorPos);

        $area->moveCursorToLineEdge(true, $lines);
        self::assertSame(13, $area->cursorPos);
    }

    // ── Size ──────────────────────────────────────────────────────────────────

    public function testRowsDecideTheHeight(): void
    {
        $style = UIStyle::dark();
        $three = new TextArea();
        $three->rows = 3;
        $six = new TextArea();
        $six->rows = 6;

        $three->measure(400.0, 600.0, $style);
        $six->measure(400.0, 600.0, $style);

        $lineH = $style->fontSize * $three->lineHeight;
        self::assertEqualsWithDelta(3 * $lineH, $six->getMeasuredHeight() - $three->getMeasuredHeight(), 0.01);
    }

    public function testAFixedHeightStillWins(): void
    {
        $area = (new TextArea())->size(Sizing::fixed(300, 150));
        $area->measure(400.0, 600.0, UIStyle::dark());

        self::assertSame(150.0, $area->getMeasuredHeight());
    }

    // ── In a widget tree ──────────────────────────────────────────────────────

    /** @return array{WidgetTree, TextArea, Input, WidgetTestHelper} */
    private static function focusedTree(TextArea $area): array
    {
        $input = new Input();
        $renderer = new WidgetTestHelper();
        $root = new VBox();
        // Width fixed, height from the rows - as a layout would give it.
        $area->sizing->width = 300.0;
        $root->addChild($area);
        $tree = new WidgetTree($root, $renderer, $input, 800, 600, UIStyle::dark());
        $tree->performLayout();

        $input->handleCursorPosEvent($area->getBounds()->x + 10, $area->getBounds()->y + 60);
        $input->handleMouseButtonEvent(0, 1);
        $tree->processInput();
        $input->endFrame();
        $input->handleMouseButtonEvent(0, 0);

        return [$tree, $area, $input, $renderer];
    }

    public function testItFocusesLikeATextInput(): void
    {
        [$tree, $area] = self::focusedTree(new TextArea());

        self::assertInstanceOf(TextInput::class, $area, 'every text-field code path applies');
        self::assertTrue($area->focused);
        self::assertSame($area, $tree->getFocusedWidget());
    }

    public function testEnterInsertsANewlineAndReportsTheChange(): void
    {
        [$tree, $area, $input] = self::focusedTree(new TextArea());
        $reported = [];
        $area->on('input', static function (string $text) use (&$reported): void { $reported[] = $text; });

        foreach (['h', 'i'] as $c) {
            $input->handleCharEvent(mb_ord($c));
        }
        $input->handleKeyEvent(self::KEY_ENTER, 1);
        $tree->processInput();
        $input->endFrame();

        $input->handleCharEvent(mb_ord('x'));
        $input->handleKeyEvent(self::KEY_KP_ENTER, 1);
        $tree->processInput();

        self::assertSame("hi\nx\n", $area->text);
        self::assertSame("hi\nx\n", end($reported));
    }

    public function testAPlainTextInputStillIgnoresEnter(): void
    {
        $input = new Input();
        $root = new VBox();
        $field = (new TextInput())->size(Sizing::fixed(300, 40));
        $root->addChild($field);
        $tree = new WidgetTree($root, new WidgetTestHelper(), $input, 800, 600, UIStyle::dark());
        $tree->performLayout();
        $tree->setFocus($field);

        $input->handleCharEvent(mb_ord('a'));
        $input->handleKeyEvent(self::KEY_ENTER, 1);
        $tree->processInput();

        self::assertSame('a', $field->text);
    }

    public function testArrowKeysMoveBetweenLinesInTheTree(): void
    {
        $area = new TextArea('', "first\nsecond");
        [$tree, , $input, $renderer] = self::focusedTree($area);
        $tree->draw(); // lays out the lines the keys navigate
        $area->cursorPos = 2;

        $input->handleKeyEvent(self::KEY_DOWN, 1);
        $tree->processInput();
        self::assertSame(8, $area->cursorPos);
        $input->endFrame();

        $input->handleKeyEvent(self::KEY_UP, 1);
        $tree->processInput();
        self::assertSame(2, $area->cursorPos);
        $input->endFrame();

        $input->handleKeyEvent(self::KEY_END, 1);
        $tree->processInput();
        self::assertSame(5, $area->cursorPos);
        $input->endFrame();

        $input->handleKeyEvent(self::KEY_HOME, 1);
        $tree->processInput();
        self::assertSame(0, $area->cursorPos);
        $input->endFrame();

        $input->handleKeyEvent(self::KEY_BACKSPACE, 1);
        $tree->processInput();
        self::assertSame("first\nsecond", $area->text, 'backspace at the very start changes nothing');
    }

    public function testTheViewFollowsTheCaretPastTheLastVisibleRow(): void
    {
        $area = new TextArea('', implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 20))));
        $area->rows = 4;
        [$tree] = self::focusedTree($area);

        $area->cursorPos = mb_strlen($area->text); // end of line 20
        $area->followCaret();
        $tree->draw();

        self::assertSame(16, $area->firstVisibleLine, 'lines 17-20 are shown, the caret on the last of them');

        $area->cursorPos = 0;
        $area->followCaret();
        $tree->draw();

        self::assertSame(0, $area->firstVisibleLine);
    }

    public function testTheWheelScrollsTheTextWithoutMovingTheCaret(): void
    {
        $area = new TextArea('', implode("\n", range(1, 30)));
        $area->rows = 4;
        [$tree, , $input] = self::focusedTree($area);
        $area->cursorPos = 0; // a new field puts the caret at the end; start at the top
        $area->followCaret();
        $tree->draw();
        $caret = $area->cursorPos;

        $input->handleCursorPosEvent($area->getBounds()->x + 10, $area->getBounds()->y + 60);
        $input->handleScrollEvent(0.0, -3.0);
        $tree->processInput();
        $tree->draw();

        self::assertSame(3, $area->firstVisibleLine);
        self::assertSame($caret, $area->cursorPos);
    }

    public function testOnlyTheVisibleRowsAreDrawn(): void
    {
        $area = new TextArea('', implode("\n", array_map(static fn (int $i): string => "row{$i}", range(1, 50))));
        $area->rows = 5;
        [$tree, , , $renderer] = self::focusedTree($area);

        $tree->draw();

        $drawn = array_filter(array_map(static fn (array $call): string => is_string($call['args'][0] ?? null) ? $call['args'][0] : '', array_filter($renderer->calls, static fn (array $call): bool => $call['method'] === 'drawText')), static fn (string $t): bool => str_starts_with($t, 'row'));
        self::assertLessThanOrEqual(5, count($drawn));
    }

    // ── From a layout file ────────────────────────────────────────────────────

    public function testItLoadsFromALayoutWithItsOwnProperties(): void
    {
        $widget = WidgetLayout::fromArray([
            '_format' => 1,
            'name'    => 'form',
            'root'    => [
                '_widget'     => TextArea::class,
                'label'       => 'Details',
                'placeholder' => 'What happened?',
                'rows'        => 6,
                'maxLength'   => 4000,
            ],
        ]);

        self::assertInstanceOf(TextArea::class, $widget);
        self::assertSame(6, $widget->rows);
        self::assertSame(4000, $widget->maxLength);
        self::assertSame('Details', $widget->label);
    }
}
