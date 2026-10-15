<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * Where a generated line lives. A game finds its voice lines with the same
 * function the generator writes them with.
 */
final class SpeechFiles
{
    public const string EXTENSION = 'flac';
    public const string MANIFEST = 'speech.lock.json';

    private function __construct() {}

    /** `{dir}/{locale}/{key}.flac`; characters outside A-Z a-z 0-9 . _ - become `_`. */
    public static function path(string $dir, string $locale, string $key): string
    {
        return rtrim($dir, '/\\') . '/' . self::segment($locale) . '/' . self::segment($key) . '.' . self::EXTENSION;
    }

    /** The line in the locale, else in the fallback locale, else null. */
    public static function find(string $dir, string $locale, string $key, ?string $fallbackLocale = null): ?string
    {
        foreach ([$locale, $fallbackLocale] as $candidate) {
            if ($candidate === null) {
                continue;
            }
            $path = self::path($dir, $candidate, $key);
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private static function segment(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
    }
}
