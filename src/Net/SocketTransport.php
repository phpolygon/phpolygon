<?php

declare(strict_types=1);

namespace PHPolygon\Net;

/**
 * Plain TCP (ext-sockets): the same sessions between two processes on one
 * machine, two machines on a LAN, or two containers - without Steam.
 *
 * TCP is a stream, not a row of messages: a read can hand over half a frame or
 * three at once. Each frame therefore travels behind its length, and a partly
 * arrived one waits in the peer's buffer until it is whole - {@see Protocol}
 * and the sessions above it see exactly the message boundaries they do over
 * Steam's relay.
 *
 * A connecting end opens with the account it plays as (8 bytes), so a host can
 * tell a returning player from a new one, the way Steam's account id does.
 * Everything is non-blocking: {@see poll()} never waits for the network.
 */
final class SocketTransport implements Transport
{
    /** A frame may not claim more than the protocol can hold. */
    private const MAX_FRAME = Protocol::MAX_FRAME + 64;

    /** How many bytes an opening end sends before its first frame. */
    private const HELLO_BYTES = 8;

    /** @var array<int, \Socket> peer => its socket */
    private array $peers = [];

    /** @var array<int, string> peer => bytes read but not yet a whole frame */
    private array $buffers = [];

    /** @var array<int, bool> peer => still waiting for the opening account */
    private array $opening = [];

    /** @var list<NetEvent> what the last poll could not hand over yet */
    private array $pending = [];

    private int $nextPeer = 1;

    private function __construct(
        private ?\Socket $listener,
        private readonly int $port,
        private readonly int $account,
    ) {}

    /**
     * Listen for partners on $port; 0 lets the system pick one, which
     * {@see port()} then reports. Null when the port cannot be opened.
     */
    public static function listen(int $port, string $bind = '0.0.0.0'): ?self
    {
        if (!extension_loaded('sockets')) {
            return null;
        }
        $listener = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($listener === false) {
            return null;
        }
        @socket_set_option($listener, SOL_SOCKET, SO_REUSEADDR, 1);
        if (!@socket_bind($listener, $bind, $port) || !@socket_listen($listener, 8)) {
            socket_close($listener);
            return null;
        }
        socket_set_nonblock($listener);

        $address = '';
        $bound = $port;
        if (@socket_getsockname($listener, $address, $bound) === false || !is_int($bound)) {
            $bound = $port;
        }
        return new self($listener, $bound, 0);
    }

    /**
     * Connect to a host. $account is who this end plays as, as Steam would
     * know them; 0 when there is nobody to name. Null when nothing answers.
     */
    public static function connect(string $host, int $port, int $account = 0): ?self
    {
        if (!extension_loaded('sockets')) {
            return null;
        }
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return null;
        }
        if (!@socket_connect($socket, $host, $port)) {
            socket_close($socket);
            return null;
        }
        socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        socket_set_nonblock($socket);

        $transport = new self(null, $port, $account);
        $peer = $transport->take($socket, opening: false);
        // Who is calling, before anything else on the wire.
        $transport->write($peer, pack('J', $account));
        $transport->pending[] = new NetEvent(NetEvent::CONNECTED, $peer);
        return $transport;
    }

    /** The port this end listens on; 0 for a connecting end. */
    public function port(): int
    {
        return $this->port;
    }

    /** The account this end plays as. */
    public function account(): int
    {
        return $this->account;
    }

    public function poll(): array
    {
        $events = $this->pending;
        $this->pending = [];

        $this->accept($events);
        foreach ($this->peers as $peer => $socket) {
            $this->read($peer, $socket, $events);
        }
        return $events;
    }

    public function send(int $peer, string $bytes): bool
    {
        if (!isset($this->peers[$peer]) || strlen($bytes) > self::MAX_FRAME) {
            return false;
        }
        return $this->write($peer, pack('N', strlen($bytes)) . $bytes);
    }

    public function disconnect(int $peer): void
    {
        $socket = $this->peers[$peer] ?? null;
        if ($socket === null) {
            return;
        }
        @socket_shutdown($socket, 2);
        socket_close($socket);
        unset($this->peers[$peer], $this->buffers[$peer], $this->opening[$peer]);
    }

    public function close(): void
    {
        foreach (array_keys($this->peers) as $peer) {
            $this->disconnect($peer);
        }
        if ($this->listener !== null) {
            socket_close($this->listener);
            $this->listener = null;
        }
    }

    /** @param list<NetEvent> $events */
    private function accept(array &$events): void
    {
        if ($this->listener === null) {
            return;
        }
        while (($socket = @socket_accept($this->listener)) !== false) {
            socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
            socket_set_nonblock($socket);
            $this->take($socket, opening: true);
        }
    }

    /**
     * Read what is there: the opening account first, then whole frames.
     *
     * @param list<NetEvent> $events
     */
    private function read(int $peer, \Socket $socket, array &$events): void
    {
        while (true) {
            $chunk = '';
            $read = @socket_recv($socket, $chunk, 64 * 1024, 0);
            if ($read === false) {
                $error = socket_last_error($socket);
                socket_clear_error($socket);
                if (self::wouldBlock($error)) {
                    break;
                }
                $this->lost($peer, $events);
                return;
            }
            if ($read === 0) {
                // The other end hung up.
                $this->lost($peer, $events);
                return;
            }
            $this->buffers[$peer] .= is_string($chunk) ? $chunk : '';
            if ($read < 64 * 1024) {
                break;
            }
        }

        if (($this->opening[$peer] ?? false)) {
            if (strlen($this->buffers[$peer]) < self::HELLO_BYTES) {
                return;
            }
            /** @var array{1: int} $head */
            $head = unpack('J', substr($this->buffers[$peer], 0, self::HELLO_BYTES));
            $this->buffers[$peer] = substr($this->buffers[$peer], self::HELLO_BYTES);
            $this->opening[$peer] = false;
            $events[] = new NetEvent(NetEvent::CONNECTED, $peer, '', $head[1]);
        }

        while (strlen($this->buffers[$peer]) >= 4) {
            /** @var array{1: int} $head */
            $head = unpack('N', substr($this->buffers[$peer], 0, 4));
            $length = $head[1];
            if ($length > self::MAX_FRAME) {
                // Nothing of ours is that big; the stream cannot be trusted.
                $this->lost($peer, $events);
                return;
            }
            if (strlen($this->buffers[$peer]) < 4 + $length) {
                return;
            }
            $events[] = new NetEvent(NetEvent::MESSAGE, $peer, substr($this->buffers[$peer], 4, $length));
            $this->buffers[$peer] = substr($this->buffers[$peer], 4 + $length);
        }
    }

    /** @param list<NetEvent> $events */
    private function lost(int $peer, array &$events): void
    {
        $this->disconnect($peer);
        $events[] = new NetEvent(NetEvent::DISCONNECTED, $peer);
    }

    /**
     * Nothing to read or write right now, rather than a broken connection.
     * The names for it are the same number on some platforms and not on
     * others, so they are compared as a set.
     */
    private static function wouldBlock(int $error): bool
    {
        return in_array($error, [SOCKET_EWOULDBLOCK, SOCKET_EAGAIN, SOCKET_EINPROGRESS], true);
    }

    private function take(\Socket $socket, bool $opening): int
    {
        $peer = $this->nextPeer++;
        $this->peers[$peer] = $socket;
        $this->buffers[$peer] = '';
        $this->opening[$peer] = $opening;
        return $peer;
    }

    /** Write everything, or give the peer up: a half-written frame is no frame. */
    private function write(int $peer, string $bytes): bool
    {
        $socket = $this->peers[$peer] ?? null;
        if ($socket === null) {
            return false;
        }
        $sent = 0;
        $total = strlen($bytes);
        $deadline = microtime(true) + 5.0;
        while ($sent < $total) {
            $wrote = @socket_write($socket, substr($bytes, $sent), $total - $sent);
            if ($wrote === false) {
                $error = socket_last_error($socket);
                socket_clear_error($socket);
                if (self::wouldBlock($error) && microtime(true) < $deadline) {
                    // The window is full; let it drain.
                    usleep(500);
                    continue;
                }
                $this->pending[] = new NetEvent(NetEvent::DISCONNECTED, $peer);
                $this->disconnect($peer);
                return false;
            }
            $sent += $wrote;
        }
        return true;
    }
}
