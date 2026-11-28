<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use PHPolygon\Log\Log;

/**
 * Sound is optional at run time: a backend that cannot open a clip (missing
 * file, a format its decoder does not know, a broken download) plays nothing
 * instead of ending the game, and says so once per clip in game.log.
 */
trait ReportsUnplayableClips
{
    /** @var array<string, string> clipId => path that could not be opened */
    private array $unplayable = [];

    private function isUnplayable(string $clipId, string $path): bool
    {
        return ($this->unplayable[$clipId] ?? null) === $path;
    }

    private function reportUnplayable(string $clipId, string $path, string $reason): void
    {
        if ($this->isUnplayable($clipId, $path)) {
            return;
        }
        $this->unplayable[$clipId] = $path;
        Log::error("Audio clip '{$clipId}' cannot be played, it stays silent: {$reason} ({$path})");
    }
}
