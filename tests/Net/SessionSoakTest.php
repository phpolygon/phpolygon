<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Net;

use PHPUnit\Framework\TestCase;
use PHPolygon\Command\Command;
use PHPolygon\Command\CommandBus;
use PHPolygon\Command\CommandRegistry;
use PHPolygon\Command\Refusal;
use PHPolygon\Net\ClientSession;
use PHPolygon\Net\HostSession;
use PHPolygon\Net\LoopbackTransport;
use PHPolygon\Net\Player;

/**
 * A session runs for hours. Nothing it remembers may grow without bound, and
 * nothing may be remembered by a number that PHP hands out again once the
 * thing behind it is gone.
 */
final class SessionSoakTest extends TestCase
{
    private Tally $hostState;
    private LoopbackTransport $hostEnd;
    private HostSession $host;
    private CommandRegistry $registry;

    protected function setUp(): void
    {
        $this->hostState = new Tally();
        $this->registry = new CommandRegistry([Add::class]);
        $this->hostEnd = LoopbackTransport::host();
        $this->host = new HostSession(
            transport: $this->hostEnd,
            state: $this->hostState,
            hostName: 'Host',
            bus: $this->bus(),
            registry: $this->registry,
            channel: new TallyChannel(),
            version: '1.0.0',
            maxPlayers: 4,
        );
    }

    private function bus(): CommandBus
    {
        return new CommandBus(
            check: static fn (object $state, Command $c): ?Refusal => null,
            apply: static function (object $state, Command $c): void {
                assert($state instanceof Tally && $c instanceof Add);
                $state->value += $c->amount;
            },
            refused: static function (object $state, Refusal $r): void {
                assert($state instanceof Tally);
                $state->told[] = $r->key;
            },
        );
    }

    private function join(int $account): ClientSession
    {
        $client = new ClientSession(
            transport: $this->hostEnd->connectClient($account),
            state: new Tally(),
            name: 'P' . $account,
            bus: $this->bus(),
            registry: $this->registry,
            channel: new TallyChannel(),
            version: '1.0.0',
        );
        $this->settle($client);
        return $client;
    }

    private function settle(ClientSession ...$clients): void
    {
        for ($i = 0; $i < 4; $i++) {
            foreach ($clients as $client) {
                $client->tick(0.1);
            }
            $this->host->tick(HostSession::STATE_INTERVAL);
        }
        foreach ($clients as $client) {
            $client->tick(0.1);
        }
    }

    private function peek(string $property): mixed
    {
        return (new \ReflectionProperty(HostSession::class, $property))->getValue($this->host);
    }

    public function testAGameNobodyPlaysAnyMoreIsForgotten(): void
    {
        $games = [];
        $this->host->setStates(static function (Player $player) use (&$games): object {
            return $games[$player->peer] ??= new Tally();
        });
        $client = $this->join(1);
        $this->settle($client);
        $this->assertCount(1, $this->peek('lastSeen'), 'the game it sent is remembered');

        $client->close();
        $this->settle();
        $games = [];
        gc_collect_cycles();

        $this->assertCount(0, $this->peek('lastSeen'), 'and forgotten with the game itself');
    }

    public function testAFloodOfCommandsDoesNotPileUpForever(): void
    {
        $client = $this->join(1);
        for ($i = 0; $i < HostSession::MAX_QUEUED * 3; $i++) {
            $client->sendCommand(new Add(1));
        }

        $this->host->tick(0.01);
        $queued = array_sum(array_map('count', $this->peek('queue')));

        $this->assertLessThanOrEqual(HostSession::MAX_QUEUED, $queued, 'the queue has a ceiling');
        $this->settle($client);
        $this->assertNotSame([], $client->state()->told, 'what did not fit is refused, not swallowed');
    }

    public function testHoursOfPlayLeaveNothingBehind(): void
    {
        $a = $this->join(1);
        $b = $this->join(2);
        $this->settle($a, $b);
        gc_collect_cycles();
        $before = memory_get_usage();

        // Roughly an hour of play: a state every quarter second, commands and
        // news all along.
        for ($round = 0; $round < 2_000; $round++) {
            $this->hostState->news[] = 'round ' . $round;
            $a->sendCommand(new Add(1));
            if ($round % 3 === 0) {
                $b->sendCommand(new Add(2));
            }
            $a->tick(0.25);
            $b->tick(0.25);
            $this->host->tick(HostSession::STATE_INTERVAL);
            // The partners' screens keep only what is on them.
            $a->state()->told = [];
            $b->state()->told = [];
        }
        gc_collect_cycles();

        $this->assertSame([], $this->hostState->news, 'every line went out');
        // 2000 from one partner, 2 per third round from the other.
        $this->assertSame(2_000 + 2 * (int) ceil(2_000 / 3), $this->hostState->value, 'every command was applied');
        $this->assertLessThanOrEqual(2, count($this->peek('lastSeen')));
        $this->assertSame(0, array_sum(array_map('count', $this->peek('queue'))), 'nothing waiting');
        $this->assertLessThan(4 * 1024 * 1024, memory_get_usage() - $before, 'the session does not grow');
    }
}
