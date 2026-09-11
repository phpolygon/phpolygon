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

    private function __construct() {}

    /**
     * The section for $settings, or '' when there is nothing to set.
     *
     * @param array<string, string> $settings
     */
    public static function block(array $settings): string
    {
        if ($settings === []) {
            return '';
        }
        $ini = '';
        foreach ($settings as $key => $value) {
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
