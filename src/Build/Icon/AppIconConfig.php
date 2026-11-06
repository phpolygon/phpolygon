<?php

declare(strict_types=1);

namespace PHPolygon\Build\Icon;

/**
 * build.json `icon` and the per-platform overrides in `platforms.<os>.icon`.
 *
 * ```json
 * "icon": "resources/icon.png"
 * "icon": { "source": "resources/icon.png", "small": "resources/icon-16.png" }
 * "platforms": { "windows": { "icon": "resources/icon.ico" }, "macos": { "icon": "resources/icon.icns" } }
 * ```
 *
 * `source`: one square PNG of at least {@see self::MIN_SOURCE} px (1024 px gives
 * the macOS 512 pt @2x entry); every platform's icon and the window icon are made
 * from it. `small`: an optional square PNG drawn for small sizes; it makes every
 * size up to min(32, its own size). Overrides: a finished `.ico` (Windows), `.icns`
 * (macOS) or `.png` (Linux; macOS takes a `.png` too) replaces what would be made
 * from `source` for that platform. Paths are relative to the project root.
 */
final class AppIconConfig
{
    public const MIN_SOURCE = 256;

    /** Sizes up to this are drawn from `small` when it is set. */
    public const SMALL_MAX = 32;

    public function __construct(
        public readonly ?string $source = null,
        public readonly ?string $small = null,
        public readonly ?string $windows = null,
        public readonly ?string $macos = null,
        public readonly ?string $linux = null,
    ) {}

    /**
     * @param array<string, array<string, mixed>> $platforms build.json `platforms`
     * @throws \InvalidArgumentException on a malformed entry
     */
    public static function fromBuildJson(mixed $icon, array $platforms): self
    {
        $source = null;
        $small = null;
        if (is_string($icon)) {
            $source = self::path($icon, 'icon', ['png']);
        } elseif (is_array($icon)) {
            $unknown = array_diff(array_keys($icon), ['source', 'small']);
            if ($unknown !== []) {
                throw new \InvalidArgumentException('build.json icon: unknown key(s) ' . implode(', ', $unknown) . ' (allowed: source, small)');
            }
            if (!isset($icon['source'])) {
                throw new \InvalidArgumentException('build.json icon needs a "source" PNG');
            }
            $source = self::path($icon['source'], 'icon.source', ['png']);
            if (isset($icon['small'])) {
                $small = self::path($icon['small'], 'icon.small', ['png']);
            }
        } elseif ($icon !== null) {
            throw new \InvalidArgumentException('build.json icon must be a PNG path or {"source": ..., "small": ...}');
        }

        return new self(
            $source,
            $small,
            self::override($platforms, 'windows', ['ico']),
            self::override($platforms, 'macos', ['icns', 'png']),
            self::override($platforms, 'linux', ['png']),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $platforms
     * @param list<string> $extensions
     */
    private static function override(array $platforms, string $platform, array $extensions): ?string
    {
        $value = $platforms[$platform]['icon'] ?? null;
        return $value === null ? null : self::path($value, "platforms.{$platform}.icon", $extensions);
    }

    public function isEmpty(): bool
    {
        return $this->source === null && $this->windows === null && $this->macos === null && $this->linux === null;
    }

    /** Does $platform get an icon (from `source` or its own override)? */
    public function covers(string $platform): bool
    {
        return $this->source !== null || match ($platform) {
            'windows' => $this->windows !== null,
            'macos' => $this->macos !== null,
            'linux' => $this->linux !== null,
            default => false,
        };
    }

    /**
     * @return array<string, ?string>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'small' => $this->small,
            'windows' => $this->windows,
            'macos' => $this->macos,
            'linux' => $this->linux,
        ];
    }

    /**
     * @param list<string> $extensions
     */
    private static function path(mixed $value, string $key, array $extensions): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("build.json {$key} must be a file path");
        }
        $value = str_replace('\\', '/', $value);
        if (preg_match('#(^|/)\.\.(/|$)|^/|^[A-Za-z]:#', $value) === 1) {
            throw new \InvalidArgumentException("build.json {$key}: relative paths without '..' only, got " . json_encode($value));
        }
        $extension = strtolower(pathinfo($value, PATHINFO_EXTENSION));
        if (!in_array($extension, $extensions, true)) {
            throw new \InvalidArgumentException("build.json {$key} must be a ." . implode(' or .', $extensions) . ' file, got ' . json_encode($value));
        }
        return $value;
    }
}
