<?php

declare(strict_types=1);

namespace PHPolygon\Net;

use PHPolygon\Command\Refusal;

/**
 * The game's side of a session: what its state is worth sending, and how a
 * player is told something.
 *
 * A session knows nothing about the game it carries. It asks for a state as
 * data ({@see capture()}), puts one back in on the other end
 * ({@see restore()}), and passes on what happened in words - the toasts, the
 * newsticker - separately, because those are read once rather than held.
 */
interface StateChannel
{
    /**
     * The whole game as data: what a save holds, and none of the screen.
     *
     * @return array<string, mixed>
     */
    public function capture(object $state): array;

    /**
     * Take a captured state in over this one. What belongs to this machine's
     * screen is the game's to keep.
     *
     * @param array<string, mixed> $data
     */
    public function restore(object $state, array $data): void;

    /**
     * What the state has to say since the last call, taken as it is returned:
     * every line goes out once. Empty when there is nothing.
     *
     * @return array<string, mixed>
     */
    public function news(object $state): array;

    /**
     * Put news from the other end in front of this player.
     *
     * @param array<string, mixed> $news as {@see news()} returned it
     */
    public function showNews(object $state, array $news): void;

    /** Tell this player why something they asked for did not happen. */
    public function refused(object $state, Refusal $refusal): void;
}
