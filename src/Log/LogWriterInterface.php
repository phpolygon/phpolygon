<?php

declare(strict_types=1);

namespace PHPolygon\Log;

/**
 * Takes finished log text - whole lines, each with its line break - and puts
 * it into the log file. {@see Log} hands over one string per frame.
 *
 * No method may throw: a log that cannot be written must never take the game
 * with it.
 */
interface LogWriterInterface
{
    /**
     * @return bool false when this writer can no longer write; the text of
     *              this call was not written then and the caller falls back
     *              to another writer
     */
    public function write(string $text): bool;

    /** Return once everything handed over so far is in the file. */
    public function flush(): void;

    /** Flush, then release the file (and the thread, if there is one). */
    public function close(): void;
}
