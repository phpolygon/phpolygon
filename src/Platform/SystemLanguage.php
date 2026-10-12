<?php

declare(strict_types=1);

namespace PHPolygon\Platform;

use PHPolygon\Locale\LocaleMatcher;

/**
 * The operating system's language as BCP-47-like tags, without ext-intl.
 *
 * - Windows: the user locale from the C runtime (`setlocale(LC_CTYPE, '')`,
 *   e.g. "German_Germany.1252"), restored right after; then the environment.
 *   Note this is the regional format setting, which almost always matches the
 *   display language.
 * - Linux and others: `LANGUAGE` (GNU priority list), `LC_ALL`, `LC_MESSAGES`, `LANG`.
 * - macOS: the environment, then `defaults read -g AppleLanguages` - an app
 *   started from Finder has no `LANG`. That probe is a short child process
 *   with a hard timeout, so it cannot hang the start.
 *
 * Every probe failing just means fewer tags; nothing here throws.
 */
final class SystemLanguage
{
    /** English language names as the Windows C runtime reports them. */
    private const WINDOWS_LANGUAGES = [
        'arabic' => 'ar', 'bulgarian' => 'bg', 'catalan' => 'ca', 'chinese' => 'zh',
        'chinese (simplified)' => 'zh-Hans', 'chinese (traditional)' => 'zh-Hant',
        'croatian' => 'hr', 'czech' => 'cs', 'danish' => 'da', 'dutch' => 'nl', 'english' => 'en',
        'estonian' => 'et', 'filipino' => 'fil', 'finnish' => 'fi', 'french' => 'fr', 'german' => 'de',
        'greek' => 'el', 'hebrew' => 'he', 'hindi' => 'hi', 'hungarian' => 'hu', 'indonesian' => 'id',
        'italian' => 'it', 'japanese' => 'ja', 'korean' => 'ko', 'latvian' => 'lv', 'lithuanian' => 'lt',
        'malay' => 'ms', 'norwegian' => 'no', 'norwegian bokmål' => 'nb', 'norwegian (bokmål)' => 'nb',
        'norwegian nynorsk' => 'nn', 'norwegian (nynorsk)' => 'nn', 'persian' => 'fa', 'polish' => 'pl',
        'portuguese' => 'pt', 'romanian' => 'ro', 'russian' => 'ru', 'serbian' => 'sr', 'slovak' => 'sk',
        'slovenian' => 'sl', 'spanish' => 'es', 'swedish' => 'sv', 'thai' => 'th', 'turkish' => 'tr',
        'ukrainian' => 'uk', 'vietnamese' => 'vi',
    ];

    /** Country names the Windows C runtime reports, for the regions a game may split by. */
    private const WINDOWS_COUNTRIES = [
        'argentina' => 'AR', 'australia' => 'AU', 'austria' => 'AT', 'belgium' => 'BE', 'brazil' => 'BR',
        'canada' => 'CA', 'chile' => 'CL', 'china' => 'CN', "people's republic of china" => 'CN',
        'colombia' => 'CO', 'france' => 'FR', 'germany' => 'DE', 'hong kong' => 'HK', 'hong kong sar' => 'HK',
        'ireland' => 'IE', 'italy' => 'IT', 'japan' => 'JP', 'korea' => 'KR', 'macao' => 'MO',
        'macao sar' => 'MO', 'malaysia' => 'MY', 'mexico' => 'MX', 'netherlands' => 'NL', 'new zealand' => 'NZ',
        'norway' => 'NO', 'peru' => 'PE', 'portugal' => 'PT', 'russia' => 'RU', 'singapore' => 'SG',
        'spain' => 'ES', 'switzerland' => 'CH', 'taiwan' => 'TW', 'united kingdom' => 'GB',
        'united states' => 'US', 'uruguay' => 'UY', 'venezuela' => 'VE',
    ];

    private const ENV_VARS = ['LC_ALL', 'LC_MESSAGES', 'LANG'];

    /** Seconds the macOS language probe may take before it is abandoned. */
    private const PROBE_TIMEOUT = 1.0;

    private function __construct() {}

    /**
     * The system's languages, most wanted first.
     *
     * @param array<string, string>|null $env      environment (null = the process's)
     * @param string|null                $osFamily PHP_OS_FAMILY value (null = this machine)
     * @param null|callable(): ?string   $windowsLocale probe for the Windows user locale name
     * @param null|callable(): ?string   $appleLanguages probe for `defaults read -g AppleLanguages`
     * @return list<string>
     */
    public static function detect(
        ?array $env = null,
        ?string $osFamily = null,
        ?callable $windowsLocale = null,
        ?callable $appleLanguages = null,
    ): array {
        $env ??= self::environment();
        $osFamily ??= PHP_OS_FAMILY;
        $windowsLocale ??= self::windowsUserLocale(...);
        $appleLanguages ??= self::appleLanguagesOutput(...);

        $tags = [];
        if ($osFamily === 'Windows') {
            $name = self::probe($windowsLocale);
            $tag = $name !== null ? self::fromWindowsLocale($name) : null;
            if ($tag !== null) {
                $tags[] = $tag;
            }
        }
        $tags = array_merge($tags, self::fromEnvironment($env));
        if ($osFamily === 'Darwin') {
            $tags = array_merge($tags, self::fromAppleLanguages(self::probe($appleLanguages) ?? ''));
        }

        return array_values(array_unique($tags));
    }

    /**
     * Tags from the POSIX locale variables, in gettext's order of precedence.
     *
     * @param array<string, string> $env
     * @return list<string>
     */
    public static function fromEnvironment(array $env): array
    {
        $main = null;
        $tags = [];
        foreach (self::ENV_VARS as $var) {
            $value = trim($env[$var] ?? '');
            if ($value === '') {
                continue;
            }
            // The first set variable decides the locale; C means "untranslated".
            $main ??= $value;
            $tag = LocaleMatcher::normalize($value);
            if ($tag !== null) {
                $tags[] = $tag;
            }
        }

        if ($main !== null && LocaleMatcher::normalize($main) === null) {
            return [];
        }

        $list = [];
        foreach (explode(':', $env['LANGUAGE'] ?? '') as $entry) {
            $tag = LocaleMatcher::normalize($entry);
            if ($tag !== null) {
                $list[] = $tag;
            }
        }

        return array_values(array_unique(array_merge($list, $tags)));
    }

    /**
     * Tag for a Windows C-runtime locale name such as "German_Germany.1252" or
     * "Chinese (Traditional)_Taiwan.950"; a name already in tag form passes through.
     */
    public static function fromWindowsLocale(string $name): ?string
    {
        $name = trim($name);
        if (str_contains($name, '-') && !str_contains($name, ' ') && !str_contains($name, '_')) {
            return LocaleMatcher::normalize($name);
        }

        $name = (string) preg_replace('/\.[^.]*$/', '', $name);
        [$language, $country] = array_pad(explode('_', $name, 2), 2, '');
        // Old sort-order variants: "Spanish - Modern Sort".
        $language = strtolower(trim(explode(' - ', $language)[0]));

        // The runtime answers in the ANSI code page, so "Norwegian Bokmål" may
        // not arrive as UTF-8; the first word still names the language.
        $tag = self::WINDOWS_LANGUAGES[$language]
            ?? self::WINDOWS_LANGUAGES[strtok($language, ' (') ?: $language]
            ?? null;
        if ($tag === null) {
            return LocaleMatcher::normalize($name);
        }
        $region = self::WINDOWS_COUNTRIES[strtolower(trim($country))] ?? null;

        return LocaleMatcher::normalize($region !== null ? $tag . '-' . $region : $tag);
    }

    /**
     * Tags from the output of `defaults read -g AppleLanguages` - a property
     * list array like `( "de-DE", en )`.
     *
     * @return list<string>
     */
    public static function fromAppleLanguages(string $output): array
    {
        $trimmed = trim($output);
        if (!str_starts_with($trimmed, '(')) {
            return [];
        }
        $tags = [];
        foreach (preg_split('/\R/', $trimmed) ?: [] as $line) {
            $entry = trim($line, " \t\",()");
            $tag = $entry !== '' ? LocaleMatcher::normalize($entry) : null;
            if ($tag !== null) {
                $tags[] = $tag;
            }
        }
        return array_values(array_unique($tags));
    }

    /**
     * The Windows user locale name ("German_Germany.1252"), or null. Briefly
     * switches LC_CTYPE to the user default and puts the previous value back,
     * so the process locale is unchanged afterwards.
     */
    public static function windowsUserLocale(): ?string
    {
        $previous = setlocale(LC_CTYPE, '0');
        $name = setlocale(LC_CTYPE, '');
        if ($previous !== false) {
            setlocale(LC_CTYPE, $previous);
        }
        return is_string($name) && $name !== '' ? $name : null;
    }

    /** Output of `defaults read -g AppleLanguages`, null when unavailable or too slow. */
    public static function appleLanguagesOutput(): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $pipes = [];
        $process = @proc_open(
            ['defaults', 'read', '-g', 'AppleLanguages'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return null;
        }

        stream_set_blocking($pipes[1], false);
        $output = '';
        $deadline = microtime(true) + self::PROBE_TIMEOUT;
        while (!feof($pipes[1]) && microtime(true) < $deadline) {
            $read = [$pipes[1]];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50_000) > 0) {
                $output .= (string) fread($pipes[1], 8192);
            }
        }
        $finished = feof($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (!$finished) {
            proc_terminate($process);
        }
        proc_close($process);

        return $finished && $output !== '' ? $output : null;
    }

    /** @param callable(): ?string $probe */
    private static function probe(callable $probe): ?string
    {
        try {
            $value = $probe();
        } catch (\Throwable) {
            return null;
        }
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $env = [];
        foreach (['LANGUAGE', ...self::ENV_VARS] as $var) {
            $value = getenv($var);
            if (is_string($value)) {
                $env[$var] = $value;
            }
        }
        return $env;
    }
}
