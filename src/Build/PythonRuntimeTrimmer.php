<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * Trims an embedded CPython install (python-build-standalone layout, Windows
 * `Lib/` + `DLLs/` or Unix `lib/python3.X/` + `lib-dynload/`) to what running
 * scripts needs, for games that ship Python to execute player code.
 *
 * Removal is grouped. {@see DEFAULT_GROUPS} only drops what a script run never
 * loads: package/venv tooling, the Tk GUI stack and the terminal database, the stdlib's own test suites
 * and test extension modules, C headers / import libraries / man pages, and the
 * optimised `.opt-1/.opt-2.pyc` duplicates (plain `.pyc` stay - a game that
 * starts Python with `-B` cannot rebuild them, every run would recompile).
 * Opt-in groups remove capabilities a sandbox may not need: `network`
 * (OpenSSL, `ssl`, `_hashlib` - `hashlib` falls back to its built-in md5, sha1, sha2,
 * sha3 and blake2), `sqlite`, `docs` (`help()` topics).
 *
 * Patterns are paths relative to the Python root, `/`-separated and matched
 * case-sensitively (a Unix `lib/thread3*` must never hit `Lib/threading.py`):
 * `*` and `?` stay inside one segment, `**` spans any number. `{lib}` stands for the stdlib directory, `{dynload}` for the
 * extension-module directory of the detected layout. A pattern that names a
 * directory removes it whole. `keep` patterns win over every removal.
 */
final class PythonRuntimeTrimmer
{
    /** @var array<string, list<string>> */
    public const GROUPS = [
        'tooling' => [
            '{lib}/site-packages/pip', '{lib}/site-packages/pip-*',
            '{lib}/ensurepip', '{lib}/venv', 'Scripts',
            'bin/pip*', 'bin/idle*', 'bin/pydoc*', 'bin/python*-config', 'bin/2to3*',
        ],
        'gui' => [
            '{lib}/tkinter', '{lib}/idlelib', '{lib}/turtledemo', '{lib}/turtle.py',
            '{dynload}/_tkinter*', '{dynload}/tcl*.dll', '{dynload}/tk*.dll',
            'tcl', 'pythonw.exe',
            'lib/libtcl*', 'lib/libtk*', 'lib/tcl9*', 'lib/tk9*', 'lib/itcl4*', 'lib/thread3*',
            // Terminal capability database of the bundled ncurses (curses/readline
            // UIs). Its entries differ only in case (E/Eterm, e/eterm), which a
            // case-insensitive file system or Steam depot refuses.
            'share/terminfo',
        ],
        'tests' => [
            '{lib}/test', '{lib}/**/test', '{lib}/**/tests', '{lib}/**/idle_test',
            '{dynload}/_test*', '{dynload}/_ctypes_test*', '{dynload}/xxlimited*',
            '{dynload}/_xxtestfuzz*', '{dynload}/xxsubtype*',
        ],
        'devel' => [
            'include', 'libs', 'lib/pkgconfig', 'lib/*.a', '{lib}/config-*', 'share/man',
        ],
        'bytecode-variants' => [
            '**/*.opt-1.pyc', '**/*.opt-2.pyc',
        ],
        'network' => [
            '{dynload}/_ssl*', '{dynload}/libssl*', '{dynload}/_hashlib*', '{dynload}/libcrypto*',
            'lib/libssl*', 'lib/libcrypto*',
        ],
        'sqlite' => [
            '{lib}/sqlite3', '{dynload}/_sqlite3*', '{dynload}/sqlite3*',
        ],
        'docs' => [
            '{lib}/pydoc_data',
        ],
    ];

    /** Groups no script run needs. */
    public const DEFAULT_GROUPS = ['tooling', 'gui', 'tests', 'devel', 'bytecode-variants'];

    private function __construct() {}

    /**
     * Remove the files of $groups plus $remove from the Python install at $root.
     * `default` in $groups expands to {@see DEFAULT_GROUPS}.
     *
     * @param list<string> $groups
     * @param list<string> $remove extra patterns
     * @param list<string> $keep   patterns that are never removed (nor their parents)
     * @return array{removed: list<string>, files: int, bytes: int} removed paths relative to $root
     */
    public static function trim(string $root, array $groups = self::DEFAULT_GROUPS, array $remove = [], array $keep = [], bool $dryRun = false): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (!is_dir($root)) {
            throw new \InvalidArgumentException("not a directory: {$root}");
        }
        [$lib, $dynload] = self::layout($root);

        $patterns = [];
        foreach (self::expandGroups($groups) as $group) {
            array_push($patterns, ...self::GROUPS[$group]);
        }
        array_push($patterns, ...$remove);
        $removeRe = self::compile($patterns, $lib, $dynload)['regex'];
        $keepRule = self::compile($keep, $lib, $dynload);

        $result = ['removed' => [], 'files' => 0, 'bytes' => 0];
        self::walk($root, '', $removeRe, $keepRule, $dryRun, $result);
        return $result;
    }

    /**
     * @param list<string> $groups
     * @return list<string>
     */
    public static function expandGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $names = $group === 'default' ? self::DEFAULT_GROUPS : [$group];
            foreach ($names as $name) {
                if (!isset(self::GROUPS[$name])) {
                    throw new \InvalidArgumentException(sprintf(
                        'unknown Python trim group "%s" (known: default, %s)',
                        $name,
                        implode(', ', array_keys(self::GROUPS)),
                    ));
                }
                $out[$name] = $name;
            }
        }
        return array_values($out);
    }

    /**
     * The stdlib and extension-module directories of the install, relative to
     * $root: `Lib` + `DLLs` (Windows) or `lib/python3.X` + `lib/python3.X/lib-dynload`.
     *
     * @return array{string, string}
     */
    public static function layout(string $root): array
    {
        // Exact names: a case-insensitive file system would answer is_dir('Lib')
        // for a Unix `lib/` as well.
        $top = scandir($root) ?: [];
        if (in_array('Lib', $top, true) && is_file($root . '/Lib/os.py')) {
            return ['Lib', 'DLLs'];
        }
        if (in_array('lib', $top, true)) {
            foreach (scandir($root . '/lib') ?: [] as $name) {
                if (str_starts_with($name, 'python3') && is_file($root . '/lib/' . $name . '/os.py')) {
                    $lib = 'lib/' . $name;
                    return [$lib, $lib . '/lib-dynload'];
                }
            }
        }
        throw new \InvalidArgumentException("no Python standard library under {$root}");
    }

    /**
     * @param array{regex: ?string, prefixes: list<string>} $keep
     * @param array{removed: list<string>, files: int, bytes: int} $result
     * @param bool $inherited the directory itself matched a removal but holds kept paths:
     *                        everything in it goes unless kept
     */
    private static function walk(string $root, string $rel, ?string $removeRe, array $keep, bool $dryRun, array &$result, bool $inherited = false): void
    {
        $dir = $rel === '' ? $root : $root . '/' . $rel;
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $rel === '' ? $name : $rel . '/' . $name;
            $abs = $root . '/' . $path;
            $isDir = is_dir($abs) && !is_link($abs);
            $kept = $keep['regex'] !== null && preg_match($keep['regex'], $path) === 1;
            $holdsKept = !$kept && $isDir && self::keepsBelow($keep['prefixes'], $path);
            $matches = $inherited || ($removeRe !== null && preg_match($removeRe, $path) === 1);

            if ($matches && !$kept && !$holdsKept) {
                [$files, $bytes] = self::measure($abs);
                $result['removed'][] = $path;
                $result['files'] += $files;
                $result['bytes'] += $bytes;
                if (!$dryRun) {
                    $isDir ? FileTree::remove($abs) : @unlink($abs);
                }
                continue;
            }
            if ($isDir && !$kept) {
                self::walk($root, $path, $removeRe, $keep, $dryRun, $result, $matches && $holdsKept);
            }
        }
    }

    /**
     * Whether a keep pattern reaches into $dir (its literal part, up to the
     * first wildcard, lies below it): the directory then is not removed whole,
     * only its other children are filtered.
     *
     * @param list<string> $keepPrefixes
     */
    private static function keepsBelow(array $keepPrefixes, string $dir): bool
    {
        foreach ($keepPrefixes as $prefix) {
            if (str_starts_with($prefix, $dir . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $patterns
     * @return array{regex: ?string, prefixes: list<string>}
     */
    private static function compile(array $patterns, string $lib, string $dynload): array
    {
        $parts = [];
        $prefixes = [];
        foreach ($patterns as $pattern) {
            $pattern = trim(str_replace(['{lib}', '{dynload}', '\\'], [$lib, $dynload, '/'], $pattern), '/');
            if ($pattern === '') {
                continue;
            }
            $prefixes[] = strpbrk($pattern, '*?') === false ? $pattern : substr($pattern, 0, strcspn($pattern, '*?'));
            $regex = '';
            $length = strlen($pattern);
            for ($i = 0; $i < $length; $i++) {
                $c = $pattern[$i];
                if ($c === '*' && ($pattern[$i + 1] ?? '') === '*') {
                    // `**/` = any number of leading segments, a trailing `**` = anything
                    if (($pattern[$i + 2] ?? '') === '/') {
                        $regex .= '(?:.*/)?';
                        $i += 2;
                    } else {
                        $regex .= '.*';
                        $i++;
                    }
                } elseif ($c === '*') {
                    $regex .= '[^/]*';
                } elseif ($c === '?') {
                    $regex .= '[^/]';
                } else {
                    $regex .= preg_quote($c, '#');
                }
            }
            $parts[] = $regex;
        }
        return [
            'regex' => $parts === [] ? null : '#^(?:' . implode('|', $parts) . ')$#',
            'prefixes' => $prefixes,
        ];
    }

    /** @return array{int, int} file count and bytes below (or of) $path */
    private static function measure(string $path): array
    {
        if (!is_dir($path) || is_link($path)) {
            return [1, (int) @filesize($path)];
        }
        $files = 0;
        $bytes = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files++;
                $bytes += (int) $file->getSize();
            }
        }
        return [$files, $bytes];
    }
}
