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
use PHPolygon\Net\SocketTransport;

/**
 * The sessions over a real socket: the same game as in-process, but every
 * message crosses a stream that may hand over half a frame at a time.
 */
final class SocketSessionTest extends TestCase
{
    private ?SocketTransport $hostEnd = null;
    private ?SocketTransport $clientEnd = null;

    protected function setUp(): void
    {
        if (!extension_loaded('sockets')) {
            $this->markTestSkipped('ext-sockets is not loaded');
        }
    }

    protected function tearDown(): void
    {
        $this->clientEnd?->close();
        $this->hostEnd?->close();
    }

    private function bus(): CommandBus
    {
        return new CommandBus(
            check: static fn (object $state, Command $c): ?Refusal => $c instanceof Add && $c->amount > 100
                ? new Refusal('tally.too_much')
                : null,
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

    public function testAPartnerPlaysOverASocket(): void
    {
        $registry = new CommandRegistry([Add::class]);
        $hostState = new Tally();
        $hostState->value = 3;

        $this->hostEnd = SocketTransport::listen(0);
        $this->assertNotNull($this->hostEnd);
        $host = new HostSession(
            transport: $this->hostEnd,
            state: $hostState,
            hostName: 'Host',
            bus: $this->bus(),
            registry: $registry,
            channel: new TallyChannel(),
            version: '1.0.0',
        );

        $this->clientEnd = SocketTransport::connect('127.0.0.1', $this->hostEnd->port(), account: 4711);
        $this->assertNotNull($this->clientEnd);
        $client = new ClientSession(
            transport: $this->clientEnd,
            state: new Tally(),
            name: 'Ada',
            bus: $this->bus(),
            registry: $registry,
            channel: new TallyChannel(),
            version: '1.0.0',
        );

        $settle = static function (int $rounds) use ($client, $host): void {
            for ($i = 0; $i < $rounds; $i++) {
                $client->tick(0.1);
                $host->tick(HostSession::STATE_INTERVAL);
                usleep(2_000);
            }
        };
        $settle(20);

        $this->assertTrue($client->isReady(), 'the partner has the host\'s game');
        $this->assertSame(3, $client->state()->value);
        $this->assertCount(1, $host->players());
        $this->assertSame(4711, $host->players()[0]->account, 'the account came over the wire');

        // A command there, a refusal back, and news along the way.
        $client->sendCommand(new Add(5));
        $client->sendCommand(new Add(500));
        $hostState->news[] = 'a day passed';
        $settle(20);

        $this->assertSame(8, $hostState->value);
        $this->assertSame(8, $client->state()->value);
        $this->assertContains('tally.too_much', $client->state()->told, 'the refusal came back');
        $this->assertContains('a day passed', $client->state()->told, 'and the news with it');
    }

    public function testAStateBiggerThanOneFrameArrives(): void
    {
        $registry = new CommandRegistry([Add::class]);
        $hostState = new Tally();
        // Incompressible, so the state really travels in pieces.
        $hostState->blob = base64_encode(random_bytes(400_000));

        $this->hostEnd = SocketTransport::listen(0);
        $this->assertNotNull($this->hostEnd);
        $host = new HostSession(
            transport: $this->hostEnd,
            state: $hostState,
            hostName: 'Host',
            bus: $this->bus(),
            registry: $registry,
            channel: new TallyChannel(),
            version: '1.0.0',
        );
        $this->clientEnd = SocketTransport::connect('127.0.0.1', $this->hostEnd->port());
        $this->assertNotNull($this->clientEnd);
        $clientState = new Tally();
        $client = new ClientSession(
            transport: $this->clientEnd,
            state: $clientState,
            name: 'Ada',
            bus: $this->bus(),
            registry: $registry,
            channel: new TallyChannel(),
            version: '1.0.0',
        );

        for ($i = 0; $i < 40; $i++) {
            $client->tick(0.1);
            $host->tick(HostSession::STATE_INTERVAL);
            usleep(2_000);
        }

        $this->assertTrue($client->isReady());
        $this->assertSame($hostState->blob, $clientState->blob);
    }
}
