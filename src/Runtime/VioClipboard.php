<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * The system clipboard through php-vio (vio_clipboard_get/set, php-vio
 * >= 2.33). With an older php-vio, or without it, the text stays in the
 * process like {@see MemoryClipboard}, so copy and paste still work inside
 * the game.
 */
final class VioClipboard implements ClipboardInterface
{
    private string $fallback = '';

    public function getText(): string
    {
        if (function_exists('vio_clipboard_get')) {
            return (string) vio_clipboard_get();
        }
        return $this->fallback;
    }

    public function setText(string $text): void
    {
        $this->fallback = $text;
        if (function_exists('vio_clipboard_set')) {
            vio_clipboard_set($text);
        }
    }

    /** Whether the text reaches other applications (php-vio has a clipboard). */
    public static function isSystemClipboard(): bool
    {
        return function_exists('vio_clipboard_get');
    }
}
