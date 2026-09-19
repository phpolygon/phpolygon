<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPUnit\Framework\TestCase;
use PHPolygon\Command\Command;
use PHPolygon\Command\CommandBus;
use PHPolygon\Command\CommandRegistry;
use PHPolygon\Command\Refusal;
use PHPolygon\UI\Widget\CommandWidgetContext;
use PHPolygon\UI\Widget\DataWidgetContext;
use PHPolygon\UI\Widget\ScopedWidgetContext;

final class Ledger
{
    public int $value = 0;

    /** @var list<string> */
    public array $done = [];
}

final class Book extends Command
{
    public function __construct(
        public readonly string $entry,
        public readonly int $amount = 1,
        public readonly bool $twice = false,
    ) {}

    public static function type(): string { return 'ledger.book'; }
}

/**
 * A panel layout naming a command instead of a handler: the click builds it
 * from the row it belongs to and hands it to the bus, so it travels to a host
 * like any other command.
 */
final class CommandWidgetContextTest extends TestCase
{
    private Ledger $ledger;
    private CommandBus $bus;
    private CommandWidgetContext $context;

    /** @var array<string, mixed> */
    private array $vm = [];

    protected function setUp(): void
    {
        $this->ledger = new Ledger();
        $this->bus = new CommandBus(
            check: static fn (object $state, Command $c): ?Refusal => $c instanceof Book && $c->entry === 'closed'
                ? new Refusal('ledger.closed')
                : null,
            apply: static function (object $state, Command $c): void {
                assert($state instanceof Ledger && $c instanceof Book);
                $state->value += $c->amount * ($c->twice ? 2 : 1);
                $state->done[] = $c->entry;
            },
            refused: static function (object $state, Refusal $r): void {
                assert($state instanceof Ledger);
                $state->done[] = 'refused:' . $r->key;
            },
        );
        $this->vm = ['rows' => [], 'plain' => 'x'];
        $this->context = new CommandWidgetContext(
            new DataWidgetContext($this->vm, ['ownAction' => function (mixed ...$args): void {
                $this->ledger->done[] = 'own:' . count($args);
            }]),
            new CommandRegistry([Book::class]),
            $this->bus,
            $this->ledger,
        );
    }

    public function testARowsCommandIsBuiltFromTheRow(): void
    {
        $row = new ScopedWidgetContext(['entry' => 'rent', 'amount' => 30], $this->context);

        $row->call('cmd:ledger.book');

        $this->assertSame(30, $this->ledger->value);
        $this->assertSame(['rent'], $this->ledger->done);
    }

    public function testTheLayoutMaySayWhatTheRowDoesNot(): void
    {
        $row = new ScopedWidgetContext(['entry' => 'rent', 'amount' => 30], $this->context);

        $row->call('cmd:ledger.book?twice=true');

        $this->assertSame(60, $this->ledger->value, 'the literal is read as the constructor declares it');
    }

    public function testALiteralWinsOverTheRow(): void
    {
        $row = new ScopedWidgetContext(['entry' => 'rent', 'amount' => 30], $this->context);

        $row->call('cmd:ledger.book?amount=1');

        $this->assertSame(1, $this->ledger->value);
    }

    public function testAButtonWithoutARowCarriesItsOwnData(): void
    {
        $this->context->call('cmd:ledger.book?entry=fee&amount=7');

        $this->assertSame(7, $this->ledger->value);
        $this->assertSame(['fee'], $this->ledger->done);
    }

    public function testTheInnermostRowOfNestedRepeatersIsTheOne(): void
    {
        $outer = new ScopedWidgetContext(['entry' => 'outer', 'amount' => 100], $this->context);
        $inner = new ScopedWidgetContext(['entry' => 'inner', 'amount' => 5], $outer);

        $inner->call('cmd:ledger.book');

        $this->assertSame(['inner'], $this->ledger->done);
        $this->assertSame(5, $this->ledger->value);
    }

    public function testARefusedCommandTellsThePlayerAndChangesNothing(): void
    {
        $row = new ScopedWidgetContext(['entry' => 'closed', 'amount' => 9], $this->context);

        $row->call('cmd:ledger.book');

        $this->assertSame(0, $this->ledger->value);
        $this->assertSame(['refused:ledger.closed'], $this->ledger->done);
    }

    public function testWhatCannotBecomeACommandDoesNothing(): void
    {
        // No entry anywhere, an unknown type, and data that does not fit.
        $this->context->call('cmd:ledger.book');
        $this->context->call('cmd:ledger.nope?entry=x');
        $this->context->call('cmd:ledger.book?entry=x&unknown=1');
        (new ScopedWidgetContext(['entry' => 'x', 'amount' => 'many'], $this->context))->call('cmd:ledger.book');

        $this->assertSame(0, $this->ledger->value);
        $this->assertSame([], $this->ledger->done);
    }

    public function testAnOrdinaryActionStillReachesTheViewModel(): void
    {
        $row = new ScopedWidgetContext(['entry' => 'rent'], $this->context);

        $this->context->call('ownAction');
        $row->call('ownAction');

        $this->assertSame(['own:0', 'own:1'], $this->ledger->done);
        $this->assertSame(0, $this->ledger->value);
    }

    public function testReadingAndWritingGoThroughAsBefore(): void
    {
        $this->assertSame('x', $this->context->get('plain'));
        $this->context->set('plain', 'y');
        $this->assertSame('y', $this->context->get('plain'));
    }
}
