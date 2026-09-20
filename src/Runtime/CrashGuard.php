<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

use PHPolygon\Engine;

/**
 * Catches what nothing else did, once per process, and tells the game.
 *
 * Two ways a PHP game dies without anyone noticing: an uncaught throwable
 * anywhere in the loop, and a fatal error PHP only reports on shutdown (out of
 * memory, execution time, a compile error in lazily loaded code). The guard
 * takes both, writes them to the game log, and calls every listener registered
 * with {@see onCrash()} - where a game saves what it can, writes a report, or
 * asks to be restarted.
 *
 * Rules a listener can rely on:
 * - It runs at most once per process, for the first crash only.
 * - Its own exceptions are logged and swallowed; the next listener still runs.
 * - On a fatal error the memory reserve held since install() is released and
 *   the memory limit lifted before it runs, so an out-of-memory death still
 *   leaves room to serialise state or build a request.
 * - The engine may be half torn down. Anything a listener touches may throw.
 *
 * The build stub's own handlers only log; installing the guard replaces the
 * exception handler (the guard logs the same lines) and adds to the shutdown
 * functions.
 */
final class CrashGuard
{
    /** Released on a fatal error, before any listener runs. */
    public const RESERVE_BYTES = 2 * 1024 * 1024;

    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

    private static ?self $instance = null;

    /** @var list<callable(CrashInfo): void> */
    private array $listeners = [];
    private ?string $reserve = null;
    private bool $handled = false;
    private bool $armed = true;

    private function __construct() {}

    /**
     * Install the guard for this process, or return the installed one.
     * Idempotent: the handlers and the reserve are set up the first time only.
     */
    public static function install(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $guard = self::$instance = new self();
        $guard->reserve = str_repeat("\0", self::RESERVE_BYTES);

        set_exception_handler(static function (\Throwable $e): void {
            self::$instance?->handleThrowable($e);
            // A handled exception otherwise ends the process with status 0,
            // and a launcher or script would take the crash for a clean exit.
            exit(255);
        });
        // A shutdown function cannot be removed again; it asks for the current
        // guard at call time so reset() really detaches it.
        register_shutdown_function(static function (): void {
            self::$instance?->handleShutdown();
        });

        return $guard;
    }

    /** The installed guard, or null. */
    public static function current(): ?self
    {
        return self::$instance;
    }

    /** @param callable(CrashInfo): void $listener */
    public function onCrash(callable $listener): self
    {
        $this->listeners[] = $listener;

        return $this;
    }

    /**
     * Ignore whatever happens from now on - for a deliberate hard exit whose
     * leftovers must not be reported as a crash.
     */
    public function disarm(): void
    {
        $this->armed = false;
        $this->reserve = null;
    }

    /** Memory held back for the listeners of a fatal error; 0 once released. */
    public function reservedBytes(): int
    {
        return $this->reserve === null ? 0 : strlen($this->reserve);
    }

    public function handleThrowable(\Throwable $e): void
    {
        $this->dispatch(CrashInfo::fromThrowable($e));
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || !self::isFatal($error['type'])) {
            return;
        }

        $this->dispatch(CrashInfo::fromError($error));
    }

    public static function isFatal(int $errorType): bool
    {
        return in_array($errorType, self::FATAL_TYPES, true);
    }

    /**
     * Forget the installed guard and give the exception handler back (tests).
     * The shutdown function stays registered but finds no guard.
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            restore_exception_handler();
        }
        self::$instance = null;
    }

    private function dispatch(CrashInfo $crash): void
    {
        if ($this->handled || !$this->armed) {
            return;
        }
        $this->handled = true;

        if ($crash->kind === CrashInfo::KIND_FATAL) {
            $this->reserve = null;
            @ini_set('memory_limit', '-1');
        }

        self::log('FATAL: Uncaught ' . $crash->describe());
        if ($crash->trace !== '') {
            self::log("  Stack trace:\n" . $crash->trace);
        }

        foreach ($this->listeners as $listener) {
            try {
                $listener($crash);
            } catch (\Throwable $e) {
                self::log('Crash listener failed: ' . get_class($e) . ': ' . $e->getMessage()
                    . ' in ' . $e->getFile() . ':' . $e->getLine());
            }
        }
        $this->reserve = null;
    }

    private static function log(string $message): void
    {
        try {
            Engine::log($message);
        } catch (\Throwable) {
            // Nowhere left to say it.
        }
    }
}
