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

/**
 * A connection can die without saying so: a cable out, a sleeping laptop, a
 * router that forgets the way back. Nothing arrives, nothing is reported, and
 * both ends would wait for each other forever - the host holding a seat, a
 * co-op role and a company for somebody who is long gone.
 *
 * So each end says something even when it has nothing to say, and gives up on
 * the other after {@see HostSession::SILENCE_TIMEOUT} of quiet.
 */
final class SilenceTest extends TestCase
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

    private function join(): ClientSession
    {
        $client = new ClientSession(
            transport: $this->hostEnd->connectClient(42),
            state: new Tally(),
            name: 'Ada',
            bus: $this->bus(),
            registry: $this->registry,
            channel: new TallyChannel(),
            version: '1.0.0',
        );
        $this->settle($client, 6, 0.1);
        return $client;
    }

    /** Both ends tick $rounds times, each tick worth $dt seconds. */
    private function settle(?ClientSession $client, int $rounds, float $dt): void
    {
        for ($i = 0; $i < $rounds; $i++) {
            $client?->tick($dt);
            $this->host->tick($dt);
        }
    }

    public function testAPartnerThatGoesQuietIsGivenUpOn(): void
    {
        $client = $this->join();
        $this->assertCount(1, $this->host->players());
        $this->host->drainEvents();

        // The partner's machine is gone: it ticks no more, and nothing reports
        // the connection as closed. Only the silence says so.
        $this->settle(null, 20, 1.0);

        $this->assertSame([], $this->host->players(), 'the seat is free again');
        $this->assertSame('left', $this->host->drainEvents()[0]['kind'] ?? null);
        $this->assertSame(ClientSession::JOINED, $client->status(), 'the partner has not noticed yet');
    }

    public function testAPartnerThatKeepsBeatingKeepsItsSeat(): void
    {
        $client = $this->join();
        $this->host->drainEvents();

        // Half a minute in which nobody has anything to say.
        $this->settle($client, 60, 0.5);

        $this->assertCount(1, $this->host->players(), 'still there');
        $this->assertSame([], $this->host->drainEvents());
        $this->assertSame(ClientSession::JOINED, $client->status());
    }

    public function testAHostThatGoesQuietIsGivenUpOn(): void
    {
        $client = $this->join();

        // The host's machine is gone: it ticks no more.
        for ($i = 0; $i < 20; $i++) {
            $client->tick(1.0);
        }

        $this->assertSame(ClientSession::CLOSED, $client->status());
        $this->assertSame('lost', $client->closeReason());
        $this->assertFalse($client->isReady(), 'and the game it holds is not to be played on');
    }

    public function testAHostThatKeepsSendingKeepsThePartner(): void
    {
        $client = $this->join();

        $this->settle($client, 60, 0.5);

        $this->assertSame(ClientSession::JOINED, $client->status());
        $this->assertTrue($client->isReady());
    }

    public function testTheBeatIsSparing(): void
    {
        $counter = new CountingTransport($this->hostEnd->connectClient(7));
        $client = new ClientSession(
            transport: $counter,
            state: new Tally(),
            name: 'Bo',
            bus: $this->bus(),
            registry: $this->registry,
            channel: new TallyChannel(),
            version: '1.0.0',
        );
        $this->settle($client, 6, 0.1);
        $this->assertSame(ClientSession::JOINED, $client->status());
        $counter->sent = 0;

        // Ten seconds in which this partner has nothing to say.
        $this->settle($client, 100, 0.1);

        $this->assertLessThanOrEqual(
            4,
            $counter->sent,
            'a beat every few seconds, not one per tick (' . $counter->sent . ')',
        );
        $this->assertGreaterThan(0, $counter->sent, 'but it does beat');
    }
}
