<?php

declare(strict_types=1);

namespace PHPolygon\Locale;

class LocaleManager
{
    private string $currentLocale;
    private string $fallbackLocale;

    /** @var array<string, array<string, string>> locale => [key => translation] */
    private array $translations = [];

    /** @var list<string> */
    private array $loadedFiles = [];

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
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $flat = $this->flatten($data);
        $this->translations[$locale] = array_merge(
            $this->translations[$locale] ?? [],
            $flat,
        );

        $this->loadedFiles[] = $path;
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

        // Check for explicit count matches: {0} None|{1} One|[2,*] Many
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

        // Simple two-form: singular|plural
        if (count($forms) === 2) {
            return $count === 1 ? trim($forms[0]) : trim($forms[1]);
        }

        // Three forms: zero|one|many
        if (count($forms) === 3) {
            if ($count === 0) {
                return trim($forms[0]);
            }
            return $count === 1 ? trim($forms[1]) : trim($forms[2]);
        }

        return trim(end($forms));
    }

    /**
     * Flatten a nested array into dot-notation keys.
     *
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix . '.' . $key : $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flatten($value, $fullKey));
            } else {
                $result[$fullKey] = (string) $value;
            }
        }
        return $result;
    }
}
