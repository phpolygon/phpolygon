<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * The constants a build type overrides (build.json `buildTypes.<type>.constants`)
 * live in the game's bootstrap files: `bootstrap.php` and every file of
 * `phar.additionalRequires`, which the stub requires in that order.
 *
 * {@see apply()} rewrites the `define()` of each constant wherever one of those
 * files has it, and refuses a constant none of them defines - otherwise a demo
 * build silently ships as the full game. {@see resolve()} answers which value
 * each constant ends up with: the first `define()` in require order wins, as
 * PHP's own `define()` does (a second one fails), with or without an
 * `if (!defined(...))` guard around it.
 */
final class BuildConstants
{
    /** The bootstrap file every game may have, required before the additional ones. */
    public const string BOOTSTRAP = 'bootstrap.php';

    private function __construct() {}

    /**
     * Project-relative bootstrap files in require order: `bootstrap.php` when it
     * exists below $root, then `phar.additionalRequires` that exist.
     *
     * @return list<string>
     */
    public static function files(BuildConfig $config, string $root): array
    {
        $files = [];
        foreach ([self::BOOTSTRAP, ...$config->additionalRequires] as $file) {
            $file = ltrim(str_replace('\\', '/', $file), '/');
            if (!in_array($file, $files, true) && is_file($root . '/' . $file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Rewrite every `define('NAME', ...)` of the given constants in the given
     * files below $root.
     *
     * @param array<string, mixed> $constants name => value
     * @param list<string> $files relative to $root, see {@see files()}
     * @return array<string, list<string>> name => files that were rewritten
     * @throws \RuntimeException when a constant is defined in none of the files
     */
    public static function apply(string $root, array $constants, array $files): array
    {
        $applied = [];
        foreach ($files as $file) {
            $path = $root . '/' . $file;
            $content = (string) file_get_contents($path);
            $changed = false;
            foreach ($constants as $name => $value) {
                $count = 0;
                $content = preg_replace_callback(
                    self::definePattern((string) $name),
                    static fn (array $m): string => $m[1] . var_export($value, true) . $m[3],
                    $content,
                    -1,
                    $count,
                ) ?? $content;
                if ($count > 0) {
                    $applied[(string) $name][] = $file;
                    $changed = true;
                }
            }
            if ($changed) {
                file_put_contents($path, $content);
            }
        }

        $missing = array_diff(array_map('strval', array_keys($constants)), array_keys($applied));
        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                "Build type constant(s) %s are not defined in any bootstrap file (%s). "
                . "Define them with define('NAME', value) in bootstrap.php or a file of phar.additionalRequires.",
                implode(', ', $missing),
                $files === [] ? 'none found' : implode(', ', $files),
            ));
        }

        return $applied;
    }

    /**
     * The value each constant resolves to across the given sources (PHP code, in
     * require order): the first `define()` wins. A constant no source defines, or
     * whose value expression cannot be evaluated on its own (it reads variables or
     * calls into the game), is reported in the second list.
     *
     * Only the `define()` calls are evaluated - nothing else in the files runs.
     *
     * @param list<string> $sources
     * @param list<string> $names
     * @return array{0: array<string, mixed>, 1: list<string>} [values, unresolved]
     */
    public static function resolve(array $sources, array $names): array
    {
        $values = [];
        $unresolved = [];
        foreach ($names as $name) {
            $found = false;
            foreach ($sources as $source) {
                foreach (self::defineExpressions($source, $name) as $expression) {
                    $found = true;
                    try {
                        $values[$name] = self::evaluate($expression);
                    } catch (\Throwable) {
                        $unresolved[] = $name;
                    }
                    break 2;
                }
            }
            if (!$found) {
                $unresolved[] = $name;
            }
        }

        return [$values, $unresolved];
    }

    /** `(define( 'NAME' , )(value)() ;)` - the value up to the call's closing parenthesis. */
    private static function definePattern(string $name): string
    {
        return '/(\bdefine\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*,\s*)(.+?)(\)\s*;)/s';
    }

    /**
     * Value expressions of `define('NAME', <expr>);` in a PHP source, in order.
     *
     * @return list<string>
     */
    private static function defineExpressions(string $source, string $name): array
    {
        preg_match_all(self::definePattern($name), self::code($source), $matches);

        return $matches[2];
    }

    /** The source without comments, so a commented-out define() never counts. */
    private static function code(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $code .= ' ';
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    /**
     * Evaluate a constant expression (literals, arrays, operators, other
     * constants). Variables and calls are refused: out of the game's context they
     * would yield a fallback (`$x ?? 'default'`), not the value the game sees.
     */
    private static function evaluate(string $expression): mixed
    {
        $tokens = token_get_all('<?php ' . $expression);
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                if ($token === '`') {
                    throw new \RuntimeException('not a constant expression');
                }
                continue;
            }
            if (in_array($token[0], [T_VARIABLE, T_EVAL, T_INCLUDE, T_REQUIRE, T_INCLUDE_ONCE, T_REQUIRE_ONCE, T_NEW, T_FN, T_FUNCTION], true)) {
                throw new \RuntimeException('not a constant expression');
            }
            if ($token[0] === T_STRING || $token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED) {
                for ($j = $i + 1; isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);
                if (($tokens[$j] ?? null) === '(') {
                    throw new \RuntimeException('not a constant expression');
                }
            }
        }
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            return (static fn () => eval('return (' . $expression . ');'))();
        } finally {
            restore_error_handler();
        }
    }
}
