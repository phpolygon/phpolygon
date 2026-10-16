<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * Project-relative path patterns of build.json (`resources.external`,
 * `resources.exclude`).
 *
 * A pattern is matched against a '/'-separated path relative to the project
 * root: `*` and `?` stay inside one segment, `**` spans segments (`**\/` also
 * matches no segment at all), `[...]` is a character class. A pattern that
 * matches a directory also covers everything below it, so `assets/raw` keeps
 * the whole folder out.
 */
final class PathGlob
{
    /** @var list<string> */
    private array $regexes;

    /** @param list<string> $patterns validated with {@see validate()} */
    public function __construct(array $patterns)
    {
        $this->regexes = array_map(self::toRegex(...), $patterns);
    }

    /** Whether $path (project-relative, '/'-separated) or one of its parent directories matches. */
    public function matches(string $path): bool
    {
        if ($this->regexes === []) {
            return false;
        }
        $path = trim(str_replace('\\', '/', $path), '/');
        $prefix = '';
        foreach (explode('/', $path) as $segment) {
            $prefix .= ($prefix === '' ? '' : '/') . $segment;
            foreach ($this->regexes as $regex) {
                if (preg_match($regex, $prefix) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Normalised patterns of a build.json list; throws on anything that is not
     * a project-relative path pattern.
     *
     * @return list<string>
     */
    public static function validate(mixed $patterns, string $field): array
    {
        if (!is_array($patterns) || !array_is_list($patterns)) {
            throw new \InvalidArgumentException("build.json {$field} must be a list of project-relative paths or glob patterns");
        }
        $valid = [];
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                throw new \InvalidArgumentException("build.json {$field}: every entry must be a non-empty string, got " . json_encode($pattern));
            }
            $normalised = rtrim(str_replace('\\', '/', trim($pattern)), '/');
            if (str_starts_with($normalised, './')) {
                $normalised = substr($normalised, 2);
            }
            if ($normalised === '' || str_starts_with($normalised, '/') || preg_match('/^[A-Za-z]:/', $normalised) === 1) {
                throw new \InvalidArgumentException("build.json {$field}: \"{$pattern}\" must be relative to the project root");
            }
            if (in_array('..', explode('/', $normalised), true)) {
                throw new \InvalidArgumentException("build.json {$field}: \"{$pattern}\" must not leave the project root (..)");
            }
            if (@preg_match(self::toRegex($normalised), '') === false) {
                throw new \InvalidArgumentException("build.json {$field}: \"{$pattern}\" is not a valid glob pattern");
            }
            $valid[] = $normalised;
        }
        return array_values(array_unique($valid));
    }

    private static function toRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    $i++;
                    if (($pattern[$i + 1] ?? '') === '/') {
                        $i++;
                        $regex .= '(?:.*/)?';
                    } else {
                        $regex .= '.*';
                    }
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } elseif ($char === '[') {
                $close = strpos($pattern, ']', $i + 1);
                if ($close === false) {
                    $regex .= '\\[';
                } else {
                    $class = substr($pattern, $i + 1, $close - $i - 1);
                    if (str_starts_with($class, '!')) {
                        $class = '^' . substr($class, 1);
                    }
                    $regex .= '[' . str_replace(['\\', '#'], ['\\\\', '\\#'], $class) . ']';
                    $i = $close;
                }
            } else {
                $regex .= preg_quote($char, '#');
            }
        }
        return '#^' . $regex . '$#';
    }
}
