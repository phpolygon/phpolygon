<?php

declare(strict_types=1);

namespace PHPolygon\Net;

/**
 * Turns one peer's frames back into messages ({@see Protocol}). Pieces of a
 * large message are collected until the last one arrives; a peer can have one
 * large message in flight at a time, which is all a session ever sends.
 */
final class Reassembler
{
    /** @var array<int, array{id: int, count: int, pieces: array<int, string>, size: int}> peer => message in flight */
    private array $pending = [];

    /**
     * The message this frame completes, or null (a piece of something larger,
     * or bytes that are not a message).
     *
     * @return null|array<string, mixed>
     */
    public function feed(int $peer, string $bytes): ?array
    {
        $frame = Protocol::frame($bytes);
        if ($frame === null) {
            return null;
        }
        if ($frame['kind'] === 'whole') {
            return $frame['message'];
        }

        $inFlight = $this->pending[$peer] ?? null;
        if ($inFlight === null || $inFlight['id'] !== $frame['id'] || $inFlight['count'] !== $frame['count']) {
            // A new message replaces an unfinished one.
            $inFlight = ['id' => $frame['id'], 'count' => $frame['count'], 'pieces' => [], 'size' => 0];
        }
        if (!isset($inFlight['pieces'][$frame['index']])) {
            $inFlight['size'] += strlen($frame['bytes']);
        }
        $inFlight['pieces'][$frame['index']] = $frame['bytes'];
        if ($inFlight['size'] > Protocol::MAX_FRAME * Protocol::MAX_PIECES) {
            unset($this->pending[$peer]);
            return null;
        }

        if (count($inFlight['pieces']) < $inFlight['count']) {
            $this->pending[$peer] = $inFlight;
            return null;
        }
        unset($this->pending[$peer]);
        ksort($inFlight['pieces']);
        return Protocol::inflate(implode('', $inFlight['pieces']));
    }

    public function forget(int $peer): void
    {
        unset($this->pending[$peer]);
    }
}
