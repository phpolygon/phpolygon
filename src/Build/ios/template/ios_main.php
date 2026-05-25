<?php
/*
 * iOS / iPadOS entry point (PHPolygon build template).
 *
 * The app bundle is read-only, so before booting the game we redirect all
 * writable paths (cache, store, saves) into the app's Documents directory,
 * which PHPRuntime passes in via the PHPOLYGON_IOS_DOCS environment variable.
 *
 * Constants set here take precedence over the game's bootstrap because PHP
 * define() is first-wins; games should guard their PHPOLYGON_PATH_* defines.
 *
 * The staged game tree (entry script, src/, vendor/, resources/, ...) sits
 * next to this file in the bundle's App/ directory, so __DIR__ resolves there.
 *
 * {{ENTRY_SCRIPT}} is replaced by IosAppBuilder with build.json's "entry".
 */

/* Mark the platform so game code can hide desktop-only affordances (e.g. a
 * quit button - Apple HIG forbids app-initiated termination). PHP_OS_FAMILY
 * is "Darwin" on iOS just like macOS, so an explicit flag is the only
 * reliable signal. */
define('PHPOLYGON_PLATFORM_IOS', true);

$docs = getenv('PHPOLYGON_IOS_DOCS');
if ($docs === false || $docs === '') {
    // Fallback: app tmp (always writable) if the host did not set it.
    $docs = sys_get_temp_dir();
}

/* Writable data root for cache/store (consumed by the game's bootstrap). */
putenv('PHPOLYGON_PATH_DATA=' . $docs);

/* Resources live inside the (read-only) bundle. */
define('PHPOLYGON_PATH_RESOURCES', __DIR__ . '/resources');

/* Engine::log() writes game.log to PHPOLYGON_PATH_ROOT. The default (next to
 * the binary) is the read-only bundle on iOS, so writes silently fail and no
 * log is ever produced. Point it at the writable Documents dir so game.log is
 * available (and pullable via devicectl for diagnostics). */
define('PHPOLYGON_PATH_ROOT', $docs);

/* Saves go to Documents so Finder file sharing + iCloud backup see them. */
define('PHPOLYGON_PATH_SAVES', $docs . '/saves');

foreach ([$docs . '/saves', $docs . '/cache', $docs . '/store'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

/* Hand off to the game's configured entry script. */
require __DIR__ . '/{{ENTRY_SCRIPT}}';
