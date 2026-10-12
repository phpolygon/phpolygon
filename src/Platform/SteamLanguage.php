<?php

declare(strict_types=1);

namespace PHPolygon\Platform;

/**
 * The language Steam wants the game in, as BCP-47-like tags.
 *
 * Steam reports API language codes ("german", "schinese", "latam" - see
 * https://partner.steamgames.com/doc/store/localization/languages). The
 * per-game language (library properties, or `-language <code>` in the launch
 * options) comes first; Steam already falls back to the client language when
 * none is set, and {@see detect()} adds the client language explicitly so a
 * per-game language the game does not ship still lands on the client's.
 *
 * Needs a running Steam session (php-steamworks `steam_init()`, e.g. through
 * {@see SteamSession::start()}). Without the extension, without Steam, or
 * before initialisation the answer is simply empty - never an error.
 */
final class SteamLanguage
{
    /** Steam API language code => locale tag (normal form of {@see \PHPolygon\Locale\LocaleMatcher}). */
    public const API_CODES = [
        'arabic'     => 'ar',
        'bulgarian'  => 'bg',
        'schinese'   => 'zh-CN',
        'tchinese'   => 'zh-TW',
        'czech'      => 'cs',
        'danish'     => 'da',
        'dutch'      => 'nl',
        'english'    => 'en',
        'finnish'    => 'fi',
        'french'     => 'fr',
        'german'     => 'de',
        'greek'      => 'el',
        'hungarian'  => 'hu',
        'indonesian' => 'id',
        'italian'    => 'it',
        'japanese'   => 'ja',
        'koreana'    => 'ko',
        'malay'      => 'ms',
        'norwegian'  => 'no',
        'polish'     => 'pl',
        'portuguese' => 'pt-PT',
        'brazilian'  => 'pt-BR',
        'romanian'   => 'ro',
        'russian'    => 'ru',
        'spanish'    => 'es-ES',
        'latam'      => 'es-419',
        'swedish'    => 'sv',
        'thai'       => 'th',
        'turkish'    => 'tr',
        'ukrainian'  => 'uk',
        'vietnamese' => 'vi',
    ];

    /** Older spellings some clients and launch options still use. */
    private const ALIASES = ['korean' => 'ko'];

    /** The functions asked, in order: per-game language, then client UI language. */
    private const SOURCES = ['steam_apps_get_language', 'steam_utils_get_steam_ui_language'];

    private function __construct() {}

    /** Locale tag for a Steam API language code, null when unknown. */
    public static function toLocaleTag(string $apiCode): ?string
    {
        $code = strtolower(trim($apiCode));
        return self::API_CODES[$code] ?? self::ALIASES[$code] ?? null;
    }

    /**
     * Steam's languages as locale tags, most wanted first; empty without Steam.
     *
     * @param null|callable(string): mixed $call runs one php-steamworks function
     *        by name (tests pass a fake); null, false or '' mean "no answer"
     * @return list<string>
     */
    public static function detect(?callable $call = null): array
    {
        $call ??= self::call(...);
        $tags = [];
        foreach (self::SOURCES as $function) {
            try {
                $code = $call($function);
            } catch (\Throwable) {
                continue;
            }
            $tag = is_string($code) ? self::toLocaleTag($code) : null;
            if ($tag !== null && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }
        return $tags;
    }

    /**
     * Calls a php-steamworks function if the extension has it. The binding
     * warns "Steam not initialized" and returns false before steam_init(); that
     * warning is expected here and must not reach a game's error handler.
     */
    private static function call(string $function): mixed
    {
        if (!function_exists($function)) {
            return null;
        }
        set_error_handler(static fn (): bool => true);
        try {
            return $function();
        } finally {
            restore_error_handler();
        }
    }
}
