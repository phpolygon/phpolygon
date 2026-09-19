<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Net;

use PHPUnit\Framework\TestCase;
use PHPolygon\Net\LoopbackTransport;
use PHPolygon\Net\NetEvent;
use PHPolygon\Net\Protocol;
use PHPolygon\Net\Reassembler;

/**
 * The wire format carries messages of any size in frames a relay accepts, and
 * turns away bytes that are not ours without choking on them.
 */
final class ProtocolTest extends TestCase
{
    public function testASmallMessageIsOneFrame(): void
    {
        $frames = Protocol::encode(['t' => 'hello', 'name' => 'Ada']);

        $this->assertCount(1, $frames);
        $this->assertSame(['t' => 'hello', 'name' => 'Ada'], (new Reassembler())->feed(1, $frames[0]));
    }

    public function testALargeMessageTravelsInPiecesAndArrivesWhole(): void
    {
        // Incompressible, so it really needs several frames.
        $blob = base64_encode(random_bytes(700_000));
        $frames = Protocol::encode(['t' => 'state', 'data' => $blob]);
        $this->assertGreaterThan(1, count($frames));
        foreach ($frames as $frame) {
            $this->assertLessThanOrEqual(Protocol::MAX_FRAME + 12, strlen($frame));
        }

        $reassembler = new Reassembler();
        $result = null;
        foreach ($frames as $frame) {
            $result = $reassembler->feed(7, $frame);
        }

        $this->assertSame($blob, $result['data'] ?? null);
    }

    public function testPiecesOfTwoPeersDoNotMix(): void
    {
        $a = Protocol::encode(['t' => 'state', 'data' => base64_encode(random_bytes(600_000))]);
        $b = Protocol::encode(['t' => 'state', 'data' => base64_encode(random_bytes(600_000))]);
        $reassembler = new Reassembler();

        $done = [];
        foreach (array_keys($a) as $i) {
            $done[1] = $reassembler->feed(1, $a[$i]) ?? $done[1] ?? null;
            $done[2] = $reassembler->feed(2, $b[$i] ?? '') ?? $done[2] ?? null;
        }

        $this->assertNotNull($done[1]);
        $this->assertNotNull($done[2]);
        $this->assertNotSame($done[1]['data'], $done[2]['data']);
    }

    public function testBytesThatAreNotOursAreIgnored(): void
    {
        $reassembler = new Reassembler();
        $head = Protocol::MAGIC . chr(Protocol::VERSION);

        $this->assertNull($reassembler->feed(1, ''));
        $this->assertNull($reassembler->feed(1, 'GET / HTTP/1.1'));
        $this->assertNull($reassembler->feed(1, Protocol::MAGIC . chr(99) . chr(0) . 'x'));
        $this->assertNull($reassembler->feed(1, $head . chr(0) . 'not zlib'));
        // Valid zlib, but not a message: no type.
        $this->assertNull($reassembler->feed(1, $head . chr(0) . gzcompress('{"x":1}')));
        // A piece claiming an impossible count.
        $this->assertNull($reassembler->feed(1, $head . chr(1) . pack('Nnn', 1, 5, 2) . 'x'));
    }

    public function testAMessageThatInflatesBeyondTheLimitIsRefused(): void
    {
        // 40 MB of zeros compress to a few dozen KB: a zip bomb in one frame.
        $bomb = gzcompress('{"t":"x","d":"' . str_repeat('0', 40 * 1024 * 1024) . '"}', 9);

        $this->assertNull((new Reassembler())->feed(1, Protocol::MAGIC . chr(Protocol::VERSION) . chr(0) . $bomb));
    }

    public function testTheLoopbackCarriesBytesBothWays(): void
    {
        $host = LoopbackTransport::host();
        $client = $host->connectClient(account: 42);

        $join = $host->poll();
        $this->assertCount(1, $join);
        $this->assertSame(NetEvent::CONNECTED, $join[0]->kind);
        $this->assertSame(42, $join[0]->account);
        $peer = $join[0]->peer;
        $client->poll();

        $this->assertTrue($host->send($peer, 'ping'));
        $this->assertSame('ping', $client->poll()[0]->bytes);

        $host->disconnect($peer);

        $this->assertSame(NetEvent::DISCONNECTED, $client->poll()[0]->kind);
        $this->assertFalse($host->send($peer, 'gone'));
    }
}
