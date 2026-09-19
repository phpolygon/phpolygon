<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPolygon\Command\Command;
use PHPolygon\Command\CommandBus;
use PHPolygon\Command\CommandRegistry;
use PHPolygon\Command\Refusal;

final class Counter
{
    public int $value = 0;

    /** @var list<string> */
    public array $told = [];
}

final class AddCommand extends Command
{
    public function __construct(public readonly int $amount, public readonly string $note = '') {}

    public static function type(): string { return 'counter.add'; }
}

final class ClearCommand extends Command
{
    public static function type(): string { return 'counter.clear'; }
}

final class CommandBusTest extends TestCase
{
    private Counter $counter;
    private CommandBus $bus;

    protected function setUp(): void
    {
        $this->counter = new Counter();
        $this->bus = new CommandBus(
            check: static fn (object $state, Command $c): ?Refusal => $c instanceof AddCommand && $c->amount < 0
                ? new Refusal('counter.negative', ['amount' => $c->amount])
                : null,
            apply: static function (object $state, Command $c): void {
                assert($state instanceof Counter);
                $state->value = $c instanceof AddCommand ? $state->value + $c->amount : 0;
            },
            refused: static function (object $state, Refusal $r): void {
                assert($state instanceof Counter);
                $state->told[] = $r->key;
            },
        );
    }

    public function testACommandIsCheckedAndApplied(): void
    {
        $this->assertTrue($this->bus->dispatch($this->counter, new AddCommand(3)));
        $this->assertSame(3, $this->counter->value);
    }

    public function testARefusalIsToldAndNothingChanges(): void
    {
        $this->assertFalse($this->bus->dispatch($this->counter, new AddCommand(-1)));
        $this->assertSame(0, $this->counter->value);
        $this->assertSame(['counter.negative'], $this->counter->told);
    }

    public function testWithARemoteTheCommandIsSentNotApplied(): void
    {
        $sent = [];
        $this->bus->setRemote(static function (Command $c) use (&$sent): void { $sent[] = $c; });

        $this->assertTrue($this->bus->isRemote());
        $this->assertTrue($this->bus->dispatch($this->counter, new AddCommand(2)));
        $this->assertFalse($this->bus->dispatch($this->counter, new AddCommand(-2)), 'the obvious is answered here');

        $this->assertSame(0, $this->counter->value);
        $this->assertCount(1, $sent);
    }

    public function testTheLocalGuardComesFirst(): void
    {
        $this->bus->setLocalGuard(static fn (Command $c): ?Refusal => $c instanceof ClearCommand ? new Refusal('counter.not_yours') : null);

        $this->assertFalse($this->bus->dispatch($this->counter, new ClearCommand()));
        $this->assertSame(['counter.not_yours'], $this->counter->told);
        $this->assertTrue($this->bus->dispatch($this->counter, new AddCommand(1)));
    }

    public function testADeferralHoldsACheckedCommandBack(): void
    {
        $this->bus->setDeferral(static fn (Command $c): Refusal|bool => $c instanceof AddCommand && $c->amount > 10);

        $this->assertTrue($this->bus->dispatch($this->counter, new AddCommand(50)), 'held counts as taken');
        $this->assertSame(0, $this->counter->value);
        $this->assertTrue($this->bus->dispatch($this->counter, new AddCommand(5)));
        $this->assertSame(5, $this->counter->value);
    }

    public function testTheGameHearsOfEveryCommandAppliedHere(): void
    {
        $heard = [];
        $this->bus->setApplied(static function (Command $c) use (&$heard): void { $heard[] = $c::type(); });

        $this->bus->dispatch($this->counter, new AddCommand(1));
        $this->bus->dispatch($this->counter, new AddCommand(-1));

        $this->assertSame(['counter.add'], $heard);
    }

    public function testExecuteSaysWhyNot(): void
    {
        $refusal = $this->bus->execute($this->counter, new AddCommand(-4));

        $this->assertNotNull($refusal);
        $this->assertSame(['amount' => -4], $refusal->params);
        $this->assertNull($this->bus->execute($this->counter, new AddCommand(4)));
        $this->assertSame(4, $this->counter->value);
    }

    public function testCommandsTravelThroughTheRegistry(): void
    {
        $registry = new CommandRegistry([AddCommand::class, ClearCommand::class]);

        $wire = json_decode(json_encode($registry->encode(new AddCommand(7, 'x')), JSON_THROW_ON_ERROR), true);
        $back = $registry->decode($wire);

        $this->assertInstanceOf(AddCommand::class, $back);
        $this->assertSame(7, $back->amount);
        $this->assertSame('x', $back->note);
        $this->assertNull($registry->decode(['type' => 'counter.add', 'data' => ['amount' => 'many']]), 'wrong type');
        $this->assertNull($registry->decode(['type' => 'counter.add', 'data' => ['amount' => 1, 'extra' => 2]]), 'unknown value');
        $this->assertNull($registry->decode(['type' => 'nope', 'data' => []]));
        $this->assertNull($registry->decode('garbage'));
    }

    public function testTwoCommandsCannotShareAName(): void
    {
        $this->expectException(\LogicException::class);
        new CommandRegistry([AddCommand::class, TwinCommand::class]);
    }
}

final class TwinCommand extends Command
{
    public static function type(): string { return 'counter.add'; }
}
