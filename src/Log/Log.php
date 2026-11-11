<?php

declare(strict_types=1);

namespace PHPolygon\Log;

/**
 * The game log: one file, one line per thing that happened.
 *
 *     Log::game('terminal opened', ['id' => $terminalId]);
 *     Log::ui('click', [$id, $label]);
 *     Log::error('save failed', ['slot' => 2, 'reason' => $e->getMessage()]);
 *
 *     2026-11-09 14:03:12.481 f=18233 [game] terminal opened id=t_04
 *
 * {@see LogRecord} describes the line. The engine writes the categories
 * engine, system, event, input, ui, scene, settings, window, save and error
 * by itself; `game` belongs to the game.
 *
 * How a line reaches the file:
 * - Outside a running game loop every line is appended at once - during
 *   start-up, in tools and tests nothing waits in memory.
 * - Inside the loop ({@see start()} was called and {@see frame()} ticks) the
 *   lines of a frame are collected and handed to the writer as ONE string
 *   at the frame boundary: {@see ThreadedLogWriter} where ext-parallel is
 *   loaded, {@see FrameBatchedLogWriter} otherwise. Lines that waited longer
 *   than {@see MAX_PENDING_SECONDS} (a frame that takes seconds) go out with
 *   the next line.
 * - A log thread that dies is replaced by the main-thread writer, with what
 *   it left unwritten. The last {@see RING} lines stay in memory
 *   ({@see recent()}); a shutdown function writes whatever is still open and
 *   the fatal error that ended the process.
 *
 * Nothing here throws. The automatic hooks of the engine check {@see $hooks}
 * first, which is off until an engine runs - so worlds, dispatchers and UI
 * contexts used in tools and tests write nothing.
 */
final class Log
{
    /** Lines kept in memory for {@see recent()} and the crash path. */
    public const int RING = 500;

    /** Longest a collected line waits for its frame to end. */
    public const float MAX_PENDING_SECONDS = 0.25;

    /**
     * Whether the engine's automatic hooks write (systems, events, input, UI,
     * scenes, settings, window, saves). Hot paths read it directly.
     */
    public static bool $hooks = false;

    /** Trace mode: {@see LogLevel::Trace} lines are written, among them one line per frame with every system's time. */
    public static bool $trace = false;

    /** Number of the frame being collected; counted by {@see frame()}. */
    public static int $frame = 0;

    /** A system whose update or render takes longer than this is reported as slow. */
    public static int $slowSystemNs = 4_000_000;

    private static ?string $path = null;
    private static bool $pathFixed = false;
    private static int $maxBytes = LogFile::DEFAULT_MAX_BYTES;
    private static int $keep = LogFile::DEFAULT_KEEP;

    private static ?LogWriterInterface $writer = null;
    private static bool $started = false;
    private static bool $batching = false;
    private static string $pending = '';
    private static float $pendingSince = 0.0;

    /** @var array<int, string> */
    private static array $ring = [];
    private static int $ringNext = 0;

    private static bool $shutdownRegistered = false;
    private static bool $errorHandlerInstalled = false;
    /** @var callable|null */
    private static $previousErrorHandler = null;
    /** @var array<string, int> PHP warnings seen, by place and message */
    private static array $phpErrorCounts = [];

    private static ?GamepadEdgeLog $gamepads = null;

    // ── Writing ──────────────────────────────────────────────────

    /** @param array<array-key, mixed> $context */
    public static function engine(string $text, array $context = [], LogLevel $level = LogLevel::Info): void
    {
        self::write(LogCategory::Engine, $text, $context, $level);
    }

    /** @param array<array-key, mixed> $context */
    public static function system(string $text, array $context = [], LogLevel $level = LogLevel::Info): void
    {
        self::write(LogCategory::System, $text, $context, $level);
    }

    /** @param array<array-key, mixed> $context */
    public static function event(string $text, array $context = [], LogLevel $level = LogLevel::Info): void
    {
        self::write(LogCategory::Event, $text, $context, $level);
    }

    /** @param array<array-key, mixed> $context */
    public static function input(string $text, array $context = [], LogLevel $level = LogLevel::Info): void
    {
        self::write(LogCategory::Input, $text, $context, $level);
    }

    /** @param array<array-key, mixed> $context */
    public static function ui(string $text, array $context = [], LogLevel $level = LogLevel::Info): void
    {
        self::write(LogCategory::Ui, $text, $context, $level);
    }

    /** @param array<array-key, mixed> $context */
    public static function scene(string $text, array $context = [], LogLevel $level = LogLevel::Info): void
    {
        self::write(LogCategory::Scene, $text, $context, $level);
    }

    /** @param array<array-key, mixed> $context */
    public static function settings(string $text, array $context = [], LogLevel $level = LogLevel::Info): void
    {
        self::write(LogCategory::Settings, $text, $context, $level);
    }

    /** @param array<array-key, mixed> $context */
    public static function window(string $text, array $context = [], LogLevel $level = LogLevel::Info): void
    {
        self::write(LogCategory::Window, $text, $context, $level);
    }

    /** @param array<array-key, mixed> $context */
    public static function save(string $text, array $context = [], LogLevel $level = LogLevel::Info): void
    {
        self::write(LogCategory::Save, $text, $context, $level);
    }

    /**
     * A line of the game's own: what the engine cannot see by itself.
     *
     * @param array<array-key, mixed> $context
     */
    public static function game(string $text, array $context = [], LogLevel $level = LogLevel::Info): void
    {
        self::write(LogCategory::Game, $text, $context, $level);
    }

    /** @param array<array-key, mixed> $context */
    public static function error(string $text, array $context = []): void
    {
        self::write(LogCategory::Error, $text, $context, LogLevel::Error);
    }

    /** @param array<array-key, mixed> $context */
    public static function warning(string $text, array $context = []): void
    {
        self::write(LogCategory::Error, $text, $context, LogLevel::Warn);
    }

    /** @param array<array-key, mixed> $context */
    public static function write(LogCategory $category, string $text, array $context = [], LogLevel $level = LogLevel::Info): void
    {
        if ($level === LogLevel::Trace && !self::$trace) {
            return;
        }
        try {
            $time = microtime(true);
            self::line(LogRecord::stamp($time) . ' f=' . self::$frame . LogRecord::body($category, $level, $text, $context) . "\n", $time);
        } catch (\Throwable) {
            // A line that cannot be built is not worth a crash.
        }
    }

    /** Write a record built elsewhere, with its own time and frame. */
    public static function record(LogRecord $record): void
    {
        if ($record->level === LogLevel::Trace && !self::$trace) {
            return;
        }
        try {
            self::line($record->format() . "\n", microtime(true));
        } catch (\Throwable) {
        }
    }

    // ── Frames ───────────────────────────────────────────────────

    /**
     * Frame boundary, called by the game loop at the start of every frame:
     * hands the lines of the frame that just ended to the writer and counts
     * on. From the first call after {@see start()} lines are collected per
     * frame.
     */
    public static function frame(): void
    {
        if (self::$pending !== '') {
            self::handOver();
        }
        self::$frame++;
        if (self::$started) {
            self::$batching = true;
            self::$gamepads?->poll();
        }
    }

    /** Hand over what was collected and return once all of it is in the file. */
    public static function flush(): void
    {
        if (self::$pending !== '') {
            self::handOver();
        }
        self::$writer?->flush();
    }

    /**
     * The newest lines, oldest first, without line breaks - for a crash
     * dialog or a report, whatever state the file is in.
     *
     * @return list<string>
     */
    public static function recent(int $max = self::RING): array
    {
        $lines = [];
        $count = count(self::$ring);
        for ($i = 0; $i < $count; $i++) {
            $lines[] = rtrim(self::$ring[($count < self::RING ? $i : (self::$ringNext + $i)) % self::RING], "\n");
        }

        return $max >= $count ? $lines : array_slice($lines, -max(0, $max));
    }

    // ── Lifecycle ────────────────────────────────────────────────

    /**
     * Start logging for a running game: the writer (its own thread when
     * $thread is set and ext-parallel is loaded), the automatic hooks, the
     * shutdown function. The engine calls this from run(); a second call
     * while started changes nothing.
     *
     * @param ?bool $trace null = {@see traceRequested()}
     */
    public static function start(bool $thread = true, ?bool $trace = null, bool $hooks = true): void
    {
        if (self::$started) {
            return;
        }
        $path = self::path();
        $threaded = $thread ? ThreadedLogWriter::start($path, self::$maxBytes, self::$keep) : null;
        self::$writer = $threaded ?? new FrameBatchedLogWriter($path, self::$maxBytes, self::$keep);
        self::$started = true;
        self::$batching = false;
        self::$hooks = $hooks;
        self::$trace = $trace ?? self::traceRequested();

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(self::onProcessEnd(...));
        }

        if ($threaded !== null) {
            self::engine('log thread started', ['start_ms' => round($threaded->startMilliseconds(), 2), 'trace' => self::$trace]);
        } else {
            self::engine('log writer on the main thread', ['parallel' => \PHPolygon\Thread\ParallelCapability::isAvailable(), 'trace' => self::$trace]);
        }
    }

    /**
     * Start with a given writer instead of the usual thread/fallback choice -
     * so a test can capture emitted lines without touching disk or a thread.
     * Otherwise identical to {@see start()} (no-op while already started, the
     * same hooks/trace defaults, the same shutdown function).
     *
     * @internal
     */
    public static function startWithWriter(LogWriterInterface $writer, bool $hooks = true, ?bool $trace = null): void
    {
        if (self::$started) {
            return;
        }
        self::$writer = $writer;
        self::$started = true;
        self::$batching = false;
        self::$hooks = $hooks;
        self::$trace = $trace ?? self::traceRequested();

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(self::onProcessEnd(...));
        }
    }

    public static function isStarted(): bool
    {
        return self::$started;
    }

    /** Whether lines currently go through the log thread. */
    public static function isThreaded(): bool
    {
        return self::$writer instanceof ThreadedLogWriter && self::$writer->isAlive();
    }

    /**
     * End of a running game: write what is open, stop the thread, switch the
     * hooks off. Lines written afterwards are appended at once again.
     */
    public static function shutdown(): void
    {
        if (!self::$started) {
            return;
        }
        $pending = self::$pending;
        self::$pending = '';
        self::$batching = false;
        self::$started = false;
        self::$hooks = false;
        self::$trace = false;
        self::$gamepads = null;

        $writer = self::$writer;
        self::$writer = null;
        if ($writer === null) {
            LogFile::appendOnce(self::path(), $pending, self::$maxBytes, self::$keep);

            return;
        }
        // The thread first, so its lines are in the file before the open ones
        // are written here - synchronously, whatever state the thread is in.
        $writer->close();
        if ($writer instanceof ThreadedLogWriter && $writer->failure() !== '') {
            $pending = self::threadNote($writer) . $writer->unwritten() . $pending;
        }
        LogFile::appendOnce(self::path(), $pending, self::$maxBytes, self::$keep);
    }

    // ── Configuration ────────────────────────────────────────────

    /** The log file: the override of {@see setPath()}, else the place resolved when the first line was written. */
    public static function path(): string
    {
        return self::$path ??= self::resolvePath();
    }

    /**
     * Where the log file belongs right now: next to a built game's
     * executable (or `~/Library/Logs/<name>` for a macOS .app, both set by
     * the build stub), else the project root.
     */
    public static function resolvePath(): string
    {
        if (self::$pathFixed && self::$path !== null) {
            return self::$path;
        }
        if (defined('PHPOLYGON_PATH_LOGS')) {
            /** @var string $logs */
            $logs = PHPOLYGON_PATH_LOGS;

            return $logs . DIRECTORY_SEPARATOR . 'game.log';
        }
        if (defined('PHPOLYGON_PATH_ROOT')) {
            /** @var string $root */
            $root = PHPOLYGON_PATH_ROOT;

            return $root . DIRECTORY_SEPARATOR . 'game.log';
        }

        // Development: project root relative to src/Log/
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'game.log';
    }

    /**
     * Write somewhere else (null = the default place again). Takes effect
     * for the writer of the next {@see start()}; outside a started log at once.
     */
    public static function setPath(?string $path): void
    {
        self::$path = $path;
        self::$pathFixed = $path !== null;
    }

    /** Size at which the file is rotated, and how many files exist at most (see {@see LogFile}). */
    public static function setLimits(int $maxBytes, int $keep = LogFile::DEFAULT_KEEP): void
    {
        self::$maxBytes = max(1, $maxBytes);
        self::$keep = max(1, $keep);
    }

    public static function setTrace(bool $on): void
    {
        self::$trace = $on;
    }

    public static function setHooksEnabled(bool $on): void
    {
        self::$hooks = $on;
    }

    /** Trace mode asked for from outside: `--trace` on the command line or `PHPOLYGON_LOG_TRACE=1`. */
    public static function traceRequested(): bool
    {
        $env = getenv('PHPOLYGON_LOG_TRACE');
        if (is_string($env) && $env !== '' && $env !== '0' && strtolower($env) !== 'false') {
            return true;
        }
        $argv = $_SERVER['argv'] ?? [];

        return is_array($argv) && in_array('--trace', $argv, true);
    }

    /** Poll gamepads once per frame and log their button edges (null = stop). */
    public static function watchGamepads(?GamepadEdgeLog $gamepads): void
    {
        self::$gamepads = $gamepads;
    }

    // ── PHP errors ───────────────────────────────────────────────

    /**
     * Log PHP warnings, notices and deprecations as `[error]` lines. The
     * handler that was installed before still runs afterwards and decides
     * what PHP does next. A message silenced with `@` is not logged. The
     * same message from the same place is written three times, then at its
     * 10th, 100th, ... occurrence with the count.
     */
    public static function installErrorHandler(): void
    {
        if (self::$errorHandlerInstalled) {
            return;
        }
        self::$errorHandlerInstalled = true;
        self::$previousErrorHandler = set_error_handler(self::handlePhpError(...));
    }

    /** True while {@see installErrorHandler()} is in effect - the build stub's own handler then leaves the line to this one. */
    public static function handlesPhpErrors(): bool
    {
        return self::$errorHandlerInstalled;
    }

    /** @internal the error handler */
    public static function handlePhpError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) !== 0) {
            $key = $file . ':' . $line . ':' . $message;
            $count = self::$phpErrorCounts[$key] = (self::$phpErrorCounts[$key] ?? 0) + 1;
            if (count(self::$phpErrorCounts) > 2000) {
                self::$phpErrorCounts = [$key => $count];
            }
            if ($count <= 3 || self::isPowerOfTen($count)) {
                $type = match ($severity) {
                    E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
                    E_NOTICE, E_USER_NOTICE => 'notice',
                    E_DEPRECATED, E_USER_DEPRECATED => 'deprecated',
                    default => 'error',
                };
                self::write(
                    LogCategory::Error,
                    "php {$type}: {$message} in {$file}:{$line}",
                    $count > 3 ? ['count' => $count] : [],
                    $type === 'error' ? LogLevel::Error : LogLevel::Warn,
                );
            }
        }

        $previous = self::$previousErrorHandler;
        // A handler that turns errors into exceptions keeps doing so.
        return $previous !== null ? (bool) $previous($severity, $message, $file, $line) : false;
    }

    /** @internal the shutdown function */
    public static function onProcessEnd(): void
    {
        try {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
                // The crash guard may have written it already.
                $first = strtok($error['message'], "\n");
                $known = false;
                foreach (self::$ring as $line) {
                    if ($first !== false && str_contains($line, $first)) {
                        $known = true;
                        break;
                    }
                }
                if (!$known) {
                    self::error("php fatal: {$error['message']} in {$error['file']}:{$error['line']}");
                }
            }
            if (self::$started) {
                self::engine('process ended without engine shutdown; open lines written');
            }
            self::shutdown();
        } catch (\Throwable) {
            // Nowhere left to say it.
        }
    }

    /**
     * Back to the state of a fresh process (tests).
     *
     * @internal
     */
    public static function reset(): void
    {
        self::shutdown();
        if (self::$errorHandlerInstalled) {
            restore_error_handler();
            self::$errorHandlerInstalled = false;
            self::$previousErrorHandler = null;
        }
        self::$phpErrorCounts = [];
        self::$ring = [];
        self::$ringNext = 0;
        self::$frame = 0;
        self::$hooks = false;
        self::$trace = false;
        self::$slowSystemNs = 4_000_000;
        self::$path = null;
        self::$pathFixed = false;
        self::$maxBytes = LogFile::DEFAULT_MAX_BYTES;
        self::$keep = LogFile::DEFAULT_KEEP;
        self::$gamepads = null;
    }

    /**
     * The writer in use, for tests of the thread's failure paths.
     *
     * @internal
     */
    public static function writer(): ?LogWriterInterface
    {
        return self::$writer;
    }

    // ── Internals ────────────────────────────────────────────────

    private static function line(string $line, float $time): void
    {
        self::$ring[self::$ringNext] = $line;
        self::$ringNext = (self::$ringNext + 1) % self::RING;

        if (!self::$batching) {
            self::emit($line);

            return;
        }
        if (self::$pending === '') {
            self::$pendingSince = $time;
            self::$pending = $line;

            return;
        }
        self::$pending .= $line;
        if ($time - self::$pendingSince > self::MAX_PENDING_SECONDS) {
            self::handOver();
        }
    }

    private static function handOver(): void
    {
        $text = self::$pending;
        self::$pending = '';
        self::emit($text);
    }

    private static function emit(string $text): void
    {
        $writer = self::$writer;
        if ($writer === null) {
            LogFile::appendOnce(self::path(), $text, self::$maxBytes, self::$keep);

            return;
        }
        if ($writer->write($text)) {
            return;
        }

        // The writer is gone. Continue on this thread and bring along what
        // the old one did not get to.
        $lost = '';
        if ($writer instanceof ThreadedLogWriter) {
            $lost = self::threadNote($writer) . $writer->unwritten();
        }
        $writer->close();
        self::$writer = self::$started ? new FrameBatchedLogWriter(self::path(), self::$maxBytes, self::$keep) : null;
        if (self::$writer === null || !self::$writer->write($lost . $text)) {
            LogFile::appendOnce(self::path(), $lost . $text, self::$maxBytes, self::$keep);
        }
    }

    private static function threadNote(ThreadedLogWriter $writer): string
    {
        return LogRecord::stamp(microtime(true)) . ' f=' . self::$frame
            . LogRecord::body(LogCategory::Error, LogLevel::Error, 'log thread stopped, writing on the main thread', ['reason' => $writer->failure()])
            . "\n";
    }

    private static function isPowerOfTen(int $n): bool
    {
        while ($n >= 10 && $n % 10 === 0) {
            $n = intdiv($n, 10);
        }

        return $n === 1;
    }
}
