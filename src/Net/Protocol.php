<?php

declare(strict_types=1);

namespace PHPolygon\Net;

/**
 * The wire format: a message is a JSON object with a type 't', compressed
 * and cut into frames small enough for Steam (whose limit is 512 KB per
 * message).
 *
 * A frame starts with {@see MAGIC}, the protocol version and its kind: 0 for a whole
 * message, 1 for one piece of a larger one (then a message id, its index and
 * the piece count follow). {@see Reassembler} puts pieces back together.
 *
 * Everything that arrives is someone else's bytes: sizes are capped before
 * anything is inflated or decoded.
 */
final class Protocol
{
    private function __construct() {}

    /** Two bytes every frame starts with, so foreign bytes are turned away at once. */
    public const MAGIC = 'PG';

    public const VERSION = 1;

    /** Frame payload limit, well under Steam's 512 KB. */
    public const MAX_FRAME = 256 * 1024;

    /** A message may inflate to this much and no more. */
    public const MAX_MESSAGE = 32 * 1024 * 1024;

    /** The most pieces one message may come in. */
    public const MAX_PIECES = 128;

    private const WHOLE = 0;
    private const PIECE = 1;

    private static int $nextId = 1;

    /**
     * @param array<string, mixed> $message must carry its type in 't'
     * @return list<string> frames to send, in order
     */
    public static function encode(array $message): array
    {
        $packed = gzcompress(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 6);
        if ($packed === false) {
            throw new \RuntimeException('Message could not be compressed.');
        }
        $head = self::MAGIC . chr(self::VERSION);
        if (strlen($packed) <= self::MAX_FRAME) {
            return [$head . chr(self::WHOLE) . $packed];
        }

        $pieces = str_split($packed, self::MAX_FRAME);
        if (count($pieces) > self::MAX_PIECES) {
            throw new \LengthException('Message too large to send: ' . strlen($packed) . ' bytes compressed.');
        }
        $id = self::$nextId++ & 0xFFFFFFFF;
        $frames = [];
        foreach ($pieces as $index => $piece) {
            $frames[] = $head . chr(self::PIECE) . pack('Nnn', $id, $index, count($pieces)) . $piece;
        }
        return $frames;
    }

    /**
     * The message in one frame, or null when it is not one of ours, damaged or
     * too large.
     *
     * @return null|array{kind: 'whole', message: array<string, mixed>}|array{kind: 'piece', id: int, index: int, count: int, bytes: string}
     */
    public static function frame(string $bytes): ?array
    {
        if (strlen($bytes) < 4 || substr($bytes, 0, 2) !== self::MAGIC || ord($bytes[2]) !== self::VERSION) {
            return null;
        }
        $kind = ord($bytes[3]);
        if ($kind === self::WHOLE) {
            $message = self::inflate(substr($bytes, 4));
            return $message === null ? null : ['kind' => 'whole', 'message' => $message];
        }
        if ($kind === self::PIECE && strlen($bytes) >= 12) {
            /** @var array{id: int, index: int, count: int} $head */
            $head = unpack('Nid/nindex/ncount', substr($bytes, 4, 8));
            if ($head['count'] < 1 || $head['count'] > self::MAX_PIECES || $head['index'] >= $head['count']) {
                return null;
            }
            return ['kind' => 'piece', 'id' => $head['id'], 'index' => $head['index'], 'count' => $head['count'], 'bytes' => substr($bytes, 12)];
        }
        return null;
    }

    /** @return null|array<string, mixed> */
    public static function inflate(string $packed): ?array
    {
        $json = @gzuncompress($packed, self::MAX_MESSAGE);
        if ($json === false) {
            return null;
        }
        try {
            $message = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($message) || !is_string($message['t'] ?? null)) {
            return null;
        }
        /** @var array<string, mixed> $message a JSON object, so its keys are strings */
        return $message;
    }
}
