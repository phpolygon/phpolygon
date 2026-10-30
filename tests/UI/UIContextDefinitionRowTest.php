<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Rendering\Color;
use PHPolygon\Runtime\Input;
use PHPolygon\UI\DefinitionRow;
use PHPolygon\UI\UIContext;
use PHPolygon\UI\UIStyle;
use PHPUnit\Framework\TestCase;

/**
 * definitionRow(): the parts give way by measurement in a fixed order, nothing
 * is cut, and the measured height is what the row takes.
 *
 * RecordingRenderer2D: size 14 = 8.4 px per byte and 16.8 per line, size 12 =
 * 7.2 px per byte and 14.4 per line. For row() below that means term 50.4,
 * note 72, tag 21.6, value with label 45.2: head with value 213.2, without 160.
 */
final class UIContextDefinitionRowTest extends TestCase
{
    private RecordingRenderer2D $renderer;
    private UIContext $ui;

    protected function setUp(): void
    {
        $this->renderer = new RecordingRenderer2D(1280, 720);
        $this->ui = new UIContext($this->renderer, new Input(), new UIStyle(fontName: 'base'));
    }

    private function row(string $body = 'Short.'): DefinitionRow
    {
        return new DefinitionRow(
            term: 'CUT_IN',
            body: $body,
            note: 'const, m/s',
            tag: 'req',
            value: '3',
            valueLabel: 'e.g.',
            termFont: 'mono',
            valueFont: 'mono',
        );
    }

    private function draw(DefinitionRow $row, float $width, float $x = 0.0, float $y = 0.0): float
    {
        $this->renderer->reset();
        $this->ui->begin($x, $y, $width);
        $this->ui->definitionRow($row);
        $bottom = $this->ui->getCursorY();
        $this->ui->end();

        return $bottom;
    }

    public function testEverythingOnTheHeadLineWhenItFits(): void
    {
        $bottom = $this->draw($this->row(), 300.0, 10.0, 100.0);

        self::assertSame(['CUT_IN', 'const, m/s', 'req', 'e.g.', '3', 'Short.'], $this->renderer->texts());
        self::assertEqualsWithDelta(10.0, $this->renderer->box('CUT_IN')['x'], 0.001);
        self::assertEqualsWithDelta(10.0 + 50.4 + 8.0, $this->renderer->box('const, m/s')['x'], 0.001);
        self::assertEqualsWithDelta(10.0 + 130.4 + 8.0, $this->renderer->box('req')['x'], 0.001);
        self::assertEqualsWithDelta(10.0 + 300.0 - 8.4, $this->renderer->box('3')['x'], 0.001, 'value right-aligned');
        self::assertEqualsWithDelta(10.0 + 300.0 - 45.2, $this->renderer->box('e.g.')['x'], 0.001);
        self::assertEqualsWithDelta(100.0 + 20.0, $this->renderer->box('Short.')['y'], 0.001, 'body under the 20 px head line');
        self::assertEqualsWithDelta(100.0 + 20.0 + 16.8 + 6.0, $bottom, 0.001);
    }

    public function testPartsOfOneLineShareACentre(): void
    {
        $this->draw($this->row(), 300.0);

        self::assertEqualsWithDelta(1.6, $this->renderer->box('CUT_IN')['y'], 0.001);
        self::assertEqualsWithDelta(2.8, $this->renderer->box('const, m/s')['y'], 0.001);
    }

    public function testHeightOfOneAndTwoLinesOfBody(): void
    {
        self::assertEqualsWithDelta(42.8, $this->ui->definitionRowHeight($this->row(), 300.0), 0.001);
        self::assertEqualsWithDelta(59.6, $this->ui->definitionRowHeight($this->row(str_repeat('word ', 12)), 300.0), 0.001);
    }

    public function testFirstTheValueMovesBelowTheBody(): void
    {
        $this->draw($this->row(), 200.0);

        self::assertEqualsWithDelta(58.4, $this->renderer->box('const, m/s')['x'], 0.001, 'note stays on the head line');
        self::assertEqualsWithDelta(20.0, $this->renderer->box('Short.')['y'], 0.001);
        self::assertEqualsWithDelta(0.0, $this->renderer->box('e.g.')['x'], 0.001);
        self::assertEqualsWithDelta(36.8, $this->renderer->box('3')['x'], 0.001, 'behind its word');
        self::assertGreaterThan(36.0, $this->renderer->box('3')['y'], 'below the body');
        self::assertEqualsWithDelta(20.0 + 16.8 + 16.8 + 6.0, $this->ui->definitionRowHeight($this->row(), 200.0), 0.001);
    }

    public function testThenNoteAndTagMoveUnderTheTerm(): void
    {
        $this->draw($this->row(), 120.0);

        self::assertEqualsWithDelta(0.0, $this->renderer->box('const, m/s')['x'], 0.001);
        self::assertEqualsWithDelta(20.0, $this->renderer->box('const, m/s')['y'], 0.001);
        self::assertEqualsWithDelta(80.0, $this->renderer->box('req')['x'], 0.001, 'tag stays behind the note');
        self::assertEqualsWithDelta(34.4, $this->renderer->box('Short.')['y'], 0.001);
        self::assertGreaterThan($this->renderer->box('Short.')['y'], $this->renderer->box('3')['y']);
    }

    public function testATermWiderThanTheRowWrapsAndIsNotCut(): void
    {
        $term = 'veryLongIdentifierWithoutAnySpaces';
        $row = new DefinitionRow(term: $term, body: 'Short.', note: 'int');
        $bottom = $this->draw($row, 100.0);

        $drawn = $this->renderer->boxes[0]['text'];
        self::assertSame($term, str_replace("\n", '', $drawn));
        foreach (explode("\n", $drawn) as $line) {
            self::assertLessThanOrEqual(100.0, strlen($line) * 8.4);
        }
        $termLines = substr_count($drawn, "\n") + 1;
        self::assertEqualsWithDelta($termLines * 16.8, $this->renderer->box('int')['y'], 0.001);
        self::assertEqualsWithDelta($termLines * 16.8 + 14.4 + 16.8 + 6.0, $bottom, 0.001);
    }

    public function testNarrowEverythingStaysWithinTheRowAndComplete(): void
    {
        foreach ([24.0, 40.0, 70.0, 110.0, 170.0, 230.0, 500.0] as $width) {
            $row = new DefinitionRow(
                term: 'RPM_PER_MS',
                body: 'Revolutions per minute for each metre per second of wind',
                note: 'constant, revolutions per minute',
                tag: 'required',
                value: 'rotorRpm(10) = 40',
                valueLabel: 'e.g.',
            );
            $bottom = $this->draw($row, $width);

            $all = '';
            foreach ($this->renderer->boxes as $box) {
                $all .= $box['text'] . ' ';
                foreach (explode("\n", $box['text']) as $line) {
                    $w = strlen($line) * 0.6 * $box['size'];
                    self::assertLessThanOrEqual($width + 0.001, $box['x'] + $w, "'$line' at width $width");
                }
            }
            $all = (string) preg_replace('/\s+/', '', $all);
            foreach ([$row->term, $row->body, $row->note, $row->tag, $row->value, $row->valueLabel] as $part) {
                self::assertStringContainsString((string) preg_replace('/\s+/', '', $part), $all, "width $width");
            }
            self::assertEqualsWithDelta($this->ui->definitionRowHeight($row, $width), $bottom, 0.001, 'measuring matches drawing');
        }
    }

    public function testNarrowerNeverGetsShorter(): void
    {
        $row = $this->row('A body that needs a couple of lines when the column gets narrow');
        $last = 0.0;
        foreach ([600.0, 300.0, 200.0, 120.0, 80.0, 40.0] as $width) {
            $height = $this->ui->definitionRowHeight($row, $width);
            self::assertGreaterThanOrEqual($last, $height, "width $width");
            $last = $height;
        }
    }

    public function testCjkBreaksBetweenCharacters(): void
    {
        // 3 bytes each = 25.2 px per character.
        $body = str_repeat('語', 12);
        $this->draw(new DefinitionRow(term: 'x', body: $body), 100.0);

        $drawn = $this->renderer->box(implode("\n", str_split($body, 9)));
        self::assertSame(3, substr_count($drawn['text'], "\n"));
    }

    public function testAHardBreakInTheBodyIsKept(): void
    {
        $this->draw(new DefinitionRow(term: 'x', body: "one\ntwo"), 300.0);

        self::assertSame("one\ntwo", $this->renderer->boxes[1]['text']);
        self::assertEqualsWithDelta(20.0 + 2 * 16.8 + 6.0, $this->ui->definitionRowHeight(new DefinitionRow(term: 'x', body: "one\ntwo"), 300.0), 0.001);
    }

    public function testAbsentPartsDrawNothingAndTakeNoRoom(): void
    {
        $bottom = $this->draw(new DefinitionRow(term: 'x'), 300.0);

        self::assertSame(['x'], $this->renderer->texts());
        self::assertEqualsWithDelta(26.0, $bottom, 0.001);
    }

    public function testATermlessRowStartsWithItsValue(): void
    {
        $this->draw(new DefinitionRow(term: '', body: 'Only text.', value: '7'), 300.0);

        self::assertSame(['7', 'Only text.'], $this->renderer->texts());
        self::assertEqualsWithDelta(20.0, $this->renderer->box('Only text.')['y'], 0.001);
    }

    public function testABodyOnlyRowHasNoHeadLine(): void
    {
        $this->draw(new DefinitionRow(term: '', body: 'Only text.'), 300.0);

        self::assertEqualsWithDelta(0.0, $this->renderer->box('Only text.')['y'], 0.001);
    }

    public function testFontsAndColoursGoToTheirParts(): void
    {
        $term = new Color(0.1, 0.2, 0.3, 1.0);
        $note = new Color(0.4, 0.5, 0.6, 1.0);
        $tag = new Color(0.7, 0.8, 0.9, 1.0);
        $this->draw(new DefinitionRow(
            term: 'T',
            body: 'B',
            note: 'N',
            tag: 'G',
            value: 'V',
            valueLabel: 'L',
            termFont: 'mono',
            valueFont: 'code',
            termColor: $term,
            noteColor: $note,
            tagColor: $tag,
            tagFont: 'bold',
        ), 300.0);

        self::assertSame('mono', $this->renderer->box('T')['font']);
        self::assertSame('code', $this->renderer->box('V')['font']);
        self::assertSame('bold', $this->renderer->box('G')['font']);
        self::assertSame('base', $this->renderer->box('N')['font']);
        self::assertSame('base', $this->renderer->box('B')['font']);
        self::assertSame('base', $this->renderer->font, 'the style font returns');
        self::assertSame($term, $this->renderer->box('T')['color']);
        self::assertSame($note, $this->renderer->box('N')['color']);
        self::assertSame($note, $this->renderer->box('L')['color']);
        self::assertSame($tag, $this->renderer->box('G')['color']);
        self::assertSame($this->ui->getStyle()->textColor, $this->renderer->box('B')['color']);
        self::assertSame(14.0, $this->renderer->box('V')['size']);
        self::assertSame(12.0, $this->renderer->box('L')['size']);
    }

    public function testSizesAndGapComeFromTheRow(): void
    {
        $row = new DefinitionRow(term: 'x', body: 'y', bodySize: 10.0, noteSize: 8.0, rowGap: 0.0);

        self::assertEqualsWithDelta(14.0 + 12.0, $this->ui->definitionRowHeight($row, 300.0), 0.001);
    }

    public function testRowsStackByTheirHeightWithoutItemSpacing(): void
    {
        $a = $this->row();
        $b = $this->row(str_repeat('word ', 12));
        $this->ui->begin(0.0, 0.0, 300.0);
        $this->ui->definitionRow($a);
        $this->ui->definitionRow($b);
        $y = $this->ui->getCursorY();
        $this->ui->end();

        self::assertEqualsWithDelta($this->ui->definitionRowHeight($a, 300.0) + $this->ui->definitionRowHeight($b, 300.0), $y, 0.001);
    }

    public function testAHorizontalFlowStepsByTheWidth(): void
    {
        $this->ui->begin(0.0, 0.0, 300.0, 'horizontal');
        $this->ui->definitionRow($this->row());
        $x = $this->ui->getCursorX();
        $this->ui->end();

        self::assertSame(300.0 + $this->ui->getStyle()->itemSpacing, $x);
    }

    public function testMeasuringHappensOncePerTextAndWidth(): void
    {
        $row = $this->row();
        $this->draw($row, 300.0);
        $first = $this->renderer->measures;
        self::assertGreaterThan(0, $first);

        $this->draw($row, 300.0);
        $this->draw($this->row(), 300.0);
        $this->ui->definitionRowHeight($row, 300.0);
        self::assertSame($first, $this->renderer->measures, 'further frames only look it up');

        $this->draw($row, 200.0);
        self::assertGreaterThan($first, $this->renderer->measures, 'another width measures anew');
    }

    public function testOtherRenderersAndClearingMeasureAnew(): void
    {
        $row = $this->row();
        $this->draw($row, 300.0);
        $count = $this->renderer->measures;

        $this->ui->clearTextLayouts();
        $this->draw($row, 300.0);
        self::assertGreaterThan($count, $this->renderer->measures);

        $other = new RecordingRenderer2D(640, 360);
        [$renderer, $input] = $this->ui->useBackends($other, $this->ui->getInput());
        $this->ui->definitionRowHeight($row, 300.0);
        $this->ui->useBackends($renderer, $input);
        self::assertGreaterThan(0, $other->measures);
    }

    public function testChangingOnlyAColourKeepsTheMeasurementAndShowsTheColour(): void
    {
        $color = new Color(0.3, 0.3, 0.3, 1.0);
        $this->draw(new DefinitionRow(term: 'x'), 300.0);
        $count = $this->renderer->measures;

        $this->draw(new DefinitionRow(term: 'x', termColor: $color), 300.0);

        self::assertSame($count, $this->renderer->measures);
        self::assertSame($color, $this->renderer->box('x')['color']);
    }
}
