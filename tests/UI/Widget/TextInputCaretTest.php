<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\TextInput;
use PHPUnit\Framework\TestCase;

/**
 * The caret of a single-line field must stand where the text actually ends.
 *
 * It used to be placed at `cursorPos * fontSize * 0.55` — a fixed estimate of
 * character width, while the text beside it is drawn with a proportional font.
 * The two drifted apart along the line, so after a few words the caret sat well
 * to the left of the character it was supposed to follow. TextArea has always
 * measured its caret; this pins the same for TextInput.
 */
final class TextInputCaretTest extends TestCase
{
    private const FIELD_X = 40.0;
    private const PADDING = 6.0;

    /** @return array{WidgetTestHelper, UIStyle} */
    private function draw(TextInput $input): array
    {
        $style = UIStyle::dark();
        $renderer = new WidgetTestHelper();

        $input->measure(300.0, 0.0, $style);
        $input->setBounds(new Rect(self::FIELD_X, 20.0, 300.0, $style->fontSize + 2 * self::PADDING));
        $input->layout($style);
        $input->draw($renderer, $style);

        return [$renderer, $style];
    }

    /** The x of the caret rect: the only 1.5-wide drawRect the widget makes. */
    private function caretX(WidgetTestHelper $renderer): ?float
    {
        foreach ($renderer->calls as $call) {
            if ($call['method'] === 'drawRect' && $call['args'][2] === 1.5) {
                return $call['args'][0];
            }
        }
        return null;
    }

    /** Where the field's text is drawn from. */
    private function textX(WidgetTestHelper $renderer): ?float
    {
        foreach ($renderer->calls as $call) {
            if ($call['method'] === 'drawText') {
                return $call['args'][1];
            }
        }
        return null;
    }

    public function testTheCaretSitsAtTheMeasuredEndOfTheText(): void
    {
        $input = new TextInput('', 'Mitarbeiter kann nicht trainiert werden');
        $input->focused = true;

        [$renderer, $style] = $this->draw($input);

        $expected = $this->textX($renderer) + $renderer->measureText($input->text, $style->fontSize)->width;
        self::assertNotNull($this->caretX($renderer));
        self::assertEqualsWithDelta($expected, $this->caretX($renderer), 0.001);
    }

    public function testTheCaretFollowsTheTextInsideTheLine(): void
    {
        $input = new TextInput('', 'abcdefghij');
        $input->focused = true;
        $input->cursorPos = 4;

        [$renderer, $style] = $this->draw($input);

        $expected = $this->textX($renderer) + $renderer->measureText('abcd', $style->fontSize)->width;
        self::assertEqualsWithDelta($expected, $this->caretX($renderer), 0.001);
    }

    public function testAnEmptyFieldPutsTheCaretAtTheStartDespiteThePlaceholder(): void
    {
        $input = new TextInput('', '', 'Was ist passiert?');
        $input->focused = true;

        [$renderer] = $this->draw($input);

        self::assertEqualsWithDelta($this->textX($renderer), $this->caretX($renderer), 0.001,
            'the placeholder is not the text: the caret belongs at the start');
    }

    public function testAnUnfocusedFieldDrawsNoCaret(): void
    {
        $input = new TextInput('', 'abc');

        [$renderer] = $this->draw($input);

        self::assertNull($this->caretX($renderer));
    }

    public function testACursorPastTheTextDoesNotOvershoot(): void
    {
        $input = new TextInput('', 'abc');
        $input->focused = true;
        $input->cursorPos = 99; // e.g. text replaced through a binding

        [$renderer, $style] = $this->draw($input);

        $expected = $this->textX($renderer) + $renderer->measureText('abc', $style->fontSize)->width;
        self::assertEqualsWithDelta($expected, $this->caretX($renderer), 0.001);
    }
}
