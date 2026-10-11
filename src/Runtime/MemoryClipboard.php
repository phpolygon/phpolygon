<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/** A clipboard that lives in this object only - for headless runs and tests. */
final class MemoryClipboard implements ClipboardInterface
{
    public function __construct(private string $text = '')
    {
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): void
    {
        $this->text = $text;
    }
}
