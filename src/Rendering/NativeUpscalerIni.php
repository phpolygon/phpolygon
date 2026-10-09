<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\EngineConfig;

/**
 * php-vio ini settings of the native temporal upscalers, from the game's
 * {@see EngineConfig}:
 *
 *   vio.dlss_project_id       the game's NGX project id (DLSS). Must look like a
 *                             random GUID; NGX rejects anything else. Empty:
 *                             php-vio's own id - fine for development, a
 *                             shipping game uses its own.
 *   vio.ffx_path, vio.dlss_path  where the FidelityFX / DLSS runtime libraries
 *                             live when not next to the executable. Empty: next
 *                             to the executable, next to php_vio, then PATH.
 *
 * {@see apply()} runs before the vio context is created (NGX reads the id when
 * it initialises on first use; the libraries are looked up then too).
 */
final class NativeUpscalerIni
{
    private const GUID = '/^\{?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\}?$/i';

    /**
     * @return array<string, string> ini key => value, only what was configured
     * @throws \InvalidArgumentException when the project id is no GUID
     */
    public static function settings(string $dlssProjectId, string $runtimePath): array
    {
        $settings = [];
        $id = trim($dlssProjectId);
        if ($id !== '') {
            if (preg_match(self::GUID, $id, $m) !== 1) {
                throw new \InvalidArgumentException(
                    "DLSS project id '{$dlssProjectId}' is not a GUID (xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)"
                );
            }
            $settings['vio.dlss_project_id'] = strtolower($m[1]);
        }
        if ($runtimePath !== '') {
            $settings['vio.ffx_path'] = $runtimePath;
            $settings['vio.dlss_path'] = $runtimePath;
        }
        return $settings;
    }

    /**
     * Hand the configured values to php-vio. Keys this php-vio build does not
     * know are skipped (older builds have no native upscalers).
     *
     * @return array<string, string> what was set
     */
    public static function apply(EngineConfig $config): array
    {
        $applied = [];
        foreach (self::settings($config->dlssProjectId, $config->upscalerRuntimePath) as $key => $value) {
            if (ini_get($key) === false) {
                continue;
            }
            if (ini_set($key, $value) !== false) {
                $applied[$key] = $value;
            }
        }
        return $applied;
    }
}
