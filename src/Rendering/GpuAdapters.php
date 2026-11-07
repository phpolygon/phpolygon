<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * The GPUs a game may offer in its graphics menu ({@see GraphicsSettings::$gpu}).
 * Reads php-vio's adapter listing without opening a context; software adapters
 * (WARP, llvmpipe) are left out. The choice is stored by name, which stays valid
 * when the order of the adapters changes, and applied when the window opens.
 */
final class GpuAdapters
{
    /**
     * Hardware adapters of a backend - 'auto' merges every backend's list, one
     * entry per name, in php-vio's order (discrete cards first).
     *
     * @return list<array{name: string, type: string, vramBytes: int}>
     */
    public static function available(string $backend = 'auto'): array
    {
        if (!function_exists('vio_adapters')) {
            return [];
        }
        try {
            $all = $backend === 'auto' ? vio_adapters() : vio_adapters($backend);
        } catch (\ValueError) {
            return [];
        }
        if (!is_array($all)) {
            return [];
        }
        $seen = [];
        $out = [];
        foreach ($all as $list) {
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $a) {
                if (!is_array($a) || !is_string($a['name'] ?? null) || $a['name'] === '') {
                    continue;
                }
                $type = is_string($a['device_type'] ?? null) ? $a['device_type'] : 'unknown';
                if ($type === 'software' || isset($seen[$a['name']])) {
                    continue;
                }
                $seen[$a['name']] = true;
                $out[] = ['name' => $a['name'], 'type' => $type, 'vramBytes' => is_int($a['vram_bytes'] ?? null) ? $a['vram_bytes'] : 0];
            }
        }
        return $out;
    }

    /** Whether a stored choice still names an available GPU ('' = automatic is always valid). */
    public static function isAvailable(string $gpu, string $backend = 'auto'): bool
    {
        if ($gpu === '') {
            return true;
        }
        foreach (self::available($backend) as $a) {
            if ($a['name'] === $gpu) {
                return true;
            }
        }
        return false;
    }
}
