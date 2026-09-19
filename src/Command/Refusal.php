<?php

declare(strict_types=1);

namespace PHPolygon\Command;

/**
 * Why a command was not applied: a locale key and its parameters, so the
 * player who issued it can read it in their own language - on their machine,
 * which may not be the one that refused it. Translating is the game's part.
 */
class Refusal
{
    /** @param array<string, scalar> $params */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
    ) {}
}
