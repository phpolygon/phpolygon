<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Math\Mat3;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextMetrics;
use PHPolygon\Rendering\Texture;
use PHPolygon\Runtime\Input;
use PHPolygon\Runtime\KeyboardLayoutInterface;
use PHPolygon\Runtime\MemoryClipboard;
use PHPolygon\UI\TextAreaView;
use PHPolygon\UI\TextEdit\IndentRules;
use PHPolygon\UI\UIContext;
use PHPUnit\Framework\TestCase;

/**
 * Keys, mouse and clipboard of {@see UIContext::textArea()} driving the
 * editing model: indentation, selection, shortcuts, undo.
 *
 * Geometry with the default style (font 16, padding 6) at begin(10, 10, 300):
 * text origin (22, 16), line height 21.6, 9.6 px per character.
 */
final class UIContextTextEditingTest extends TestCase
{
    private const int SHIFT = 340;
    private const int CTRL = 341;
    private const int ALT = 342;
    private const int SUPER = 343;
    private const int RIGHT_ALT = 346;
    private const int ENTER = 257;
    private const int TAB = 258;
    private const int BACKSPACE = 259;
    private const int DELETE = 261;
    private const int RIGHT = 262;
    private const int LEFT = 263;
    private const int DOWN = 264;
    private const int UP = 265;
    private const int HOME = 268;
    private const int END = 269;

    private Input $input;
    private UIContext $ctx;
    private MemoryClipboard $clipboard;

    /** @var list<array{method: string, args: array<int, mixed>}> */
    public array $calls = [];

    private string $value = '';
    private ?IndentRules $rules = null;

    protected function setUp(): void
    {
        $this->input = new Input();
        $this->clipboard = new MemoryClipboard();
        $this->ctx = new UIContext($this->recordingRenderer(), $this->input, clipboard: $this->clipboard);
        $this->ctx->useMacKeys(false);
    }

    /** Focus the area with $text, caret at $caret (end when null). */
    private function open(string $text, ?int $caret = null): void
    {
        $this->value = $text;
        $this->ctx->focusTextField('code', $text, $caret);
    }

    /** One frame; returns (and keeps) the text the area hands back. */
    private function frame(?\Closure $underlay = null): string
    {
        $this->calls = [];
        $this->ctx->begin(10, 10, 300);
        $this->value = $this->ctx->textArea('code', $this->value, 4, $underlay, $this->rules);
        $this->ctx->end();
        $this->input->unsuppress();
        $this->input->endFrame();
        return $this->value;
    }

    /** Press $key (with modifiers held) for one frame, then release everything. */
    private function press(int $key, int ...$mods): string
    {
        foreach ($mods as $mod) {
            $this->input->handleKeyEvent($mod, 1);
        }
        $this->input->handleKeyEvent($key, 1);
        $text = $this->frame();
        $this->input->handleKeyEvent($key, 0);
        foreach ($mods as $mod) {
            $this->input->handleKeyEvent($mod, 0);
        }
        return $text;
    }

    private function type(string $chars, int ...$mods): string
    {
        foreach ($mods as $mod) {
            $this->input->handleKeyEvent($mod, 1);
        }
        foreach (mb_str_split($chars) as $ch) {
            $this->input->handleCharEvent(mb_ord($ch));
        }
        $text = $this->frame();
        foreach ($mods as $mod) {
            $this->input->handleKeyEvent($mod, 0);
        }
        return $text;
    }

    private function mouse(float $col, int $row, ?int $button = null, int $action = 1): void
    {
        $this->input->handleCursorPosEvent(22.0 + $col * 9.6, 16.0 + $row * 21.6 + 5.0);
        if ($button !== null) {
            $this->input->handleMouseButtonEvent($button, $action);
        }
    }

    // ── typing, Tab, Enter ──────────────────────────────────────────

    public function testTypedCharactersAreInserted(): void
    {
        $this->open('ac', 1);
        self::assertSame('abc', $this->type('b'));
    }

    public function testTabIndentsAndShiftTabOutdents(): void
    {
        $this->open('x', 0);
        self::assertSame('    x', $this->press(self::TAB));
        self::assertSame('        x', $this->press(self::TAB));
        self::assertSame('    x', $this->press(self::TAB, self::SHIFT));
    }

    public function testTabOverASelectedBlockIndentsEveryLine(): void
    {
        $this->open("a\nb", 0);
        $this->press(self::END, self::CTRL, self::SHIFT);
        self::assertSame("    a\n    b", $this->press(self::TAB));
        self::assertSame("a\nb", $this->press(self::TAB, self::SHIFT));
    }

    public function testEnterAutoIndents(): void
    {
        $this->open('    if (x) {');
        self::assertSame("    if (x) {\n        ", $this->press(self::ENTER));
    }

    public function testTheIndentRulesComeFromTheCaller(): void
    {
        $this->rules = IndentRules::braces(width: 2);
        $this->open('case 1:');
        self::assertSame("case 1:\n", $this->press(self::ENTER), 'brace rules: no block after a colon');

        $this->open('{');
        self::assertSame("{\n  ", $this->press(self::ENTER));
    }

    public function testTypingAClosingBraceDedents(): void
    {
        $this->open("{\n        ");
        self::assertSame("{\n    }", $this->type('}'));
    }

    // ── caret keys and keyboard selection ───────────────────────────

    public function testHomeEndAndControlHomeEnd(): void
    {
        $this->open("a\n    bc\nd", 8);
        $this->press(self::HOME);
        self::assertSame("a\n    Xbc\nd", $this->type('X'));
        $this->press(self::END);
        self::assertSame("a\n    XbcY\nd", $this->type('Y'));
        $this->press(self::HOME, self::CTRL);
        self::assertSame("_a\n    XbcY\nd", $this->type('_'));
        $this->press(self::END, self::CTRL);
        self::assertSame("_a\n    XbcY\nd!", $this->type('!'));
    }

    public function testShiftArrowsSelectAndTypingReplacesTheSelection(): void
    {
        $this->open('hello world', 0);
        for ($i = 0; $i < 5; $i++) {
            $this->press(self::RIGHT, self::SHIFT);
        }
        self::assertSame('bye world', $this->type('bye'));
    }

    public function testShiftHomeAndEndSelect(): void
    {
        $this->open('abc def', 4);
        $this->press(self::END, self::SHIFT);
        self::assertSame('abc ', $this->press(self::DELETE));
    }

    public function testControlArrowsJumpWords(): void
    {
        $this->open('foo bar baz', 0);
        $this->press(self::RIGHT, self::CTRL);
        $this->press(self::RIGHT, self::CTRL);
        self::assertSame('foo bar! baz', $this->type('!'));
        $this->press(self::LEFT, self::CTRL, self::SHIFT); // the punctuation run "!"
        self::assertSame('foo bar baz', $this->press(self::BACKSPACE));
    }

    public function testControlBackspaceDeletesTheWordBeforeTheCaret(): void
    {
        $this->open('foo barbaz', 10);
        self::assertSame('foo ', $this->press(self::BACKSPACE, self::CTRL));
    }

    public function testUpDownMoveBetweenLines(): void
    {
        $this->open("abc\nde", 2);
        $this->press(self::DOWN);
        self::assertSame("abc\nde!", $this->type('!'));
        $this->press(self::UP);
        self::assertSame("abc?\nde!", $this->type('?'));
    }

    // ── shortcuts and clipboard ─────────────────────────────────────

    public function testControlCharactersAreNotTyped(): void
    {
        $this->open('x', 1);
        self::assertSame('x', $this->type('v', self::CTRL));
    }

    public function testAltGrStillTypesCharacters(): void
    {
        // AltGr = Control + right Alt on Windows layouts: '@', '{', ...
        $this->open('a', 1);
        self::assertSame('a@', $this->type('@', self::CTRL, self::RIGHT_ALT));
    }

    public function testSelectAllCopyAndPaste(): void
    {
        $this->open("line 1\nline 2", 0);
        $this->press(65, self::CTRL); // A
        $this->press(67, self::CTRL); // C
        self::assertSame("line 1\nline 2", $this->clipboard->getText());

        $this->press(self::END, self::CTRL);
        self::assertSame("line 1\nline 2line 1\nline 2", $this->press(86, self::CTRL)); // V
    }

    public function testCutRemovesTheSelectionIntoTheClipboard(): void
    {
        $this->open('keep CUT keep', 5);
        for ($i = 0; $i < 4; $i++) {
            $this->press(self::RIGHT, self::SHIFT);
        }
        self::assertSame('keep keep', $this->press(88, self::CTRL)); // X
        self::assertSame('CUT ', $this->clipboard->getText());
    }

    public function testCopyWithoutASelectionKeepsTheClipboard(): void
    {
        $this->clipboard->setText('before');
        $this->open('abc', 1);
        $this->press(67, self::CTRL);
        self::assertSame('before', $this->clipboard->getText());
    }

    public function testPastedWindowsLineEndsBecomeNewlines(): void
    {
        $this->clipboard->setText("a\r\nb");
        $this->open('', 0);
        self::assertSame("a\nb", $this->press(86, self::CTRL));
    }

    public function testUndoAndRedo(): void
    {
        $this->open('', 0);
        $this->type('abc');
        $this->press(self::ENTER);
        $this->type('def');

        self::assertSame("abc\n", $this->press(90, self::CTRL)); // Z
        self::assertSame('abc', $this->press(90, self::CTRL));
        self::assertSame('', $this->press(90, self::CTRL));
        self::assertSame('abc', $this->press(89, self::CTRL)); // Y
        self::assertSame("abc\n", $this->press(90, self::CTRL, self::SHIFT)); // Shift+Z
    }

    public function testShortcutsFollowTheKeyboardLayout(): void
    {
        // QWERTZ: the keycap Z sits at the US 'Y' position (key 89).
        $this->ctx->setKeyboardLayout(new class implements KeyboardLayoutInterface {
            public function keyFor(string $char): int
            {
                return match (strtolower($char)) {
                    'z' => 89,
                    'y' => 90,
                    default => ord(strtoupper($char)),
                };
            }
        });
        $this->open('', 0);
        $this->type('abc');

        self::assertSame('', $this->press(89, self::CTRL), 'Ctrl + keycap Z undoes');
        self::assertSame('abc', $this->press(90, self::CTRL), 'Ctrl + keycap Y redoes');
    }

    public function testMacUsesCommandForShortcutsAndLineJumps(): void
    {
        $this->ctx->useMacKeys(true);
        $this->open("ab\ncd", 4);

        $this->press(self::LEFT, self::SUPER);
        self::assertSame("ab\n!cd", $this->type('!'));
        $this->press(65, self::SUPER); // Cmd+A
        $this->press(67, self::SUPER); // Cmd+C
        self::assertSame("ab\n!cd", $this->clipboard->getText());

        self::assertSame('X', $this->type('X'), 'the selection is replaced');
        self::assertSame('Xq', $this->type('q', self::CTRL), 'Control types on macOS - no shortcut');
    }

    // ── mouse ───────────────────────────────────────────────────────

    public function testDraggingSelects(): void
    {
        $this->value = 'abcdef';
        $this->mouse(1, 0, 0, 1);     // press between a and b
        $this->frame();
        $this->mouse(4, 0);           // drag to between d and e
        $this->frame();
        $this->mouse(4, 0, 0, 0);     // release
        $this->frame();

        self::assertSame('code', $this->ctx->focusedTextFieldId());
        self::assertSame('aXef', $this->type('X'));
    }

    public function testDraggingOutOfTheFieldKeepsFocus(): void
    {
        $this->value = 'abcdef';
        $this->mouse(1, 0, 0, 1);
        $this->frame();
        $this->input->handleCursorPosEvent(600.0, 20.0); // far right, outside
        $this->frame();
        $this->input->handleMouseButtonEvent(0, 0);
        $this->frame();

        self::assertSame('code', $this->ctx->focusedTextFieldId());
        self::assertSame('aX', $this->type('X'), 'selected to the end of the line');
    }

    public function testShiftClickExtendsTheSelection(): void
    {
        $this->open('abcdef', 1);
        $this->frame();
        $this->input->handleKeyEvent(self::SHIFT, 1);
        $this->mouse(5, 0, 0, 1);
        $this->frame();
        $this->mouse(5, 0, 0, 0);
        $this->frame();
        $this->input->handleKeyEvent(self::SHIFT, 0);

        self::assertSame('aXf', $this->type('X'));
    }

    public function testDoubleClickSelectsAWord(): void
    {
        $this->value = 'foo barbaz qux';
        foreach ([1, 0, 1, 0] as $action) {
            $this->mouse(6, 0, 0, $action);
            $this->frame();
        }
        self::assertSame('foo X qux', $this->type('X'));
    }

    public function testAClickOutsideStillUnfocuses(): void
    {
        $this->open('abc');
        $this->frame();
        $this->input->handleCursorPosEvent(600.0, 400.0);
        $this->input->handleMouseButtonEvent(0, 1);
        $this->frame();
        $this->input->handleMouseButtonEvent(0, 0);
        $this->frame();

        self::assertSame('', $this->ctx->focusedTextFieldId());
    }

    // ── drawing ─────────────────────────────────────────────────────

    public function testTheSelectionIsDrawnBetweenTheUnderlayAndTheText(): void
    {
        $this->open("abc\ndef", 1);
        $this->press(self::DOWN, self::SHIFT); // select "bc\nd"
        $this->frame(function (TextAreaView $view): void {
            $this->calls[] = ['method' => 'underlay', 'args' => []];
        });

        $order = array_column($this->calls, 'method');
        $underlay = array_search('underlay', $order, true);
        $firstText = array_search('drawText', $order, true);
        $rects = array_keys($order, 'drawRect', true);
        self::assertCount(2, $rects, 'one band per selected line');
        foreach ($rects as $i) {
            self::assertGreaterThan($underlay, $i);
            self::assertLessThan($firstText, $i);
        }

        // Row 0 from column 1 to its end (plus room for the line break), row 1 up to column 1.
        $first = $this->calls[$rects[0]]['args'];
        self::assertEqualsWithDelta(22.0 + 9.6, $first[0], 0.01);
        self::assertGreaterThan(2 * 9.6, $first[2]);
        $second = $this->calls[$rects[1]]['args'];
        self::assertEqualsWithDelta(22.0, $second[0], 0.01);
        self::assertEqualsWithDelta(9.6, $second[2], 0.01);
    }

    public function testTheViewReportsCaretAndSelection(): void
    {
        $this->open('abcdef', 1);
        $this->press(self::RIGHT, self::SHIFT);
        $this->press(self::RIGHT, self::SHIFT);
        $view = $this->ctx->textAreaView('code');

        self::assertNotNull($view);
        self::assertSame(3, $view->caret);
        self::assertSame(1, $view->selectionStart);
        self::assertSame(3, $view->selectionEnd);
    }

    public function testUndoHistorySurvivesRefocusingWithTheSameText(): void
    {
        $this->open('', 0);
        $text = $this->type('abc');
        $this->ctx->focusTextField('');
        $this->frame();
        $this->ctx->focusTextField('code', $text, 3);

        self::assertSame('', $this->press(90, self::CTRL));
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function recordingRenderer(): Renderer2DInterface
    {
        $test = $this;
        return new class($test) implements Renderer2DInterface {
            public function __construct(private readonly UIContextTextEditingTest $test) {}
            /** @param array<int, mixed> $args */
            private function record(string $method, array $args): void { $this->test->calls[] = ['method' => $method, 'args' => $args]; }
            public function beginFrame(): void {}
            public function endFrame(): void {}
            public function clearFallbackFonts(?string $baseFont = null): void {}
            public function clear(Color $color): void {}
            public function setViewport(int $x, int $y, int $width, int $height): void {}
            public function getWidth(): int { return 1280; }
            public function getHeight(): int { return 720; }
            public function drawRect(float $x, float $y, float $w, float $h, Color $color): void { $this->record('drawRect', func_get_args()); }
            public function drawRectOutline(float $x, float $y, float $w, float $h, Color $color, float $lineWidth = 1.0): void { $this->record('drawRectOutline', func_get_args()); }
            public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void { $this->record('drawRoundedRect', func_get_args()); }
            public function drawRoundedRectOutline(float $x, float $y, float $w, float $h, float $radius, Color $color, float $lineWidth = 1.0): void {}
            public function drawCircle(float $cx, float $cy, float $r, Color $color): void {}
            public function drawCircleOutline(float $cx, float $cy, float $r, Color $color, float $lineWidth = 1.0): void {}
            public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void { $this->record('drawLine', func_get_args()); }
            public function drawText(string $text, float $x, float $y, float $size, Color $color): void { $this->record('drawText', func_get_args()); }
            public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void {}
            public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void {}
            public function drawSprite(Texture $texture, ?Rect $srcRegion, float $x, float $y, float $w, float $h, float $opacity = 1.0): void {}
            public function pushTransform(Mat3 $matrix): void {}
            public function popTransform(): void {}
            public function pushScissor(float $x, float $y, float $w, float $h): void {}
            public function popScissor(): void {}
            public function loadFont(string $name, string $path): void {}
            public function preloadFontAsync(string $name, string $path): void {}
            public function setFont(string $name): void {}
            public function setFontRenderScale(float $scale): void {}
            public function setTextAlign(int $align): void {}
            public function measureText(string $text, float $size): TextMetrics { return new TextMetrics(mb_strlen($text) * $size * 0.6, $size); }
            public function measureTextBox(string $text, float $breakWidth, float $size): TextMetrics { return new TextMetrics($breakWidth, $size); }
            public function fontCoversScript(string $font, \PHPolygon\Rendering\Script $script): bool { return true; }
            public function fontForScript(\PHPolygon\Rendering\Script $script, array $candidates): ?string { return $candidates[0] ?? null; }
            public function addFallbackFont(string $baseFont, string $fallbackFont): void {}
            public function setGlobalAlpha(float $alpha): void {}
            public function drawArc(float $cx, float $cy, float $r, float $startAngle, float $endAngle, Color $color, int $direction = 0): void {}
            public function saveState(): void {}
            public function restoreState(): void {}
            public function beginOffscreenFrame(int $width, int $height): void {}
            public function endOffscreenFrame(): void {}
        };
    }
}
