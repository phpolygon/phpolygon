<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Codec;

/**
 * Which audio formats the running PHP can decode (play) and encode, and the
 * choices that follow from it: the format a build step writes, and the file a
 * player loads when a clip exists in several formats.
 *
 * Format names are codec names: `wav`, `flac`, `mp3`, `vorbis` (`.ogg`) and
 * `opus` (`.opus`). php-vio reports its own list through `vio_audio_formats()`;
 * a php-vio without that function (or no php-vio, e.g. php-glfw audio) decodes
 * WAV, FLAC and MP3. WAV and FLAC are always encodable – {@see WavWriter} and
 * {@see FlacWriter} are plain PHP – so FLAC is the universal fallback.
 */
final readonly class AudioFormats
{
    public const string WAV = 'wav';
    public const string FLAC = 'flac';
    public const string MP3 = 'mp3';
    public const string VORBIS = 'vorbis';
    public const string OPUS = 'opus';

    /** What every audio backend of the engine decodes. */
    public const array BASELINE_DECODE = [self::WAV, self::FLAC, self::MP3];

    /** What the engine encodes without any extension. */
    public const array BASELINE_ENCODE = [self::WAV, self::FLAC];

    /** File extension per format. */
    private const array EXTENSIONS = [
        self::WAV => 'wav',
        self::FLAC => 'flac',
        self::MP3 => 'mp3',
        self::VORBIS => 'ogg',
        self::OPUS => 'opus',
    ];

    /** Sibling search order of {@see resolve()}: smallest files first. */
    public const array PREFERENCE = [self::OPUS, self::VORBIS, self::MP3, self::FLAC, self::WAV];

    /**
     * @param list<string> $decodable
     * @param list<string> $encodable
     */
    public function __construct(
        public array $decodable = self::BASELINE_DECODE,
        public array $encodable = self::BASELINE_ENCODE,
    ) {}

    /** The formats of this process (php-vio's list when it has one). */
    public static function detect(): self
    {
        if (!function_exists('vio_audio_formats')) {
            return new self();
        }
        /** @var array{decode: list<string>, encode: list<string>} $f */
        $f = vio_audio_formats();
        return new self(
            array_values(array_unique([...self::BASELINE_DECODE, ...$f['decode']])),
            array_values(array_unique([...self::BASELINE_ENCODE, ...$f['encode']])),
        );
    }

    public function canDecode(string $format): bool
    {
        return in_array($format, $this->decodable, true);
    }

    public function canEncode(string $format): bool
    {
        return in_array($format, $this->encodable, true);
    }

    /**
     * The first of $preferred that this process can encode and $player can
     * decode (default: this process), else FLAC.
     *
     * A build step that runs on the shipped runtime passes no $player; one that
     * runs on another PHP passes the runtime's formats.
     *
     * @param list<string> $preferred e.g. ['opus', 'vorbis']
     */
    public function choose(array $preferred, ?self $player = null): string
    {
        $player ??= $this;
        foreach ($preferred as $format) {
            if ($this->canEncode($format) && $player->canDecode($format)) {
                return $format;
            }
        }
        return self::FLAC;
    }

    /**
     * The file to load for $path: among $path and its siblings with the same
     * name and another audio extension, the first existing one whose format
     * is decodable, in {@see PREFERENCE} order (smallest first). Null when
     * none plays; a path with an unknown extension is returned as is.
     *
     * So a build can ship `line.opus` next to a `line.flac` fallback, and a
     * caller that still asks for `line.flac` gets the Opus file where it plays
     * and the FLAC file on a runtime without Opus.
     */
    public function resolve(string $path): ?string
    {
        $own = self::forPath($path);
        $dot = strrpos($path, '.');
        $slash = max((int) strrpos($path, '/'), (int) strrpos($path, '\\'));
        $base = $dot !== false && $dot > $slash ? substr($path, 0, $dot) : $path;

        foreach (self::PREFERENCE as $format) {
            if (!$this->canDecode($format)) {
                continue;
            }
            $candidate = $format === $own ? $path : $base . '.' . self::EXTENSIONS[$format];
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        // An unknown extension is left to the decoder to sniff.
        return $own === null && is_file($path) ? $path : null;
    }

    /** File extension (without dot) for a format. */
    public static function extension(string $format): string
    {
        return self::EXTENSIONS[$format]
            ?? throw new \InvalidArgumentException("unknown audio format '$format'");
    }

    /** Format of a path by its extension (`.ogg`/`.oga` = Vorbis), null if unknown. */
    public static function forPath(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'wav', 'wave' => self::WAV,
            'flac' => self::FLAC,
            'mp3' => self::MP3,
            'ogg', 'oga' => self::VORBIS,
            'opus' => self::OPUS,
            default => null,
        };
    }
}
