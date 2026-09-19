<?php

declare(strict_types=1);

namespace PHPolygon\Net;

/** Something a {@see Transport} noticed: a peer arrived, left, or sent bytes. */
final class NetEvent
{
    public const CONNECTED    = 'connected';
    public const DISCONNECTED = 'disconnected';
    public const MESSAGE      = 'message';

    /**
     * @param int $account the peer's account (Steam id) when the transport knows it, else 0
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $peer,
        public readonly string $bytes = '',
        public readonly int $account = 0,
    ) {}
}
