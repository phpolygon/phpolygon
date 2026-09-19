<?php

declare(strict_types=1);

namespace PHPolygon\Net;

/**
 * Steam's relay network (ISteamNetworkingSockets via php-steamworks): no ports,
 * no addresses, peers are Steam accounts. A host listens on a P2P socket and
 * reads every partner through one poll group; a client connects to the host's
 * account. The virtual port is the game's to choose - both ends must agree.
 *
 * Connection changes arrive as callbacks, so {@see poll()} must run after the
 * game pumped Steam's callbacks in the same frame. Whether Steam is running at
 * all is the game's to know: {@see available()} only answers for the extension.
 */
final class SteamTransport implements Transport
{
    // STEAM_NET_CONNECTION_STATE_* - mirrored as literals, since the constants
    // only exist when the extension is loaded.
    private const STATE_CONNECTING = 1;
    private const STATE_CONNECTED  = 3;
    private const STATE_CLOSED     = 4;
    private const STATE_PROBLEM    = 5;

    /** @var array<int, int> connection => partner's Steam id, once connected */
    private array $connected = [];

    private function __construct(
        private readonly int $listenSocket,
        private readonly int $pollGroup,
        private readonly int $hostConnection,
    ) {}

    /** Does this build have Steam's networking functions at all? */
    public static function available(): bool
    {
        return function_exists('steam_net_create_listen_socket_p2p');
    }

    /** Listen for partners on $port; null when Steam will not open the socket. */
    public static function listen(int $port): ?self
    {
        if (!self::available()) {
            return null;
        }
        steam_net_init_relay_network_access();
        $socket = steam_net_create_listen_socket_p2p($port);
        $group = steam_net_create_poll_group();
        if ($socket === false || $group === false) {
            return null;
        }
        return new self($socket, $group, 0);
    }

    /** Connect to a host's account on $port; null when Steam will not even try. */
    public static function connect(int $hostSteamId, int $port): ?self
    {
        if (!self::available()) {
            return null;
        }
        steam_net_init_relay_network_access();
        $connection = steam_net_connect_p2p($hostSteamId, $port);
        return $connection === false ? null : new self(0, 0, $connection);
    }

    public function poll(): array
    {
        $events = [];
        foreach (steam_net_get_connection_events() as $change) {
            $connection = (int) $change['connection'];
            $state = (int) $change['state'];
            $mine = $this->hostConnection !== 0
                ? $connection === $this->hostConnection
                : (int) $change['listen_socket'] === $this->listenSocket || isset($this->connected[$connection]);
            if (!$mine) {
                continue;
            }

            if ($state === self::STATE_CONNECTING && $this->hostConnection === 0) {
                steam_net_accept_connection($connection);
                steam_net_set_connection_poll_group($connection, $this->pollGroup);
            } elseif ($state === self::STATE_CONNECTED && !isset($this->connected[$connection])) {
                $this->connected[$connection] = (int) $change['peer'];
                $events[] = new NetEvent(NetEvent::CONNECTED, $connection, '', (int) $change['peer']);
            } elseif ($state === self::STATE_CLOSED || $state === self::STATE_PROBLEM) {
                steam_net_close_connection($connection);
                if (isset($this->connected[$connection])) {
                    unset($this->connected[$connection]);
                    $events[] = new NetEvent(NetEvent::DISCONNECTED, $connection);
                }
            }
        }

        $messages = $this->hostConnection !== 0
            ? steam_net_receive_messages($this->hostConnection, 64)
            : steam_net_receive_messages_on_poll_group($this->pollGroup, 64);
        foreach ($messages ?: [] as $message) {
            $events[] = new NetEvent(NetEvent::MESSAGE, (int) $message['connection'], (string) $message['data']);
        }
        return $events;
    }

    public function send(int $peer, string $bytes): bool
    {
        return isset($this->connected[$peer]) && steam_net_send_message($peer, $bytes, true) === 1;
    }

    public function disconnect(int $peer): void
    {
        // Linger, so a farewell already queued still reaches them.
        steam_net_close_connection($peer, 0, null, true);
        unset($this->connected[$peer]);
    }

    public function close(): void
    {
        foreach (array_keys($this->connected) as $connection) {
            $this->disconnect($connection);
        }
        if ($this->hostConnection !== 0) {
            steam_net_close_connection($this->hostConnection);
        }
        if ($this->listenSocket !== 0) {
            steam_net_close_listen_socket($this->listenSocket);
        }
        if ($this->pollGroup !== 0) {
            steam_net_destroy_poll_group($this->pollGroup);
        }
    }
}
