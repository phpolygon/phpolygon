<?php

declare(strict_types=1);

namespace PHPolygon\Net;

use PHPolygon\Command\Command;
use PHPolygon\Command\CommandBus;
use PHPolygon\Command\CommandRegistry;
use PHPolygon\Command\Refusal;

/**
 * The host's end of a session: the only game that simulates. Partners send
 * commands; the host checks them against its state, applies them and sends
 * everyone the resulting state.
 *
 * The host's own clicks take the local path (the {@see CommandBus} applies
 * them); this session only notices the state changed and passes it on.
 *
 * Messages it takes by itself: hello, cmd, bye. Anything else a partner sends
 * goes to a handler the game registered with {@see on()} - and is dropped when
 * there is none, so a partner can never send state.
 */
final class HostSession
{
    /** How often a changed state goes out. */
    public const STATE_INTERVAL = 0.25;

    /** An unchanged state still goes out this often, so a partner who missed one catches up. */
    public const KEEPALIVE_INTERVAL = 3.0;

    /** Commands one partner may send per tick; the rest wait. */
    public const MAX_COMMANDS_PER_TICK = 20;

    /**
     * Commands one partner may have waiting. A partner cannot click faster than
     * the host works through them; more than this is a broken or a hostile
     * game, and the rest is refused rather than kept.
     */
    public const MAX_QUEUED = 100;

    /** @var array<int, Player> peer => partner who said hello */
    private array $players = [];

    /** @var array<int, true> peers connected but not yet introduced */
    private array $strangers = [];

    /**
     * @var array<int, int> peer => account, from the moment it connected. Only
     *      that event carries it; the messages that follow do not.
     */
    private array $accounts = [];

    private Reassembler $reassembler;

    private int $seq = 0;
    private float $sinceState = 0.0;
    /** Seconds since this session started, for the keepalive. */
    private float $clock = 0.0;
    private bool $dirty = true;

    /**
     * @var \WeakMap<object, array{hash: string, at: float}> what a game last
     *      went out as, and when. Held by the game itself: a game nobody plays
     *      any more is forgotten with it, and PHP hands a freed object's id out
     *      again - remembering one would mix two games up.
     */
    private \WeakMap $lastSeen;

    /** @var null|\Closure(Player): object whose game a partner plays */
    private ?\Closure $stateOf = null;

    /** @var null|\Closure(Player, Command): void told after a partner's command was applied */
    private ?\Closure $applied = null;

    /** @var array<int, list<array<string, mixed>>> peer => commands waiting their turn */
    private array $queue = [];

    /** @var null|\Closure(Player, Command): ?Refusal who may do what */
    private ?\Closure $authorize = null;

    /** @var null|\Closure(HostSession, Player, Command): (Refusal|bool) holds a checked command back */
    private ?\Closure $deferral = null;

    /** @var null|\Closure(Player): array<string, mixed> what the game adds to a welcome */
    private ?\Closure $welcome = null;

    /** @var array<string, \Closure(Player, array<string, mixed>): void> the game's own messages */
    private array $handlers = [];

    /** @var list<array{kind: string, player: Player}> joins and leaves for the UI */
    private array $events = [];

    /**
     * @param string $version both ends must run the same one; a partner on
     *                        another is turned away with 'version'
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly object $state,
        private readonly string $hostName,
        private readonly CommandBus $bus,
        private readonly CommandRegistry $registry,
        private readonly StateChannel $channel,
        private readonly string $version,
        private readonly int $maxPlayers = 4,
    ) {
        $this->reassembler = new Reassembler();
        $this->lastSeen = new \WeakMap();
        // What the state has to say now is the host's past, not news for partners.
        $this->channel->news($state);
    }

    /** @param null|\Closure(Player, Command): ?Refusal $authorize */
    public function setAuthorizer(?\Closure $authorize): void
    {
        $this->authorize = $authorize;
    }

    /** @param null|\Closure(HostSession, Player, Command): (Refusal|bool) $deferral true = held, false = apply */
    public function setDeferral(?\Closure $deferral): void
    {
        $this->deferral = $deferral;
    }

    /**
     * Each partner plays a game of their own. $stateOf gives the state a
     * partner's commands work on and that partner is sent; $applied hears of
     * every command applied. Unset, everyone shares the host's state.
     *
     * @param null|\Closure(Player): object $stateOf
     * @param null|\Closure(Player, Command): void $applied
     */
    public function setStates(?\Closure $stateOf, ?\Closure $applied = null): void
    {
        $this->stateOf = $stateOf;
        $this->applied = $applied;
        $this->dirty = true;
    }

    /** @param null|\Closure(Player): array<string, mixed> $welcome what to add to a partner's welcome */
    public function setWelcome(?\Closure $welcome): void
    {
        $this->welcome = $welcome;
    }

    /**
     * Take the game's own message type from partners - a vote, a chat line.
     * 'hello', 'cmd' and 'bye' are the session's and cannot be taken over.
     *
     * @param \Closure(Player, array<string, mixed>): void $handler
     */
    public function on(string $type, \Closure $handler): void
    {
        if (!in_array($type, ['hello', 'cmd', 'bye'], true)) {
            $this->handlers[$type] = $handler;
        }
    }

    /** The state $player plays. */
    public function stateOf(Player $player): object
    {
        return $this->stateOf !== null ? ($this->stateOf)($player) : $this->state;
    }

    public function hostName(): string
    {
        return $this->hostName;
    }

    /** A partner by peer, or null when they are not (or no longer) in the session. */
    public function player(int $peer): ?Player
    {
        return $this->players[$peer] ?? null;
    }

    /** @return list<Player> */
    public function players(): array
    {
        return array_values($this->players);
    }

    /**
     * Joins and leaves since the last call, for the lobby and toasts.
     *
     * @return list<array{kind: string, player: Player}>
     */
    public function drainEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    /** Send the state at the next tick, changed or not. */
    public function markDirty(): void
    {
        $this->dirty = true;
    }

    public function tick(float $dt): void
    {
        foreach ($this->transport->poll() as $event) {
            match ($event->kind) {
                NetEvent::CONNECTED    => $this->meet($event->peer, $event->account),
                NetEvent::DISCONNECTED => $this->leave($event->peer),
                default                => $this->receive($event->peer, $event->bytes, $event->account),
            };
        }

        foreach ($this->queue as $peer => $commands) {
            $now = array_splice($commands, 0, self::MAX_COMMANDS_PER_TICK);
            $this->queue[$peer] = $commands;
            foreach ($now as $message) {
                $this->run($peer, $message);
            }
        }

        $this->sinceState += $dt;
        $this->clock += $dt;
        if ($this->players !== [] && ($this->dirty || $this->sinceState >= self::STATE_INTERVAL)) {
            $this->sinceState = 0.0;
            $this->broadcastState();
        }
    }

    /** Say goodbye to everyone and stop. */
    public function close(string $reason = 'closed'): void
    {
        foreach (array_keys($this->players + $this->strangers) as $peer) {
            $this->sendTo($peer, ['t' => 'bye', 'reason' => $reason]);
            $this->transport->disconnect($peer);
        }
        $this->players = [];
        $this->strangers = [];
        $this->transport->close();
    }

    /** Send one partner away. */
    public function kick(int $peer, string $reason = 'kicked'): void
    {
        $this->sendTo($peer, ['t' => 'bye', 'reason' => $reason]);
        $this->transport->disconnect($peer);
        $this->leave($peer);
    }

    /**
     * One of the game's own messages to one partner; it carries its type in 't'.
     *
     * @param array<string, mixed> $message
     */
    public function sendTo(int $peer, array $message): void
    {
        foreach (Protocol::encode($message) as $frame) {
            $this->transport->send($peer, $frame);
        }
    }

    private function meet(int $peer, int $account): void
    {
        $this->strangers[$peer] = true;
        $this->accounts[$peer] = $account;
    }

    private function receive(int $peer, string $bytes, int $account): void
    {
        $message = $this->reassembler->feed($peer, $bytes);
        if ($message === null) {
            return;
        }
        $type = is_string($message['t'] ?? null) ? $message['t'] : '';
        if ($type === 'hello') {
            $this->hello($peer, $message, $account);
            return;
        }
        $player = $this->players[$peer] ?? null;
        if ($player === null) {
            return;
        }
        match (true) {
            $type === 'cmd'                  => $this->enqueue($peer, $message),
            $type === 'bye'                  => $this->leave($peer),
            isset($this->handlers[$type])    => ($this->handlers[$type])($player, $message),
            default                          => null,
        };
    }

    /**
     * A partner's command, unless they have that many waiting already.
     *
     * @param array<string, mixed> $message
     */
    private function enqueue(int $peer, array $message): void
    {
        if (count($this->queue[$peer] ?? []) >= self::MAX_QUEUED) {
            $n = is_int($message['n'] ?? null) ? $message['n'] : 0;
            $this->sendTo($peer, ['t' => 'refused', 'n' => $n, 'key' => 'commands.busy', 'params' => []]);
            return;
        }
        $this->queue[$peer][] = $message;
    }

    /** @param array<string, mixed> $message */
    private function hello(int $peer, array $message, int $account): void
    {
        if (isset($this->players[$peer])) {
            return;
        }
        if (($message['v'] ?? null) !== Protocol::VERSION || ($message['game'] ?? null) !== $this->version) {
            $this->kick($peer, 'version');
            return;
        }
        if (count($this->players) >= $this->maxPlayers - 1) {
            $this->kick($peer, 'full');
            return;
        }
        unset($this->strangers[$peer]);
        $name = is_string($message['name'] ?? null) ? mb_substr(trim($message['name']), 0, 32) : '';
        /** @var array<string, mixed> $hello */
        $hello = is_array($message['hello'] ?? null) ? $message['hello'] : [];
        $player = new Player($peer, $this->accounts[$peer] ?? $account, $name !== '' ? $name : 'Player ' . $peer, [], $hello);
        $this->players[$peer] = $player;
        $this->events[] = ['kind' => 'joined', 'player' => $player];

        $extra = $this->welcome !== null ? ($this->welcome)($player) : [];
        $this->sendTo($peer, ['t' => 'welcome', 'host' => $this->hostName, 'you' => $peer] + $extra);
        $this->dirty = true;
    }

    private function leave(int $peer): void
    {
        unset($this->strangers[$peer], $this->queue[$peer], $this->accounts[$peer]);
        $this->reassembler->forget($peer);
        if (isset($this->players[$peer])) {
            $this->events[] = ['kind' => 'left', 'player' => $this->players[$peer]];
            unset($this->players[$peer]);
        }
    }

    /** @param array<string, mixed> $message */
    private function run(int $peer, array $message): void
    {
        $player = $this->players[$peer] ?? null;
        if ($player === null) {
            return;
        }
        $n = is_int($message['n'] ?? null) ? $message['n'] : 0;
        $command = $this->registry->decode($message['cmd'] ?? null);
        if ($command === null) {
            $this->sendTo($peer, ['t' => 'refused', 'n' => $n, 'key' => 'commands.stale', 'params' => []]);
            return;
        }

        $state = $this->stateOf($player);
        $refusal = $this->authorize !== null ? ($this->authorize)($player, $command) : null;
        $refusal ??= $this->bus->check($state, $command);
        if ($refusal !== null) {
            $this->sendTo($peer, ['t' => 'refused', 'n' => $n, 'key' => $refusal->key, 'params' => $refusal->params]);
            return;
        }
        $held = $this->deferral !== null ? ($this->deferral)($this, $player, $command) : false;
        if ($held instanceof Refusal) {
            $this->sendTo($peer, ['t' => 'refused', 'n' => $n, 'key' => $held->key, 'params' => $held->params]);
            return;
        }
        $this->dirty = true;
        if ($held) {
            return; // waits for the others' consent
        }
        $this->bus->apply($state, $command);
        if ($this->applied !== null) {
            ($this->applied)($player, $command);
        }
    }

    /** Everyone the state of the game they play - once per state, when it changed. */
    private function broadcastState(): void
    {
        $groups = [];
        foreach ($this->players as $peer => $player) {
            $state = $this->stateOf($player);
            $groups[spl_object_id($state)] ??= ['state' => $state, 'peers' => []];
            $groups[spl_object_id($state)]['peers'][] = $peer;
        }
        foreach ($groups as $group) {
            $this->sendState($group['state'], $group['peers']);
        }
        $this->dirty = false;
    }

    /** @param list<int> $peers */
    private function sendState(object $state, array $peers): void
    {
        $data = $this->channel->capture($state);
        $hash = md5(json_encode($data, JSON_THROW_ON_ERROR));
        $news = $this->channel->news($state);

        $seen = $this->lastSeen[$state] ?? null;
        $changed = $seen === null || $hash !== $seen['hash'] || $news !== [];
        $quiet = $this->clock - ($seen['at'] ?? -INF);
        if (!$changed && !$this->dirty && $quiet < self::KEEPALIVE_INTERVAL) {
            return;
        }

        $this->lastSeen[$state] = ['hash' => $hash, 'at' => $this->clock];
        $frames = Protocol::encode([
            't'    => 'state',
            'seq'  => ++$this->seq,
            'data' => $data,
            'news' => $news,
        ]);
        foreach ($peers as $peer) {
            foreach ($frames as $frame) {
                $this->transport->send($peer, $frame);
            }
        }
    }
}
