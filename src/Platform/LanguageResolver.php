<?php

declare(strict_types=1);

namespace PHPolygon\Platform;

use PHPolygon\Locale\LocaleMatcher;

/**
 * Picks the locale a game starts in: the player's saved choice, else Steam's
 * language, else the operating system's, else the game's fallback - each
 * matched against the locales the game ships ({@see LocaleMatcher}).
 *
 *     SteamSession::start();                       // when the game uses Steam
 *     $start = (new LanguageResolver($codes, 'en'))->resolve($saved);
 *     $engine->locale->setLocale($start->locale);
 *     // save only when the player picks one: $start->wasChosen()
 *
 * Sources are asked lazily and in order; a saved choice asks nobody. A source
 * that throws counts as silent.
 */
final class LanguageResolver
{
    /** @var callable(): list<string> */
    private $steam;

    /** @var callable(): list<string> */
    private $system;

    /**
     * @param list<string> $available the game's locale codes
     * @param string $fallback locale when nothing matches
     * @param null|callable(): list<string> $steam tags from Steam (default {@see SteamLanguage::detect()})
     * @param null|callable(): list<string> $system tags from the OS (default {@see SystemLanguage::detect()})
     * @param array<string, string> $aliases game code => tag, for codes that are not tags (see {@see LocaleMatcher::match()})
     */
    public function __construct(
        private readonly array $available,
        private readonly string $fallback,
        ?callable $steam = null,
        ?callable $system = null,
        private readonly array $aliases = [],
    ) {
        $this->steam = $steam ?? static fn (): array => SteamLanguage::detect();
        $this->system = $system ?? static fn (): array => SystemLanguage::detect();
    }

    /** @param string|null $saved the player's stored choice; null or '' = none */
    public function resolve(?string $saved = null): ResolvedLanguage
    {
        if ($saved !== null && $saved !== '') {
            // A saved choice is one of the game's own codes - which need not be a tag.
            $match = in_array($saved, $this->available, true)
                ? $saved
                : LocaleMatcher::match($this->aliases[$saved] ?? $saved, $this->available, $this->aliases);
            if ($match !== null) {
                return new ResolvedLanguage($match, ResolvedLanguage::SAVED);
            }
        }

        foreach ([ResolvedLanguage::STEAM => $this->steam, ResolvedLanguage::SYSTEM => $this->system] as $source => $tags) {
            try {
                $preferred = $tags();
            } catch (\Throwable) {
                continue;
            }
            $match = LocaleMatcher::matchFirst($preferred, $this->available, $this->aliases);
            if ($match !== null) {
                return new ResolvedLanguage($match, $source);
            }
        }

        return new ResolvedLanguage($this->fallback, ResolvedLanguage::FALLBACK);
    }
}
