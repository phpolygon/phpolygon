<?php

declare(strict_types=1);

namespace PHPolygon\Locale;

class LocaleManager
{
    private string $currentLocale;
    private string $fallbackLocale;

    /** @var array<string, array<string, string>> locale => [key => translation] */
    private array $translations = [];

    public function __construct(
        string $defaultLocale = 'en',
        string $fallbackLocale = 'en',
    ) {
        $this->currentLocale = $defaultLocale;
        $this->fallbackLocale = $fallbackLocale;
    }

    public function getLocale(): string
    {
        return $this->currentLocale;
    }

    public function setLocale(string $locale): void
    {
        $this->currentLocale = $locale;
    }

    public function getFallbackLocale(): string
    {
        return $this->fallbackLocale;
    }

    public function setFallbackLocale(string $locale): void
    {
        $this->fallbackLocale = $locale;
    }

    /**
     * @return list<string>
     */
    public function getAvailableLocales(): array
    {
        return array_keys($this->translations);
    }

    /**
     * Load translations from a JSON file.
     *
     * Expected format: { "key": "Translation text", "menu.start": "Start Game" }
     * Keys can use dot notation for grouping.
     */
    public function loadFile(string $locale, string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Translation file not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read translation file: {$path}");
        }

        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Translation file must contain a JSON object: {$path}");
        }
        /** @var array<string, string|array<mixed>> $data */
        $data = $decoded;

        $flat = $this->flatten($data);
        $this->translations[$locale] = array_merge(
            $this->translations[$locale] ?? [],
            $flat,
        );
    }

    /**
     * Load all JSON files from a directory.
     *
     * Each file should be named {locale}.json (e.g. en.json, de.json).
     */
    public function loadDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException("Translation directory not found: {$directory}");
        }

        $files = glob($directory . '/*.json');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            $locale = pathinfo($file, PATHINFO_FILENAME);
            $this->loadFile($locale, $file);
        }
    }

    /**
     * Add translations programmatically.
     *
     * @param array<string, string> $translations
     */
    public function add(string $locale, array $translations): void
    {
        $this->translations[$locale] = array_merge(
            $this->translations[$locale] ?? [],
            $translations,
        );
    }

    /**
     * Translate a key with optional placeholder replacement.
     *
     * Placeholders use :name syntax: "Hello :name" with ['name' => 'World']
     * produces "Hello World".
     *
     * @param array<string, string|int|float> $params
     */
    public function get(string $key, array $params = []): string
    {
        $text = $this->translations[$this->currentLocale][$key]
            ?? $this->translations[$this->fallbackLocale][$key]
            ?? $key;

        if ($params !== []) {
            foreach ($params as $param => $value) {
                $text = str_replace(':' . $param, (string) $value, $text);
            }
        }

        return $text;
    }

    /**
     * Shorthand alias for get().
     *
     * @param array<string, string|int|float> $params
     */
    public function t(string $key, array $params = []): string
    {
        return $this->get($key, $params);
    }

    /**
     * Translate a key with pluralization.
     *
     * Translation strings use pipe-separated forms: "singular|plural"
     * Supports explicit count forms: "{0} None|{1} One|[2,*] Many"
     * The :count parameter is added automatically.
     *
     * @param array<string, string|int|float> $params
     */
    public function choice(string $key, int $count, array $params = []): string
    {
        $raw = $this->translations[$this->currentLocale][$key]
            ?? $this->translations[$this->fallbackLocale][$key]
            ?? $key;

        $text = $this->selectPluralForm($raw, $count);
        $params['count'] = $count;

        foreach ($params as $param => $value) {
            $text = str_replace(':' . $param, (string) $value, $text);
        }

        return $text;
    }

    /**
     * Check whether a translation key exists for the current locale.
     */
    public function has(string $key): bool
    {
        return isset($this->translations[$this->currentLocale][$key])
            || isset($this->translations[$this->fallbackLocale][$key]);
    }

    private function selectPluralForm(string $raw, int $count): string
    {
        $forms = explode('|', $raw);

        if (count($forms) === 1) {
            return $forms[0];
        }

        // Check for explicit count/range matches: {0} None|{1} One|[2,*] Many
        $hasExplicit = false;
        foreach ($forms as $form) {
            $trimmed = trim($form);
            if (preg_match('/^\{\d+\}/', $trimmed) || preg_match('/^\[\d+,/', $trimmed)) {
                $hasExplicit = true;
                break;
            }
        }

        if ($hasExplicit) {
            foreach ($forms as $form) {
                $form = trim($form);
                if (preg_match('/^\{(\d+)\}\s*(.+)$/', $form, $m)) {
                    if ((int) $m[1] === $count) {
                        return $m[2];
                    }
                    continue;
                }
                if (preg_match('/^\[(\d+),\s*(\d+|\*)\]\s*(.+)$/', $form, $m)) {
                    $min = (int) $m[1];
                    $max = $m[2] === '*' ? PHP_INT_MAX : (int) $m[2];
                    if ($count >= $min && $count <= $max) {
                        return $m[3];
                    }
                    continue;
                }
            }
            return trim(end($forms));
        }

        // Positional forms — use CLDR plural index for the current locale.
        // This supports languages with more than two plural forms (e.g. Slavic languages).
        $index = min($this->getPluralIndex($this->currentLocale, $count), count($forms) - 1);
        return trim($forms[$index]);
    }

    /**
     * Return the CLDR plural form index for a given locale and count.
     *
     * Index 0 = one/singular, 1 = few, 2 = many/other.
     * For two-form languages (Germanic, Romance, …) only 0 and 1 are used.
     */
    private function getPluralIndex(string $locale, int $n): int
    {
        // Languages with no grammatical number distinction (East Asian, South-East Asian)
        if (in_array($locale, ['ja', 'ko', 'zh-CN', 'zh-TW', 'zh', 'th', 'vi', 'id', 'ms'], true)) {
            return 0;
        }

        // Slavic: Russian, Ukrainian, Bulgarian
        // one:  n mod 10 == 1 AND n mod 100 != 11
        // few:  n mod 10 in 2..4 AND n mod 100 not in 12..14
        // many: everything else
        if (in_array($locale, ['ru', 'uk', 'bg'], true)) {
            $mod10 = $n % 10;
            $mod100 = $n % 100;
            if ($mod10 === 1 && $mod100 !== 11) {
                return 0;
            }
            if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
                return 1;
            }
            return 2;
        }

        // Polish
        // one:  n == 1
        // few:  n mod 10 in 2..4 AND n mod 100 not in 12..14
        // many: everything else
        if ($locale === 'pl') {
            $mod10 = $n % 10;
            $mod100 = $n % 100;
            if ($n === 1) {
                return 0;
            }
            if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
                return 1;
            }
            return 2;
        }

        // Czech
        // one: n == 1 | few: n in 2..4 | many: else
        if ($locale === 'cs') {
            if ($n === 1) {
                return 0;
            }
            if ($n >= 2 && $n <= 4) {
                return 1;
            }
            return 2;
        }

        // Romanian
        // one: n == 1 | few: n == 0 or n mod 100 in 1..19 | many: else
        if ($locale === 'ro') {
            if ($n === 1) {
                return 0;
            }
            $mod100 = $n % 100;
            if ($n === 0 || ($mod100 >= 1 && $mod100 <= 19)) {
                return 1;
            }
            return 2;
        }

        // Default: two-form (one vs. other) — covers Germanic, Romance, Greek, Turkish, …
        return $n === 1 ? 0 : 1;
    }

    /**
     * Flatten a nested array into dot-notation keys.
     *
     * @param array<string|int, mixed> $data
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix . '.' . $key : (string) $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flatten($value, $fullKey));
            } elseif (is_string($value)) {
                $result[$fullKey] = $value;
            } else {
                $result[$fullKey] = is_scalar($value) ? (string) $value : '';
            }
        }
        return $result;
    }
}
