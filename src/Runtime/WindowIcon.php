<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

use PHPolygon\Icon\Png;
use PHPolygon\Icon\WindowIconSet;

/**
 * Gives the game window its icon at start-up (EngineConfig::$windowIcon).
 *
 * - null (default): the set a build made from build.json `icon`
 *   (`<resources>/phpolygon-window-icon.bin`); none when the game was not built
 *   with an icon. Inside a macOS .app nothing is set: the bundle's .icns already
 *   is the Dock icon.
 * - '': no window icon.
 * - a path (relative to the working directory, like the other EngineConfig
 *   paths): a square PNG, shrunk at start-up (meant for development: 1.07 s
 *   for a 1024 px PNG, measured 2026-11-06), or a prepared set file.
 *
 * An icon never stops a game: an unreadable file is logged and skipped.
 */
final class WindowIcon
{
    /**
     * The icon set for $setting, or null for none.
     *
     * @param ?string $resourcesDir PHPOLYGON_PATH_RESOURCES of a built game, else null
     */
    public static function resolve(?string $setting, ?string $resourcesDir, bool $inMacBundle = false): ?WindowIconSet
    {
        if ($setting === '') {
            return null;
        }
        if ($setting === null) {
            if ($inMacBundle || $resourcesDir === null) {
                return null;
            }
            $path = $resourcesDir . DIRECTORY_SEPARATOR . WindowIconSet::FILE;
            if (!is_file($path)) {
                return null;
            }
            return self::readSet($path);
        }
        if (!is_file($setting)) {
            throw new \RuntimeException("Window icon not found: {$setting}");
        }
        if (strtolower(pathinfo($setting, PATHINFO_EXTENSION)) === 'png') {
            return WindowIconSet::fromImage(Png::decodeFile($setting));
        }
        return self::readSet($setting);
    }

    /**
     * Resolve and set the icon of $window. Returns whether the window took it.
     */
    public static function apply(Window $window, ?string $setting): bool
    {
        $start = hrtime(true);
        try {
            $resources = null;
            if (defined('PHPOLYGON_PATH_RESOURCES')) {
                /** @var string $resources */
                $resources = PHPOLYGON_PATH_RESOURCES;
            }
            $icon = self::resolve($setting, $resources, self::inMacBundle());
        } catch (\Throwable $e) {
            \PHPolygon\Engine::log('Window icon skipped: ' . $e->getMessage());
            return false;
        }
        if ($icon === null) {
            return false;
        }
        $applied = $window->setIcon($icon);
        \PHPolygon\Engine::log(sprintf(
            'Window icon: %s (%d sizes, %.1f ms)',
            $applied ? 'set' : 'not supported by this window/platform',
            count($icon->images),
            (hrtime(true) - $start) / 1e6,
        ));
        return $applied;
    }

    /** Is this a built game running from a macOS .app bundle? */
    public static function inMacBundle(): bool
    {
        return PHP_OS_FAMILY === 'Darwin'
            && defined('PHPOLYGON_PATH_ROOT') && is_string(PHPOLYGON_PATH_ROOT)
            && preg_match('#\.app/Contents/Resources/?$#', str_replace('\\', '/', PHPOLYGON_PATH_ROOT)) === 1;
    }

    private static function readSet(string $path): WindowIconSet
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Cannot read the window icon: {$path}");
        }
        return WindowIconSet::decode($bytes);
    }
}
