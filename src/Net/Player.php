<?php

declare(strict_types=1);

namespace PHPolygon\Net;

/** A partner in a session, as the host knows them. */
final class Player
{
    /**
     * @param list<string> $roles the parts of the game this partner looks after,
     *                            named by the game; empty = all, or none - the
     *                            game's authorizer decides
     * @param array<string, mixed> $hello what they said when they joined
     */
    public function __construct(
        public readonly int $peer,
        public readonly int $account,
        public string $name,
        public array $roles = [],
        public array $hello = [],
    ) {}
}
