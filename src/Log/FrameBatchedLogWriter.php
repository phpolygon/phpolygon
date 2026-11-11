<?php

declare(strict_types=1);

namespace PHPolygon\Log;

/**
 * Writes on the calling thread: the writer of a PHP without the parallel
 * extension, of headless runs, and the one {@see Log} falls back to when the
 * log thread is gone. Same flow as {@see ThreadedLogWriter} - one string per
 * frame, appended, rotated by size - only the write itself is paid for by
 * the frame.
 */
final class FrameBatchedLogWriter implements LogWriterInterface
{
    private readonly LogFile $file;

    public function __construct(string $path, int $maxBytes = LogFile::DEFAULT_MAX_BYTES, int $keep = LogFile::DEFAULT_KEEP)
    {
        $this->file = new LogFile($path, $maxBytes, $keep);
    }

    public function write(string $text): bool
    {
        return $this->file->append($text);
    }

    public function flush(): void
    {
        // append() leaves nothing buffered.
    }

    public function close(): void
    {
        $this->file->close();
    }
}
