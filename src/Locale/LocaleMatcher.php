<?php

declare(strict_types=1);

namespace PHPolygon\Locale;

/**
 * Matches a language tag from the outside world (Steam, the operating system,
 * a command line) against the locales a game actually ships.
 *
 * Tags are BCP-47-like: `language[-Script][-REGION]`. Input may be POSIX
 * (`de_DE.UTF-8@euro`), any case, or carry legacy codes (`in`, `iw`). A game's
 * catalogue may be bare (`es`, `pt`, `zh`) or split by region (`es-ES` /
 * `es-419`, `pt-PT` / `pt-BR`, `zh-CN` / `zh-TW`); the matcher picks the
 * closest entry and returns it in the catalogue's own spelling.
 *
 * Regional rules, because the generic "drop the region" step gets them wrong:
 * - Spanish outside Spain prefers Latin American (`es-419`), Spain/bare prefers `es-ES`.
 * - Portuguese outside Brazil prefers `pt-PT`, Brazil prefers `pt-BR`.
 * - Chinese is split by script: Hans (CN, SG, MY, bare) vs. Hant (TW, HK, MO).
 *   The other script is only taken as a last resort.
 * - Norwegian `no`, `nb` and `nn` stand in for each other.
 */
final class LocaleMatcher
{
    private const LEGACY = ['in' => 'id', 'iw' => 'he', 'ji' => 'yi', 'jw' => 'jv'];

    private const NORWEGIAN = ['no', 'nb', 'nn'];

    private const HANT_REGIONS = ['TW', 'HK', 'MO'];

    private function __construct() {}

    /**
     * Canonical `language[-Script][-REGION]` form, or null for the C/POSIX
     * locale and anything that is not a language tag.
     */
    public static function normalize(string $raw): ?string
    {
        $parts = self::parse($raw);
        if ($parts === null) {
            return null;
        }
        [$language, $script, $region] = $parts;

        return $language
            . ($script !== null ? '-' . $script : '')
            . ($region !== null ? '-' . $region : '');
    }

    /**
     * The catalogue entry closest to $tag, or null when the game does not ship
     * that language at all.
     *
     * @param list<string> $available the game's locale codes
     * @param array<string, string> $aliases game code => the tag it stands for, for codes
     *        that are not tags themselves (e.g. a 'latam' file meaning es-419)
     */
    public static function match(string $tag, array $available, array $aliases = []): ?string
    {
        $parts = self::parse($tag);
        if ($parts === null || $available === []) {
            return null;
        }

        $index = [];
        foreach ($available as $code) {
            $normal = self::normalize($aliases[$code] ?? $code);
            if ($normal !== null && !isset($index[strtolower($normal)])) {
                $index[strtolower($normal)] = $code;
            }
        }

        foreach (self::candidates(...$parts) as $candidate) {
            if (isset($index[strtolower($candidate)])) {
                return $index[strtolower($candidate)];
            }
        }

        // Any region of the same language, in catalogue order - for Chinese
        // this is the other script, which still beats no Chinese at all.
        $family = self::family($parts[0]);
        foreach ($available as $code) {
            $other = self::parse($aliases[$code] ?? $code);
            if ($other !== null && in_array($other[0], $family, true)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * The first preference the game ships, or null.
     *
     * @param list<string> $preferred tags, most wanted first
     * @param list<string> $available the game's locale codes
     * @param array<string, string> $aliases see {@see match()}
     */
    public static function matchFirst(array $preferred, array $available, array $aliases = []): ?string
    {
        foreach ($preferred as $tag) {
            $match = self::match($tag, $available, $aliases);
            if ($match !== null) {
                return $match;
            }
        }
        return null;
    }

    /**
     * Ordered tags to look up for one request; the language-only and
     * same-family scans in {@see match()} run after these.
     *
     * @return list<string>
     */
    private static function candidates(string $language, ?string $script, ?string $region): array
    {
        $list = [];
        if ($script !== null && $region !== null) {
            $list[] = "{$language}-{$script}-{$region}";
        }
        if ($region !== null) {
            $list[] = "{$language}-{$region}";
        }
        if ($script !== null) {
            $list[] = "{$language}-{$script}";
        }

        switch ($language) {
            case 'zh':
                $list = array_merge($list, self::isTraditional($script, $region)
                    ? ['zh-TW', 'zh-Hant', 'zh-HK', 'zh-MO']
                    : ['zh-CN', 'zh-Hans', 'zh-SG']);
                break;
            case 'es':
                $list = array_merge($list, $region === null || $region === 'ES'
                    ? ['es-ES']
                    : ['es-419', 'es-MX']);
                break;
            case 'pt':
                $list = array_merge($list, $region === 'BR' ? ['pt-BR'] : ['pt-PT']);
                break;
        }

        $list[] = $language;
        if (in_array($language, self::NORWEGIAN, true)) {
            foreach (self::NORWEGIAN as $sibling) {
                $list[] = $sibling;
                $list[] = $sibling . '-NO';
            }
        }

        return array_values(array_unique($list));
    }

    /** @return list<string> */
    private static function family(string $language): array
    {
        return in_array($language, self::NORWEGIAN, true) ? self::NORWEGIAN : [$language];
    }

    private static function isTraditional(?string $script, ?string $region): bool
    {
        if ($script !== null) {
            return $script === 'Hant';
        }
        return $region !== null && in_array($region, self::HANT_REGIONS, true);
    }

    /** @return array{0: string, 1: ?string, 2: ?string}|null */
    private static function parse(string $raw): ?array
    {
        // Drop the POSIX code set (".UTF-8") and modifier ("@euro").
        $tag = trim((string) preg_replace('/[.@].*$/', '', trim($raw)));
        if ($tag === '' || strcasecmp($tag, 'C') === 0 || strcasecmp($tag, 'POSIX') === 0) {
            return null;
        }

        $subtags = preg_split('/[-_]/', $tag) ?: [];
        $language = strtolower(array_shift($subtags) ?? '');
        if (preg_match('/^[a-z]{2,3}$/', $language) !== 1) {
            return null;
        }
        $language = self::LEGACY[$language] ?? $language;

        $script = null;
        $region = null;
        foreach ($subtags as $subtag) {
            if ($script === null && $region === null && preg_match('/^[A-Za-z]{4}$/', $subtag) === 1) {
                $script = ucfirst(strtolower($subtag));
            } elseif ($region === null && preg_match('/^([A-Za-z]{2}|\d{3})$/', $subtag) === 1) {
                $region = strtoupper($subtag);
            } else {
                break;
            }
        }

        return [$language, $script, $region];
    }
}
