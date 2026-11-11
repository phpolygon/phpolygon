<?php

declare(strict_types=1);

namespace PHPolygon\Log;

/**
 * The log file on disk: append, and rotate by size.
 *
 * When the next text would take `game.log` past $maxBytes the file becomes
 * `game.1.log`, the old `game.1.log` becomes `game.2.log` and so on; with
 * $keep files in total the oldest one is dropped. A single text larger than
 * the limit still lands in one piece, in a file of its own.
 *
 * {@see ThreadedLogWriter} carries the same steps inline, because its thread
 * runs without an autoloader; `LogWriterParityTest` holds the two together.
 */
final class LogFile
{
    public const int DEFAULT_MAX_BYTES = 10 * 1024 * 1024;
    public const int DEFAULT_KEEP = 5;

    /** @var resource|null */
    private $handle = null;
    private int $size = 0;

    public function __construct(
        private readonly string $path,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly int $keep = self::DEFAULT_KEEP,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    /** Append with the file kept open between calls. */
    public function append(string $text): bool
    {
        if ($text === '') {
            return true;
        }
        if ($this->handle === null && !$this->open()) {
            return false;
        }
        $handle = $this->handle;
        if ($handle === null) {
            // open() just returned true, so it did set a handle; kept only
            // so the next lines deal with a resource, not resource|null.
            return false;
        }
        if ($this->size > 0 && $this->size + strlen($text) > $this->maxBytes) {
            fclose($handle);
            $this->handle = null;
            self::rotate($this->path, $this->keep);
            if (!$this->open()) {
                return false;
            }
            $handle = $this->handle;
            if ($handle === null) {
                return false;
            }
        }
        $written = @fwrite($handle, $text);
        if ($written === false) {
            return false;
        }
        @fflush($handle);
        $this->size += $written;

        return true;
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            @fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Append with the file opened and closed around the write: slower, but
     * nothing stays open - for the lines outside the game loop, where the
     * directory may be moved or removed between two of them.
     */
    public static function appendOnce(string $path, string $text, int $maxBytes = self::DEFAULT_MAX_BYTES, int $keep = self::DEFAULT_KEEP): bool
    {
        if ($text === '') {
            return true;
        }
        $size = @filesize($path);
        if ($size !== false && $size > 0 && $size + strlen($text) > $maxBytes) {
            clearstatcache(true, $path);
            self::rotate($path, $keep);
        }
        $written = @file_put_contents($path, $text, FILE_APPEND);
        if ($written !== false) {
            return true;
        }
        // A log directory nobody created yet.
        $dir = dirname($path);
        if (!is_dir($dir) && @mkdir($dir, 0755, true)) {
            $retried = @file_put_contents($path, $text, FILE_APPEND);

            return $retried !== false;
        }

        return false;
    }

    /** `game.log` + 2 -> `game.2.log`; a name without extension gets the number appended. */
    public static function rotatedPath(string $path, int $index): string
    {
        $dot = strrpos($path, '.');
        $slash = max((int) strrpos($path, '/'), (int) strrpos($path, '\\'));

        return $dot !== false && $dot > $slash
            ? substr($path, 0, $dot) . '.' . $index . substr($path, $dot)
            : $path . '.' . $index;
    }

    /** Shift the rotated files up by one and move $path to index 1. The file must be closed. */
    public static function rotate(string $path, int $keep): void
    {
        $last = max(1, $keep - 1);
        @unlink(self::rotatedPath($path, $last));
        for ($i = $last - 1; $i >= 1; $i--) {
            $from = self::rotatedPath($path, $i);
            if (is_file($from)) {
                @rename($from, self::rotatedPath($path, $i + 1));
            }
        }
        if ($keep <= 1) {
            @unlink($path);
        } else {
            // A file another process holds open cannot be renamed on Windows;
            // the log then simply grows on until the next attempt.
            @rename($path, self::rotatedPath($path, 1));
        }
        clearstatcache(true, $path);
    }

    private function open(): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $handle = @fopen($this->path, 'ab');
        if ($handle === false) {
            return false;
        }
        $this->handle = $handle;
        $stat = fstat($handle);
        $this->size = $stat !== false ? $stat['size'] : 0;

        return true;
    }
}
