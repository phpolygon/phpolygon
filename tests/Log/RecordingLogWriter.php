<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Log;

use PHPolygon\Log\LogWriterInterface;

/**
 * Captures what {@see \PHPolygon\Log\Log} hands over, without touching disk
 * or a thread - one entry per call, exactly as it arrived (a frame's
 * collected lines as one string, or a whole line outside the game loop).
 */
final class RecordingLogWriter implements LogWriterInterface
{
    /** @var list<string> */
    public array $writes = [];

    public bool $failNextWrite = false;

    public int $flushes = 0;

    public bool $closed = false;

    public function write(string $text): bool
    {
        if ($this->failNextWrite) {
            $this->failNextWrite = false;

            return false;
        }
        $this->writes[] = $text;

        return true;
    }

    public function flush(): void
    {
        $this->flushes++;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** Every write() joined, in order - as if it had all gone to one file. */
    public function all(): string
    {
        return implode('', $this->writes);
    }
}
