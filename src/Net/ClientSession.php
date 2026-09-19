<?php

declare(strict_types=1);

namespace PHPolygon\Net;

use PHPolygon\Command\Command;
use PHPolygon\Command\CommandBus;
use PHPolygon\Command\CommandRegistry;
use PHPolygon\Command\Refusal;

/**
 * A partner's end of a session. It does not simulate: every click becomes a
 * command for the host, and the host's state replaces this game's state as it
 * arrives.
 *
 * Messages it takes by itself: welcome, state, refused, bye. Anything else the
 * host sends goes to a handler the game registered with {@see on()}.
 */
final class ClientSession
{
    public const CONNECTING = 'connecting';
    public const JOINED     = 'joined';
    public const CLOSED     = 'closed';

    private string $status = self::CONNECTING;
    private string $closeReason = '';
    private string $hostName = '';
    private int $lastSeq = 0;
    private int $nextCommand = 1;
    private bool $hasState = false;
    private ?int $hostPeer = null;

    /** How the host knows this partner (its peer id there). */
    private int $you = 0;

    /** @var array<string, mixed> the welcome as it arrived, with whatever the host added */
    private array $welcome = [];

    /** @var array<string, \Closure(array<string, mixed>): void> the game's own messages */
    private array $handlers = [];

    /** @var null|\Closure(array<string, mixed>): void */
    private ?\Closure $onWelcome = null;

    private Reassembler $reassembler;

    /**
     * @param string $version this build's version; the host turns away a partner on another
     * @param array<string, mixed> $hello what to tell the host when joining
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly object $state,
        private readonly string $name,
        private readonly CommandBus $bus,
        private readonly CommandRegistry $registry,
        private readonly StateChannel $channel,
        private readonly string $version,
        private readonly array $hello = [],
    ) {
        $this->reassembler = new Reassembler();
    }

    public function status(): string
    {
        return $this->status;
    }

    /** Joined and holding the host's game - the UI may show it. */
    public function isReady(): bool
    {
        return $this->status === self::JOINED && $this->hasState;
    }

    /** Why the session ended: 'version', 'full', 'kicked', 'closed', 'lost' ... */
    public function closeReason(): string
    {
        return $this->closeReason;
    }

    public function hostName(): string
    {
        return $this->hostName;
    }

    /** The game this session fills in. */
    public function state(): object
    {
        return $this->state;
    }

    /** This partner's id at the host - how the host's tables name it. */
    public function you(): int
    {
        return $this->you;
    }

    /** @return array<string, mixed> what the host said when letting this partner in */
    public function welcome(): array
    {
        return $this->welcome;
    }

    /**
     * Take one of the host's own message types.
     *
     * @param \Closure(array<string, mixed>): void $handler
     */
    public function on(string $type, \Closure $handler): void
    {
        if (!in_array($type, ['welcome', 'state', 'refused', 'bye'], true)) {
            $this->handlers[$type] = $handler;
        }
    }

    /** @param null|\Closure(array<string, mixed>): void $onWelcome the game is in */
    public function setOnWelcome(?\Closure $onWelcome): void
    {
        $this->onWelcome = $onWelcome;
    }

    public function tick(float $dt): void
    {
        foreach ($this->transport->poll() as $event) {
            if ($event->kind === NetEvent::CONNECTED) {
                $this->hostPeer = $event->peer;
                $this->post([
                    't'     => 'hello',
                    'v'     => Protocol::VERSION,
                    'game'  => $this->version,
                    'name'  => $this->name,
                    'hello' => $this->hello,
                ]);
            } elseif ($event->kind === NetEvent::DISCONNECTED) {
                $this->end($this->closeReason !== '' ? $this->closeReason : 'lost');
            } else {
                $message = $this->reassembler->feed($event->peer, $event->bytes);
                if ($message !== null) {
                    $this->handle($message);
                }
            }
        }
    }

    /** Leave the host's game. */
    public function close(): void
    {
        if ($this->status !== self::CLOSED) {
            $this->post(['t' => 'bye', 'reason' => 'left']);
        }
        $this->end('left');
        $this->transport->close();
    }

    /**
     * One of the game's own messages to the host.
     *
     * @param array<string, mixed> $payload
     */
    public function send(string $type, array $payload = []): void
    {
        $this->post(['t' => $type] + $payload);
    }

    /** Hand a command to the host, which applies it or says why not. */
    public function sendCommand(Command $command): void
    {
        $this->post(['t' => 'cmd', 'n' => $this->nextCommand++, 'cmd' => $this->registry->encode($command)]);
    }

    /** @param array<string, mixed> $message */
    private function handle(array $message): void
    {
        $type = is_string($message['t'] ?? null) ? $message['t'] : '';
        switch ($type) {
            case 'welcome':
                $this->hostName = is_string($message['host'] ?? null) ? mb_substr($message['host'], 0, 32) : '';
                $this->status = self::JOINED;
                $this->you = is_int($message['you'] ?? null) ? $message['you'] : 0;
                $this->welcome = $message;
                // From now on every click goes to the host.
                $this->bus->setRemote($this->sendCommand(...));
                if ($this->onWelcome !== null) {
                    ($this->onWelcome)($message);
                }
                break;
            case 'state':
                $seq = is_int($message['seq'] ?? null) ? $message['seq'] : 0;
                if ($seq <= $this->lastSeq || !is_array($message['data'] ?? null)) {
                    break;
                }
                $this->lastSeq = $seq;
                /** @var array<string, mixed> $data */
                $data = $message['data'];
                $this->channel->restore($this->state, $data);
                /** @var array<string, mixed> $news */
                $news = is_array($message['news'] ?? null) ? $message['news'] : [];
                if ($news !== []) {
                    $this->channel->showNews($this->state, $news);
                }
                $this->hasState = true;
                break;
            case 'refused':
                $key = is_string($message['key'] ?? null) ? $message['key'] : 'commands.stale';
                /** @var array<string, scalar> $params */
                $params = array_filter(is_array($message['params'] ?? null) ? $message['params'] : [], 'is_scalar');
                $this->channel->refused($this->state, new Refusal($key, $params));
                break;
            case 'bye':
                $this->end(is_string($message['reason'] ?? null) ? $message['reason'] : 'closed');
                break;
            default:
                if (isset($this->handlers[$type])) {
                    ($this->handlers[$type])($message);
                }
        }
    }

    private function end(string $reason): void
    {
        if ($this->status === self::CLOSED) {
            return;
        }
        $this->status = self::CLOSED;
        $this->closeReason = $reason;
        $this->bus->setRemote(null);
        $this->bus->setLocalGuard(null);
    }

    /** @param array<string, mixed> $message */
    private function post(array $message): void
    {
        if ($this->hostPeer === null) {
            return;
        }
        foreach (Protocol::encode($message) as $frame) {
            $this->transport->send($this->hostPeer, $frame);
        }
    }
}
