<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * Walks, copies and deletes directory trees for the build.
 *
 * Directories are listed with scandir(), never with the SPL directory iterators:
 * those rewind a directory after reading its first entry, and on a Docker Desktop
 * bind mount (WSL2 9p) a directory rewound after a partial read returns only part
 * of its entries - 331 of 765 files in one assets folder. A container build then
 * staged a game without the rest and could not delete its previous output.
 */
final class FileTree
{
    /**
     * Every entry below $dir, keyed by its path relative to $dir with '/' separators.
     * Parents come before their children, or after them with $childFirst. A linked
     * directory (see {@see isLinkedDirectory()}) is listed but not entered.
     *
     * @return \Generator<string, \SplFileInfo>
     * @throws \RuntimeException while iterating, when a directory cannot be listed
     */
    public static function walk(string $dir, bool $childFirst = false): \Generator
    {
        return self::entries($dir, '', $childFirst);
    }

    /**
     * Whether $path is a symlink or a Windows junction to a directory. PHP reports a
     * junction as a plain directory, not as a link: it is recognised by resolving to
     * somewhere other than its own place in its parent.
     */
    public static function isLinkedDirectory(string $path): bool
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            return false;
        }
        if (is_link($path)) {
            return true;
        }
        $parent = realpath(dirname($path));

        return $parent !== false && $real !== rtrim($parent, '\\/') . DIRECTORY_SEPARATOR . basename($path);
    }

    /**
     * Copy the tree $src into $dst, which is created when missing. A linked
     * directory becomes an empty directory.
     *
     * @throws \RuntimeException when an entry cannot be copied
     */
    public static function copy(string $src, string $dst): void
    {
        error_clear_last();
        self::makeDirectory($dst);
        foreach (self::walk($src) as $relative => $item) {
            $target = $dst . '/' . $relative;
            if ($item->isDir()) {
                self::makeDirectory($target);
            } elseif (!@copy($item->getPathname(), $target)) {
                throw new \RuntimeException("Cannot copy {$item->getPathname()} to {$target}" . self::lastError());
            }
        }
    }

    /**
     * Remove $path and everything below it. A file or a link is unlinked; a linked
     * directory's target is never touched. A missing path is left alone.
     *
     * @throws \RuntimeException when an entry cannot be removed
     */
    public static function remove(string $path): void
    {
        error_clear_last();
        clearstatcache();
        if (is_link($path) || is_file($path) || self::isLinkedDirectory($path)) {
            self::unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }

        foreach (self::walk($path, childFirst: true) as $item) {
            self::unlink($item->getPathname());
        }
        if (!@rmdir($path)) {
            throw new \RuntimeException("Cannot remove {$path}" . self::lastError());
        }
    }

    /** @return \Generator<string, \SplFileInfo> */
    private static function entries(string $dir, string $prefix, bool $childFirst): \Generator
    {
        $names = @scandir($dir);
        if ($names === false) {
            throw new \RuntimeException("Cannot list {$dir}" . self::lastError());
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $item = new \SplFileInfo($dir . '/' . $name);
            $key = $prefix . $name;
            if (!$childFirst) {
                yield $key => $item;
            }
            if ($item->isDir() && !self::isLinkedDirectory($item->getPathname())) {
                yield from self::entries($item->getPathname(), $key . '/', $childFirst);
            }
            if ($childFirst) {
                yield $key => $item;
            }
        }
    }

    private static function makeDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}" . self::lastError());
        }
    }

    /**
     * Delete one entry whose children are gone: a file, a link, an empty directory.
     * rmdir() removes a directory, and a linked directory on Windows, without
     * touching the target.
     */
    private static function unlink(string $path): void
    {
        $removed = is_dir($path) && !is_link($path)
            ? @rmdir($path)
            : @unlink($path) || (PHP_OS_FAMILY === 'Windows' && @rmdir($path));
        if (!$removed) {
            throw new \RuntimeException("Cannot remove {$path}" . self::lastError());
        }
    }

    private static function lastError(): string
    {
        $error = error_get_last();

        return $error !== null ? ": {$error['message']}" : '';
    }
}
