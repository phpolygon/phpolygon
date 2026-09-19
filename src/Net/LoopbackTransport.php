<?php

declare(strict_types=1);

namespace PHPolygon\Net;

/**
 * An in-process network for tests: one host end and any number of client ends
 * that hand bytes to each other through queues, with no Steam and no sockets.
 *
 *   $host   = LoopbackTransport::host();
 *   $client = $host->connectClient(accountId: 42);
 */
final class LoopbackTransport implements Transport
{
    /** @var list<NetEvent> */
    private array $inbox = [];

    /** @var array<int, LoopbackTransport> peer id => the other end */
    private array $peers = [];

    /** @var array<int, int> other end's object id => the peer id it has here */
    private array $idOf = [];

    private int $nextPeer = 1;

    private function __construct(private readonly bool $isHost) {}

    public static function host(): self
    {
        return new self(true);
    }

    /** A client end joined to this host end, as the account $account. */
    public function connectClient(int $account = 0): self
    {
        if (!$this->isHost) {
            throw new \LogicException('Only a host end accepts clients.');
        }
        $client = new self(false);
        $hostPeer = $this->link($client);
        $clientPeer = $client->link($this);
        $this->inbox[] = new NetEvent(NetEvent::CONNECTED, $hostPeer, '', $account);
        $client->inbox[] = new NetEvent(NetEvent::CONNECTED, $clientPeer);
        return $client;
    }

    private function link(self $other): int
    {
        $peer = $this->nextPeer++;
        $this->peers[$peer] = $other;
        $this->idOf[spl_object_id($other)] = $peer;
        return $peer;
    }

    public function poll(): array
    {
        $events = $this->inbox;
        $this->inbox = [];
        return $events;
    }

    public function send(int $peer, string $bytes): bool
    {
        $other = $this->peers[$peer] ?? null;
        if ($other === null) {
            return false;
        }
        $other->inbox[] = new NetEvent(NetEvent::MESSAGE, $other->idOf[spl_object_id($this)], $bytes);
        return true;
    }

    public function disconnect(int $peer): void
    {
        $other = $this->peers[$peer] ?? null;
        if ($other === null) {
            return;
        }
        $theirs = $other->idOf[spl_object_id($this)];
        unset($this->peers[$peer], $this->idOf[spl_object_id($other)]);
        unset($other->peers[$theirs], $other->idOf[spl_object_id($this)]);
        $other->inbox[] = new NetEvent(NetEvent::DISCONNECTED, $theirs);
    }

    public function close(): void
    {
        foreach (array_keys($this->peers) as $peer) {
            $this->disconnect($peer);
        }
    }
}
