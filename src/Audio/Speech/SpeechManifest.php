<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * `speech.lock.json` next to the generated files: the cache key each file was
 * generated with. It lives with the files, not in Git – a machine without the
 * files has nothing to be current about.
 */
final class SpeechManifest
{
    private const int FORMAT = 1;

    /** @var array<string, array<string, string>> locale => key => cache key */
    private array $lines = [];

    private function __construct(private readonly string $path) {}

    public static function load(string $path): self
    {
        $manifest = new self($path);
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($data) && ($data['format'] ?? null) === self::FORMAT && is_array($data['lines'] ?? null)) {
            foreach ($data['lines'] as $locale => $keys) {
                if (!is_array($keys)) {
                    continue;
                }
                foreach ($keys as $key => $cacheKey) {
                    if (is_string($cacheKey)) {
                        $manifest->lines[(string) $locale][(string) $key] = $cacheKey;
                    }
                }
            }
        }
        return $manifest;
    }

    public function get(string $locale, string $key): ?string
    {
        return $this->lines[$locale][$key] ?? null;
    }

    public function set(string $locale, string $key, string $cacheKey): void
    {
        $this->lines[$locale][$key] = $cacheKey;
    }

    public function save(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        ksort($this->lines);
        foreach ($this->lines as &$keys) {
            ksort($keys);
        }
        unset($keys);
        $json = json_encode(['format' => self::FORMAT, 'lines' => $this->lines], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // Write-then-rename: an interrupted run never leaves a truncated manifest.
        $tmp = $this->path . '.tmp';
        if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $this->path)) {
            throw new \RuntimeException("cannot write {$this->path}");
        }
    }
}
