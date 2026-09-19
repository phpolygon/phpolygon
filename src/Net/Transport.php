<?php

declare(strict_types=1);

namespace PHPolygon\Net;

/**
 * Moves bytes between this game and its peers.
 *
 * Peers are opaque ints the transport hands out; a host has one per partner, a
 * client one for the host. Everything is reliable and ordered - a tycoon has no
 * use for dropped state. Implementations: Steam's relay network
 * ({@see SteamTransport}) and an in-process loopback for tests
 * ({@see LoopbackTransport}).
 */
interface Transport
{
    /**
     * What happened since the last call: peers that came and went, and the
     * messages they sent, in order.
     *
     * @return list<NetEvent>
     */
    public function poll(): array;

    /** Send one message to a peer; false when the peer is gone. */
    public function send(int $peer, string $bytes): bool;

    /** Drop one peer. */
    public function disconnect(int $peer): void;

    /** Drop everyone and stop listening. */
    public function close(): void;
}
