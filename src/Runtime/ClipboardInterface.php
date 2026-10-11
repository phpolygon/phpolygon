<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * Plain-text clipboard as UTF-8.
 *
 * {@see VioClipboard} is the system clipboard; {@see MemoryClipboard} keeps
 * the text in the process (headless runs, tests, platforms without one).
 */
interface ClipboardInterface
{
    /** The clipboard text, '' when it is empty or holds no text. */
    public function getText(): string;

    public function setText(string $text): void;
}
