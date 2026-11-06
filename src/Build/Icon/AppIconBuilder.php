<?php

declare(strict_types=1);

namespace PHPolygon\Build\Icon;

use PHPolygon\Icon\IconResampler;
use PHPolygon\Icon\Png;
use PHPolygon\Icon\RgbaImage;
use PHPolygon\Icon\WindowIconSet;

/**
 * Makes every platform's application icon from build.json `icon`
 * ({@see AppIconConfig}): the Windows icon resource of the runtime, the macOS
 * .icns, the Linux PNG and the window icon set the engine applies at start-up.
 * The source is decoded and shrunk once per build; all of it is plain PHP.
 */
final class AppIconBuilder
{
    /** File name of a generated .icns in Contents/Resources. */
    public const MAC_ICON_FILE = 'AppIcon.icns';

    /** Edge of the Linux icon PNG (the largest size icon themes and AppImage use regularly). */
    public const LINUX_SIZE = 256;

    private ?RgbaImage $sourceImage = null;
    private ?RgbaImage $smallImage = null;

    /** @var array<int, RgbaImage> */
    private array $sized = [];

    /** @var callable|null */
    private $logger = null;

    public function __construct(
        private readonly AppIconConfig $config,
        private readonly string $projectRoot,
    ) {}

    /**
     * @param callable(string, string): void $logger fn(level, message)
     */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    public function config(): AppIconConfig
    {
        return $this->config;
    }

    /**
     * The Windows icon: the entries of `platforms.windows.icon`, or made from
     * `source` in {@see IcoFile::SIZES}. Null without an icon for Windows.
     *
     * @return list<IcoEntry>|null
     */
    public function windowsEntries(): ?array
    {
        if ($this->config->windows !== null) {
            return IcoFile::parse($this->read($this->config->windows));
        }
        if ($this->config->source === null) {
            return null;
        }
        return IcoFile::entries($this->images(IcoFile::SIZES));
    }

    /**
     * Writes $runtime with the Windows icon to $target ({@see PeIconWriter}).
     * False (and $target untouched) without an icon for Windows.
     */
    public function patchWindowsRuntime(string $runtime, string $target): bool
    {
        $entries = $this->windowsEntries();
        if ($entries === null) {
            return false;
        }
        $bytes = @file_get_contents($runtime);
        if ($bytes === false) {
            throw new \RuntimeException("Cannot read the runtime: {$runtime}");
        }
        if (file_put_contents($target, PeIconWriter::withIcon($bytes, $entries)) === false) {
            throw new \RuntimeException("Cannot write {$target}");
        }
        $this->log('info', sprintf('Windows icon: %d image(s) (%s px)', count($entries), implode(', ', array_map(
            static fn (IcoEntry $e): string => (string) $e->width,
            $entries,
        ))));
        return true;
    }

    /**
     * The macOS icon as [file name in Contents/Resources, bytes], or null.
     * `platforms.macos.icon` wins: an .icns is copied as it is (missing: a
     * warning and `source` is used, as builds before build.json `icon` skipped
     * it), a .png is converted.
     *
     * @return array{string, string}|null
     */
    public function macIcon(): ?array
    {
        $override = $this->config->macos;
        if ($override !== null) {
            $path = $this->projectRoot . '/' . $override;
            if (is_file($path)) {
                if (str_ends_with(strtolower($override), '.icns')) {
                    return [basename($override), $this->read($override)];
                }
                $image = $this->decodeSquare($override, 'platforms.macos.icon');
                $images = IconResampler::sizes($image, IcnsFile::SIZES);
                return [self::MAC_ICON_FILE, IcnsFile::build($images)];
            }
            $this->log('warning', "platforms.macos.icon not found: {$override}" . ($this->config->source !== null ? ' - using icon.source' : ' - the bundle gets no icon'));
        }
        if ($this->config->source === null) {
            return null;
        }
        return [self::MAC_ICON_FILE, IcnsFile::build($this->images(IcnsFile::SIZES))];
    }

    /** The Linux icon PNG ({@see self::LINUX_SIZE} px from `source`, or `platforms.linux.icon` as it is), or null. */
    public function linuxPng(): ?string
    {
        if ($this->config->linux !== null) {
            $bytes = $this->read($this->config->linux);
            Png::size($bytes); // a PNG, or a clear error
            return $bytes;
        }
        if ($this->config->source === null) {
            return null;
        }
        return Png::encode($this->images([self::LINUX_SIZE])[self::LINUX_SIZE]);
    }

    /** The window icon set ({@see WindowIconSet}) from `source`, or null. */
    public function windowIconSet(): ?WindowIconSet
    {
        if ($this->config->source === null) {
            return null;
        }
        $images = $this->images(WindowIconSet::SIZES);
        ksort($images);
        return new WindowIconSet(array_values($images));
    }

    /**
     * Square images of $sizes from `source` (sizes up to {@see AppIconConfig::SMALL_MAX}
     * from `small` when set), cached for the build.
     *
     * @param list<int> $sizes
     * @return array<int, RgbaImage>
     */
    public function images(array $sizes): array
    {
        $missing = array_values(array_filter($sizes, fn (int $s): bool => !isset($this->sized[$s])));
        if ($missing !== []) {
            $small = $this->small();
            $fromSmall = $small === null ? [] : array_values(array_filter(
                $missing,
                static fn (int $s): bool => $s <= min(AppIconConfig::SMALL_MAX, $small->width),
            ));
            if ($small !== null && $fromSmall !== []) {
                $this->sized = IconResampler::sizes($small, $fromSmall) + $this->sized;
            }
            $fromSource = array_values(array_diff($missing, $fromSmall));
            if ($fromSource !== []) {
                $this->sized += IconResampler::sizes($this->source(), $fromSource);
            }
        }
        $out = [];
        foreach ($sizes as $size) {
            if (isset($this->sized[$size])) {
                $out[$size] = $this->sized[$size];
            }
        }
        return $out;
    }

    private function source(): RgbaImage
    {
        if ($this->sourceImage === null) {
            if ($this->config->source === null) {
                throw new \LogicException('build.json icon has no source');
            }
            $image = $this->decodeSquare($this->config->source, 'icon.source');
            if ($image->width < AppIconConfig::MIN_SOURCE) {
                throw new \RuntimeException(sprintf(
                    'build.json icon.source must be at least %d px (1024 recommended), %s is %d px',
                    AppIconConfig::MIN_SOURCE,
                    $this->config->source,
                    $image->width,
                ));
            }
            $this->sourceImage = $image;
        }
        return $this->sourceImage;
    }

    private function small(): ?RgbaImage
    {
        if ($this->config->small === null) {
            return null;
        }
        return $this->smallImage ??= $this->decodeSquare($this->config->small, 'icon.small');
    }

    private function decodeSquare(string $relative, string $key): RgbaImage
    {
        $image = Png::decode($this->read($relative));
        if (!$image->isSquare()) {
            throw new \RuntimeException("build.json {$key} must be square, {$relative} is {$image->width}x{$image->height}");
        }
        return $image;
    }

    private function read(string $relative): string
    {
        $path = $this->projectRoot . '/' . $relative;
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if ($bytes === false) {
            throw new \RuntimeException("Icon file not found: {$relative} (build.json icon / platforms.*.icon)");
        }
        return $bytes;
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message);
        }
    }
}
