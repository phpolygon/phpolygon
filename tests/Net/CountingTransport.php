<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Net;

use PHPolygon\Net\Transport;

/** A transport that carries everything through and counts what went out. */
final class CountingTransport implements Transport
{
    public int $sent = 0;

    public function __construct(private readonly Transport $inner) {}

    public function poll(): array
    {
        return $this->inner->poll();
    }

    public function send(int $peer, string $bytes): bool
    {
        $this->sent++;
        return $this->inner->send($peer, $bytes);
    }

    public function disconnect(int $peer): void
    {
        $this->inner->disconnect($peer);
    }

    public function close(): void
    {
        $this->inner->close();
    }
}
