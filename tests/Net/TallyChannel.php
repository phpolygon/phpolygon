<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Net;

use PHPolygon\Command\Refusal;
use PHPolygon\Net\StateChannel;

/** The game's side of a session: what a state is, and what a player is told. */
final class TallyChannel implements StateChannel
{
    public function capture(object $state): array
    {
        assert($state instanceof Tally);
        return ['value' => $state->value, 'blob' => $state->blob];
    }

    public function restore(object $state, array $data): void
    {
        assert($state instanceof Tally);
        $state->value = is_int($data['value'] ?? null) ? $data['value'] : 0;
        $state->blob = is_string($data['blob'] ?? null) ? $data['blob'] : '';
    }

    public function news(object $state): array
    {
        assert($state instanceof Tally);
        $news = $state->news;
        $state->news = [];
        return $news === [] ? [] : ['lines' => $news];
    }

    public function showNews(object $state, array $news): void
    {
        assert($state instanceof Tally);
        foreach (is_array($news['lines'] ?? null) ? $news['lines'] : [] as $line) {
            $state->told[] = (string) $line;
        }
    }

    public function refused(object $state, Refusal $refusal): void
    {
        assert($state instanceof Tally);
        $state->told[] = $refusal->key;
    }
}
