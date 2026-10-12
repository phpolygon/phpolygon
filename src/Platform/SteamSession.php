<?php

declare(strict_types=1);

namespace PHPolygon\Platform;

/**
 * One Steam API session per process, through php-steamworks.
 *
 * {@see start()} is idempotent and never fails loudly: without the extension,
 * without a running Steam client or without an app id (outside Steam and no
 * steam_appid.txt) it answers false once and remembers that - `SteamAPI_InitFlat`
 * returns at once in those cases, so this cannot stall the start. A started
 * session is shut down when the process ends.
 *
 * Games that use Steam callbacks pump them once per frame with
 * {@see runCallbacks()}; a game that only reads values (language, user name)
 * may still call it each frame so Steam's callback queue does not grow.
 */
final class SteamSession
{
    private static ?bool $running = null;

    private function __construct() {}

    /** Is php-steamworks loaded at all? */
    public static function available(): bool
    {
        return function_exists('steam_init');
    }

    /** Start the session once; true while Steam is up. */
    public static function start(): bool
    {
        if (self::$running !== null) {
            return self::$running;
        }
        self::$running = false;
        if (!self::available()) {
            return false;
        }

        // steam_init() warns with Steam's reason when it fails; a game without
        // Steam is a normal case here, not an error for its handler.
        set_error_handler(static fn (): bool => true);
        try {
            self::$running = steam_init() === true;
        } catch (\Throwable) {
            self::$running = false;
        } finally {
            restore_error_handler();
        }

        if (self::$running) {
            register_shutdown_function(self::shutdown(...));
        }
        return self::$running;
    }

    public static function isRunning(): bool
    {
        return self::$running === true;
    }

    /** Deliver Steam's queued callbacks; a no-op without a session. */
    public static function runCallbacks(): void
    {
        if (self::$running === true) {
            steam_run_callbacks();
        }
    }

    /** End the session; a later {@see start()} may begin a new one. */
    public static function shutdown(): void
    {
        if (self::$running === true) {
            try {
                steam_shutdown();
            } catch (\Throwable) {
            }
        }
        self::$running = null;
    }
}
