<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * The ini section a micro.sfx reads between itself and the appended PHAR:
 * a magic marker, the big-endian length and plain `key=value` lines – the
 * format `spc micro:combine -I` writes. It is how settings that only take
 * effect at startup (OPcache and its JIT, for one) reach the shipped runtime,
 * which ignores php.ini files and `ini_set()` for them.
 */
final class MicroIni
{
    public const string MAGIC = "\xfd\xf6\x69\xe6";

    /**
     * Forced whenever OPcache is on: the embedded script has no file behind
     * it, and stat-ing it for a timestamp check read script text as a pointer
     * (SIGSEGV at startup on macOS). The script never changes, so the check
     * buys nothing on any platform.
     */
    public const array EMBEDDED_SCRIPT_OPCACHE = [
        'opcache.validate_timestamps' => '0',
        'opcache.file_update_protection' => '0',
    ];

    private function __construct() {}

    /**
     * $settings as the runtime gets them: with OPcache on (`opcache.enable_cli`
     * or `opcache.enable`), {@see EMBEDDED_SCRIPT_OPCACHE} overrides whatever
     * build.json says for those keys.
     *
     * @param array<string, string> $settings
     * @return array<string, string>
     */
    public static function settings(array $settings): array
    {
        foreach (['opcache.enable_cli', 'opcache.enable'] as $switch) {
            if (isset($settings[$switch]) && filter_var($settings[$switch], FILTER_VALIDATE_BOOLEAN)) {
                return [...$settings, ...self::EMBEDDED_SCRIPT_OPCACHE];
            }
        }
        return $settings;
    }

    /**
     * The section for $settings ({@see settings()}), or '' when there is
     * nothing to set.
     *
     * @param array<string, string> $settings
     */
    public static function block(array $settings): string
    {
        if ($settings === []) {
            return '';
        }
        $ini = '';
        foreach (self::settings($settings) as $key => $value) {
            if (preg_match('/^[A-Za-z0-9_.\-]+$/', $key) !== 1) {
                throw new \InvalidArgumentException("Invalid ini key: {$key}");
            }
            $ini .= $key . '=' . self::value($value) . "\n";
        }
        return self::MAGIC . pack('N', strlen($ini)) . $ini;
    }

    /** Bare values stay bare; anything else is quoted for the ini parser. */
    private static function value(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.\-\/]*$/', $value) === 1) {
            return $value;
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
