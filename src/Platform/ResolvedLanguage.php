<?php

declare(strict_types=1);

namespace PHPolygon\Platform;

/** The locale a game starts in, and where that answer came from. */
final readonly class ResolvedLanguage
{
    /** The player picked it (a saved setting). */
    public const SAVED = 'saved';
    /** Steam's per-game or client language. */
    public const STEAM = 'steam';
    /** The operating system's language. */
    public const SYSTEM = 'system';
    /** Nothing matched; the game's fallback. */
    public const FALLBACK = 'fallback';

    public function __construct(
        /** One of the game's own locale codes (except a fallback the game passed in). */
        public string $locale,
        /** One of the constants above. */
        public string $source,
    ) {}

    /**
     * Did the player choose this? Only then is it worth saving - a detected
     * language written back as a choice would never follow a later change of
     * the Steam or system language.
     */
    public function wasChosen(): bool
    {
        return $this->source === self::SAVED;
    }
}
