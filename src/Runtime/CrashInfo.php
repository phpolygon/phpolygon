<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * One crash, as {@see CrashGuard} hands it to the game.
 *
 * Plain data on purpose: a crash listener runs in a process that is about to
 * end, often with the engine half torn down, and should not have to reach back
 * into anything to learn what happened. The throwable is kept for listeners
 * that want more than the text (its class hierarchy, a domain exception's own
 * fields); a fatal error has none.
 */
final class CrashInfo
{
    public const KIND_EXCEPTION = 'exception';
    public const KIND_FATAL = 'fatal';

    public function __construct(
        /** {@see KIND_EXCEPTION} or {@see KIND_FATAL}. */
        public readonly string $kind,
        /** The throwable's class, or the error constant's name ("E_ERROR"). */
        public readonly string $type,
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
        /** The stack trace with every previous throwable; '' for a fatal error. */
        public readonly string $trace,
        public readonly ?\Throwable $throwable = null,
    ) {}

    public static function fromThrowable(\Throwable $e): self
    {
        $parts = [];
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            $parts[] = ($t === $e ? '' : 'Caused by ' . get_class($t) . ': ' . $t->getMessage()
                . ' in ' . $t->getFile() . ':' . $t->getLine() . "\n")
                . $t->getTraceAsString();
        }

        return new self(
            self::KIND_EXCEPTION,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            implode("\n", $parts),
            $e,
        );
    }

    /**
     * An error as error_get_last() reports it. Only the fatal kinds reach a
     * listener; see {@see CrashGuard::isFatal()}.
     *
     * @param array{type: int, message: string, file: string, line: int} $error
     */
    public static function fromError(array $error): self
    {
        return new self(
            self::KIND_FATAL,
            self::errorName($error['type']),
            $error['message'],
            $error['file'],
            $error['line'],
            '',
        );
    }

    /** "Class: message in file:line" - the line a log wants. */
    public function describe(): string
    {
        return $this->type . ': ' . $this->message . ' in ' . $this->file . ':' . $this->line;
    }

    private static function errorName(int $type): string
    {
        return match ($type) {
            E_ERROR         => 'E_ERROR',
            E_PARSE         => 'E_PARSE',
            E_CORE_ERROR    => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_USER_ERROR    => 'E_USER_ERROR',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            default         => 'E_' . $type,
        };
    }
}
