<?php

declare(strict_types=1);

namespace PHPolygon\Command;

/**
 * The one way a player changes the game.
 *
 * Alone, a command is checked and applied at once. In a session whose host is
 * somebody else, the session installs a remote: the command is sent there
 * instead, and the host's next state shows what came of it. The host applies
 * what arrives through {@see execute()}, the same path a single-player click
 * takes.
 *
 * What checking, applying and telling the player mean is the game's: it hands
 * the three in. Everything else here is routing.
 */
final class CommandBus
{
    /**
     * @param \Closure(object, Command): ?Refusal $check why the command cannot be applied to this state
     * @param \Closure(object, Command): void $apply change the state; only called after $check passed
     * @param \Closure(object, Refusal): void $refused tell the player on this machine
     */
    public function __construct(
        private readonly \Closure $check,
        private readonly \Closure $apply,
        private readonly \Closure $refused,
    ) {}

    /** @var null|\Closure(Command): void where commands go when this game is not the host */
    private ?\Closure $remote = null;

    /** @param null|\Closure(Command): void $remote */
    public function setRemote(?\Closure $remote): void
    {
        $this->remote = $remote;
    }

    public function isRemote(): bool
    {
        return $this->remote !== null;
    }

    /** @var null|\Closure(Command): ?Refusal what this player may do (shared-company roles) */
    private ?\Closure $localGuard = null;

    /** @param null|\Closure(Command): ?Refusal $guard */
    public function setLocalGuard(?\Closure $guard): void
    {
        $this->localGuard = $guard;
    }

    /**
     * @var null|\Closure(Command): (Refusal|bool) holds a checked command back
     *      instead of applying it (waiting for consent): true = held, false = go on
     */
    private ?\Closure $deferral = null;

    /** @param null|\Closure(Command): (Refusal|bool) $deferral */
    public function setDeferral(?\Closure $deferral): void
    {
        $this->deferral = $deferral;
    }

    /** @var null|\Closure(Command): void told when a click of this player's was applied here */
    private ?\Closure $applied = null;

    /** @param null|\Closure(Command): void $applied */
    public function setApplied(?\Closure $applied): void
    {
        $this->applied = $applied;
    }

    /**
     * Issue a command from this game's UI. True when it was applied here, or
     * sent to the host; false when it was refused (the player is told why).
     */
    public function dispatch(object $state, Command $command): bool
    {
        $refusal = $this->localGuard !== null ? ($this->localGuard)($command) : null;
        if ($refusal !== null) {
            ($this->refused)($state, $refusal);
            return false;
        }

        if ($this->remote !== null) {
            // Answer the obvious locally, so a click that cannot work says so
            // at once instead of after a round trip.
            $refusal = $this->check($state, $command);
            if ($refusal !== null) {
                ($this->refused)($state, $refusal);
                return false;
            }
            ($this->remote)($command);
            return true;
        }

        if ($this->deferral !== null) {
            $held = $this->check($state, $command) ?? ($this->deferral)($command);
            if ($held instanceof Refusal) {
                ($this->refused)($state, $held);
                return false;
            }
            if ($held === true) {
                return true;
            }
        }

        if ($this->execute($state, $command) !== null) {
            return false;
        }
        if ($this->applied !== null) {
            ($this->applied)($command);
        }
        return true;
    }

    /**
     * Check and apply: null when applied, otherwise why not. Tells the player
     * on this machine about a refusal.
     */
    public function execute(object $state, Command $command): ?Refusal
    {
        $refusal = $this->check($state, $command);
        if ($refusal !== null) {
            ($this->refused)($state, $refusal);
            return $refusal;
        }
        $this->apply($state, $command);
        return null;
    }

    /** Why the command cannot be applied to this state, or null when it can. */
    public function check(object $state, Command $command): ?Refusal
    {
        return ($this->check)($state, $command);
    }

    /** Apply it, unchecked and without telling anyone - a session that checked already. */
    public function apply(object $state, Command $command): void
    {
        ($this->apply)($state, $command);
    }

    /** Tell the player on this machine why something did not happen. */
    public function refused(object $state, Refusal $refusal): void
    {
        ($this->refused)($state, $refusal);
    }
}
