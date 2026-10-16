<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * Copies the files a built game carries inside its PHAR (assets/, resources/)
 * to a real directory, once per build.
 *
 * Native loaders (textures, audio, fonts) cannot read phar:// paths, so a built
 * game needs these files on disk. They used to be copied on every start; now a
 * marker file in the target ({@see MARKER}) records the build id they came from,
 * and a start with the same build id copies nothing.
 *
 * Interrupted extraction is safe: the marker is removed first and written last
 * (temp file + rename), and every file lands under a temporary name before it
 * is renamed into place - a half-written file never carries the final name.
 *
 * When the target is not the install directory (a signed macOS bundle, a
 * read-only install), the files that ship loose next to the executable
 * (build.json `resources.external`) are linked into the target, so the game
 * still finds everything below one directory.
 */
final class BundleExtractor
{
    public const MARKER = '.phpolygon-extracted.json';

    /**
     * Bring $target up to date with $source for build $buildId.
     *
     * @param string $source directory to copy from, e.g. phar://game.phar/assets
     * @param string $target real directory to copy to
     * @param ?string $looseRoot the install's own copy of this directory, whose
     *   entries that are not in $source get linked into $target; null when
     *   $target IS the install directory (then nothing is linked and nothing
     *   is ever deleted: the install's loose files live there too)
     * @param ?callable(string): void $log
     * @return bool true when files were copied, false when $target was current
     * @throws \RuntimeException when a file cannot be written
     */
    public static function sync(string $source, string $target, string $buildId, ?string $looseRoot = null, ?callable $log = null): bool
    {
        if (self::isCurrent($target, $buildId)) {
            return false;
        }
        $previous = self::readMarker($target);
        self::makeDirectory($target);
        $marker = $target . '/' . self::MARKER;
        if (is_file($marker) && !@unlink($marker)) {
            throw new \RuntimeException("Cannot remove {$marker}" . self::lastError());
        }

        $files = [];
        if (is_dir($source)) {
            foreach (self::walk($source) as $relative => $isDir) {
                $destination = $target . '/' . $relative;
                if ($isDir) {
                    if (is_link($destination)) {
                        self::removeLink($destination);
                    }
                    self::makeDirectory($destination);
                    continue;
                }
                self::copyFile($source . '/' . $relative, $destination);
                $files[] = $relative;
            }
        }

        $links = [];
        if ($looseRoot !== null && is_dir($looseRoot)) {
            $links = self::linkLoose($source, $looseRoot, $target, '', $log);
        }

        // Only a private target (one with a loose root elsewhere) may lose files:
        // in the install directory a stale name may be a file the store shipped.
        if ($looseRoot !== null && $previous !== null) {
            $keep = array_flip([...$files, ...$links]);
            foreach ([...$previous['files'], ...$previous['links']] as $old) {
                if (!isset($keep[$old]) && !str_contains('/' . $old . '/', '/../')) {
                    $path = $target . '/' . $old;
                    if (is_link($path)) {
                        self::removeLink($path);
                    } elseif (is_file($path)) {
                        @unlink($path);
                    }
                }
            }
        }

        $json = json_encode(['build' => $buildId, 'files' => $files, 'links' => $links], JSON_UNESCAPED_SLASHES);
        $temp = $marker . '.part-' . getmypid();
        if ($json === false || @file_put_contents($temp, $json) === false || !self::replace($temp, $marker)) {
            @unlink($temp);
            throw new \RuntimeException("Cannot write {$marker}" . self::lastError());
        }
        if ($log !== null) {
            $log(sprintf('Extracted %d files, linked %d entries to %s (build %s)', count($files), count($links), $target, $buildId));
        }

        return true;
    }

    /** Whether $target carries the marker of build $buildId. */
    public static function isCurrent(string $target, string $buildId): bool
    {
        return (self::readMarker($target)['build'] ?? null) === $buildId;
    }

    /**
     * Per-user cache directory for this game: ~/Library/Caches/<id> on macOS,
     * %LOCALAPPDATA%\<id> on Windows, $XDG_CACHE_HOME/<id> (or ~/.cache/<id>) elsewhere.
     */
    public static function userCacheDir(string $identifier, string $os = PHP_OS_FAMILY): string
    {
        $home = (string) (getenv('HOME') ?: getenv('USERPROFILE') ?: '');
        $base = match (true) {
            $os === 'Darwin' && $home !== '' => $home . '/Library/Caches',
            $os === 'Windows' && (string) getenv('LOCALAPPDATA') !== '' => (string) getenv('LOCALAPPDATA'),
            (string) getenv('XDG_CACHE_HOME') !== '' => (string) getenv('XDG_CACHE_HOME'),
            $home !== '' => $home . '/.cache',
            default => sys_get_temp_dir(),
        };

        return $base . '/' . $identifier;
    }

    /**
     * @return array{build: string, files: list<string>, links: list<string>}|null
     */
    private static function readMarker(string $target): ?array
    {
        $raw = @file_get_contents($target . '/' . self::MARKER);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_string($data['build'] ?? null)) {
            return null;
        }
        $list = static fn (mixed $value): array => is_array($value) ? array_values(array_filter($value, 'is_string')) : [];

        return ['build' => $data['build'], 'files' => $list($data['files'] ?? null), 'links' => $list($data['links'] ?? null)];
    }

    /**
     * Link every entry of $looseRoot that $source does not have into $target;
     * descend where both have the same directory.
     *
     * @param ?callable(string): void $log
     * @return list<string> the linked relative paths
     */
    private static function linkLoose(string $source, string $looseRoot, string $target, string $prefix, ?callable $log): array
    {
        $names = @scandir($looseRoot . ($prefix === '' ? '' : '/' . $prefix));
        if ($names === false) {
            return [];
        }
        $links = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || $name === self::MARKER) {
                continue;
            }
            $relative = $prefix === '' ? $name : $prefix . '/' . $name;
            $loose = $looseRoot . '/' . $relative;
            $inSource = $source . '/' . $relative;
            if (is_dir($inSource) && is_dir($loose)) {
                array_push($links, ...self::linkLoose($source, $looseRoot, $target, $relative, $log));
                continue;
            }
            if (file_exists($inSource)) {
                continue; // the archive's own copy wins
            }
            $link = $target . '/' . $relative;
            if (is_link($link) && @readlink($link) === $loose) {
                $links[] = $relative;
                continue;
            }
            if (is_link($link)) {
                self::removeLink($link);
            } elseif (file_exists($link)) {
                continue; // a real file or folder of an earlier layout: leave it
            }
            self::makeDirectory(dirname($link));
            if (@symlink($loose, $link)) {
                $links[] = $relative;
            } elseif ($log !== null) {
                $log("WARNING: cannot link {$link} -> {$loose}" . self::lastError());
            }
        }
        return $links;
    }

    /**
     * Every entry below $dir, parents first, keyed by its '/'-separated relative
     * path; true for directories. scandir() works on phar:// and real paths alike.
     *
     * @return \Generator<string, bool>
     */
    private static function walk(string $dir, string $prefix = ''): \Generator
    {
        $names = @scandir($dir);
        if ($names === false) {
            throw new \RuntimeException("Cannot list {$dir}" . self::lastError());
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || $name === self::MARKER) {
                continue;
            }
            $path = $dir . '/' . $name;
            $relative = $prefix . $name;
            $isDir = is_dir($path);
            yield $relative => $isDir;
            if ($isDir) {
                yield from self::walk($path, $relative . '/');
            }
        }
    }

    private static function copyFile(string $from, string $to): void
    {
        self::makeDirectory(dirname($to));
        if (is_link($to)) {
            self::removeLink($to);
        }
        $temp = $to . '.part-' . getmypid();
        error_clear_last();
        if (!@copy($from, $temp) || !self::replace($temp, $to)) {
            @unlink($temp);
            throw new \RuntimeException("Cannot extract {$from} to {$to}" . self::lastError());
        }
    }

    /** rename() that also replaces an existing file on Windows. */
    private static function replace(string $from, string $to): bool
    {
        if (@rename($from, $to)) {
            return true;
        }
        if (PHP_OS_FAMILY === 'Windows' && is_file($to) && @unlink($to)) {
            return @rename($from, $to);
        }
        return false;
    }

    private static function removeLink(string $path): void
    {
        if (!@unlink($path) && !(PHP_OS_FAMILY === 'Windows' && @rmdir($path))) {
            throw new \RuntimeException("Cannot remove {$path}" . self::lastError());
        }
    }

    private static function makeDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}" . self::lastError());
        }
    }

    private static function lastError(): string
    {
        $error = error_get_last();

        return $error !== null ? ": {$error['message']}" : '';
    }
}
