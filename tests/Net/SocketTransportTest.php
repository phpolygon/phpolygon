<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Net;

use PHPUnit\Framework\TestCase;
use PHPolygon\Net\NetEvent;
use PHPolygon\Net\Protocol;
use PHPolygon\Net\SocketTransport;

/**
 * The same sessions over a real socket instead of Steam's relay: two
 * processes, two machines, a LAN game - and the only way to see the protocol
 * survive a stream that hands over half a message at a time.
 */
final class SocketTransportTest extends TestCase
{
    private ?SocketTransport $host = null;

    /** @var list<SocketTransport> */
    private array $clients = [];

    protected function setUp(): void
    {
        if (!extension_loaded('sockets')) {
            $this->markTestSkipped('ext-sockets is not loaded');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->host?->close();
    }

    private function listen(): SocketTransport
    {
        // Port 0: the operating system picks a free one.
        $host = SocketTransport::listen(0);
        $this->assertNotNull($host);
        $this->host = $host;
        return $host;
    }

    private function join(int $account = 0): SocketTransport
    {
        $this->assertNotNull($this->host);
        $client = SocketTransport::connect('127.0.0.1', $this->host->port(), $account);
        $this->assertNotNull($client);
        $this->clients[] = $client;
        return $client;
    }

    /** @return list<NetEvent> everything both ends see within a moment */
    private function settle(SocketTransport ...$ends): array
    {
        $events = [];
        for ($i = 0; $i < 40; $i++) {
            foreach ($ends as $end) {
                foreach ($end->poll() as $event) {
                    $events[] = $event;
                }
            }
            usleep(2_000);
        }
        return $events;
    }

    public function testAPartnerConnectsAndSaysWhoTheyAre(): void
    {
        $host = $this->listen();
        $this->assertGreaterThan(0, $host->port());
        $client = $this->join(account: 76561198000000042);

        $events = $this->settle($host, $client);
        $connected = array_values(array_filter($events, static fn (NetEvent $e) => $e->kind === NetEvent::CONNECTED));

        $this->assertCount(2, $connected, 'both ends see the connection');
        $atHost = array_values(array_filter($connected, static fn (NetEvent $e) => $e->account !== 0));
        $this->assertCount(1, $atHost);
        $this->assertSame(76561198000000042, $atHost[0]->account);
    }

    public function testMessagesArriveWholeAndInOrder(): void
    {
        $host = $this->listen();
        $client = $this->join();
        $events = $this->settle($host, $client);
        $peer = array_values(array_filter($events, static fn (NetEvent $e) => $e->kind === NetEvent::CONNECTED && $e->account === 0))[0]->peer;
        $peerAtHost = array_values(array_filter($events, static fn (NetEvent $e) => $e->kind === NetEvent::CONNECTED && $e->account !== $e->peer))[0]->peer;

        foreach (['one', 'two', 'three'] as $line) {
            $this->assertTrue($host->send($peerAtHost, $line));
        }
        $this->assertTrue($client->send($peer, 'back'));

        $events = $this->settle($host, $client);
        $atClient = array_values(array_map(
            static fn (NetEvent $e) => $e->bytes,
            array_filter($events, static fn (NetEvent $e) => $e->kind === NetEvent::MESSAGE && $e->bytes !== 'back'),
        ));
        $atHost = array_values(array_filter($events, static fn (NetEvent $e) => $e->kind === NetEvent::MESSAGE && $e->bytes === 'back'));

        $this->assertSame(['one', 'two', 'three'], $atClient, 'whole, and in the order they were sent');
        $this->assertCount(1, $atHost);
    }

    public function testAStateTooBigForOneReadArrivesWhole(): void
    {
        $host = $this->listen();
        $client = $this->join();
        $connected = $this->settle($host, $client);
        $peerAtHost = array_values(array_filter($connected, static fn (NetEvent $e) => $e->kind === NetEvent::CONNECTED && $e->account !== $e->peer))[0]->peer;

        // Incompressible, so the protocol really cuts it into several frames.
        $message = ['t' => 'state', 'data' => base64_encode(random_bytes(400_000))];
        $frames = Protocol::encode($message);
        $this->assertGreaterThan(1, count($frames));
        foreach ($frames as $frame) {
            $this->assertTrue($host->send($peerAtHost, $frame));
        }

        $events = $this->settle($host, $client);
        $reassembler = new \PHPolygon\Net\Reassembler();
        $received = null;
        foreach ($events as $event) {
            if ($event->kind === NetEvent::MESSAGE) {
                $received = $reassembler->feed($event->peer, $event->bytes) ?? $received;
            }
        }

        $this->assertSame($message, $received);
    }

    public function testAPartnerLeavingIsSeenAtTheHost(): void
    {
        $host = $this->listen();
        $client = $this->join();
        $this->settle($host, $client);

        $client->close();
        $events = $this->settle($host);

        $this->assertNotSame([], array_filter($events, static fn (NetEvent $e) => $e->kind === NetEvent::DISCONNECTED));
    }

    public function testAPortNobodyListensOnIsNoConnection(): void
    {
        // Port 1 is privileged and nothing of ours listens there.
        $this->assertNull(SocketTransport::connect('127.0.0.1', 1));
    }
}
