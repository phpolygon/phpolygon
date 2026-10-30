<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Engine;

/**
 * Provider answers on disk, so a slow probe is paid once per device, driver and
 * php-vio version, not once per start. One JSON file with one entry per provider
 * and device key, so the two GPUs of a hybrid laptop keep their own answers; only
 * the newest {@see MAX_ENTRIES} stay, a driver update leaves one entry behind.
 *
 * An entry counts only for the device it was taken on and while the libraries it
 * rests on are unchanged. Anything else - no file, broken file, other format, other
 * device, changed library - reads as "no answer" and the caller probes again.
 * An answer whose library cannot be stamped is not stored: nothing could tell that
 * it went stale. It is an optimisation: write failures are ignored.
 */
final class UpscalerProbeCache
{
    /** Bump when the meaning of an entry changes. */
    public const FORMAT = 1;

    public const MAX_ENTRIES = 8;

    /** @var \Closure(string): void */
    private readonly \Closure $log;

    /** @param (\Closure(string): void)|null $log Default: game.log */
    public function __construct(private readonly string $path, ?\Closure $log = null)
    {
        $this->log = $log ?? Engine::log(...);
    }

    /**
     * Identity of the device a context runs on, from what php-vio lists without
     * asking the provider (vio_adapters: its own context would need
     * vio_backend_info(), 200 ms on D3D12). Null when the adapter cannot be
     * named: then nothing is read or written. The project id belongs to it because
     * NGX can refuse the id, not the device.
     *
     * @param array<array-key, mixed> $adapters vio_adapters() of the backend
     */
    public static function deviceKey(string $backend, string $gpuName, array $adapters, string $vioVersion, string $projectId = ''): ?string
    {
        foreach ($adapters as $adapter) {
            if (!is_array($adapter) || ($adapter['name'] ?? null) !== $gpuName || $gpuName === '') {
                continue;
            }
            $driver = $adapter['driver'] ?? '';
            if (!is_string($driver) || $driver === '') {
                return null;
            }
            $vendor = is_int($adapter['vendor_id'] ?? null) ? $adapter['vendor_id'] : 0;
            $device = is_int($adapter['device_id'] ?? null) ? $adapter['device_id'] : 0;
            $key = "{$backend}|{$vendor}:{$device}|{$gpuName}|{$driver}|vio {$vioVersion}";
            return $projectId !== '' ? "{$key}|{$projectId}" : $key;
        }
        return null;
    }

    public function read(string $id, string $device): ?UpscalerProbeResult
    {
        $entry = $this->entries()["{$id}|{$device}"] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $supported = $entry['supported'] ?? null;
        $reason = $entry['reason'] ?? null;
        $files = $entry['files'] ?? null;
        if (!is_bool($supported) || !is_string($reason) || !is_array($files)) {
            return null;
        }
        foreach ($files as $file => $stamp) {
            if ($stamp === '' || self::stamp((string) $file) !== $stamp) {
                return null;
            }
        }
        return new UpscalerProbeResult($supported, $reason, array_map('strval', array_keys($files)));
    }

    public function write(string $id, string $device, UpscalerProbeResult $result): void
    {
        $files = [];
        foreach ($result->files as $file) {
            // php-vio reports paths in the ANSI code page: outside ASCII that is
            // no UTF-8 and fits no JSON key.
            $stamp = preg_match('//u', $file) === 1 ? self::stamp($file) : '';
            if ($stamp === '') {
                ($this->log)('UpscalerProbeCache: cannot stamp ' . json_encode($file, JSON_INVALID_UTF8_SUBSTITUTE)
                    . ", the {$id} answer is not stored");
                return;
            }
            $files[$file] = $stamp;
        }
        $key = "{$id}|{$device}";
        $entries = $this->entries();
        unset($entries[$key]);
        $entries[$key] = [
            'supported' => $result->supported,
            'reason' => $result->reason,
            'files' => $files,
        ];
        $entries = array_slice($entries, -self::MAX_ENTRIES, null, true);

        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        // The reason is display text and may quote such a path.
        $json = json_encode(
            ['format' => self::FORMAT, 'entries' => $entries],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $temp = $this->path . '.part-' . getmypid();
        if ($json === false || @file_put_contents($temp, $json) === false) {
            return;
        }
        if (!@rename($temp, $this->path)) {
            @unlink($temp);
        }
    }

    /** @return array<array-key, mixed> */
    private function entries(): array
    {
        $json = is_file($this->path) ? @file_get_contents($this->path) : false;
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !is_array($data['entries'] ?? null)) {
            return [];
        }
        return $data['entries'];
    }

    /** Size and modification time; '' for a file that is gone. */
    private static function stamp(string $file): string
    {
        clearstatcache(true, $file);
        $size = @filesize($file);
        $time = @filemtime($file);
        return $size === false || $time === false ? '' : "{$size}:{$time}";
    }
}
