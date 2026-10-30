<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Text;

use PHPolygon\Component\SurfaceText;
use PHPolygon\Locale\LocaleManager;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\TextureManager;

/**
 * Turns a {@see SurfaceText} in one language into a texture and a material.
 *
 * Ids hold the content (text, chain of fonts, colours, sizes, {@see BAKE_VERSION}):
 * equal signs share one texture, a language that says the same thing with the
 * same fonts shares it too, and a pre-baked file can never be stale. Bump
 * BAKE_VERSION when the pixels of the same input change.
 *
 * A texture comes from the store when it has one, else from the rasterizer.
 * Without either (php-vio too old, a backend that takes no raw pixels) the surface
 * stays a plain plate in its background colour, in every language.
 */
final class SurfaceTextBaker
{
    public const int BAKE_VERSION = 1;

    /**
     * Start of the material id of every unlit surface. The vio renderer does not
     * read Material::$shader and the engine ships no unlit proc mode, so a game
     * makes `unlit` real with ProcModeRegistry::map() on this prefix; unmapped,
     * such a surface is lit like any other.
     */
    public const string UNLIT_MATERIAL_PREFIX = 'surface_text_unlit_';

    /** Largest texture edge; a bigger sign reads as well at a lower resolution. */
    private const int MAX_EDGE = 2048;

    /** Block-compressed encoders of a pre-bake need multiples of 4. */
    private const int EDGE_STEP = 4;

    private readonly FontChain $fonts;

    private readonly TextRasterizer $rasterizer;

    /** @var array<string, string> texture id => the path or 'rgba' it came from; textures this baker made */
    private array $loaded = [];

    public function __construct(
        private readonly LocaleManager $locale,
        private readonly TextureManager $textures,
        private readonly ?SurfaceTextStoreInterface $store = null,
        ?FontChain $fonts = null,
        ?TextRasterizer $rasterizer = null,
    ) {
        $this->fonts = $fonts ?? FontChain::engine();
        $this->rasterizer = $rasterizer ?? new TextRasterizer();
    }

    /** The text of $surface in $locale, the fallback language or else the key itself. */
    public function resolveText(SurfaceText $surface, string $locale): string
    {
        if ($surface->textKey === '') {
            return $surface->text;
        }
        return $this->locale->all($locale)[$surface->textKey]
            ?? $this->locale->all($this->locale->getFallbackLocale())[$surface->textKey]
            ?? $surface->textKey;
    }

    /** @return array{0: int, 1: int} texture size */
    public function pixelSize(SurfaceText $surface): array
    {
        $perMetre = self::pixelsPerMetre($surface);

        return [self::edge($surface->width * $perMetre), self::edge($surface->height * $perMetre)];
    }

    public function textureId(SurfaceText $surface, string $locale): string
    {
        $text = $this->resolveText($surface, $locale);
        [$width, $height] = $this->pixelSize($surface);

        return 'surface_text_' . md5(implode("\n", [
            self::BAKE_VERSION,
            $text,
            $this->fonts->signature($surface->fontId, $locale, $text),
            $width,
            $height,
            sprintf('%.5f %.5f', $surface->width, $surface->height),
            self::colorKey($surface->inkColor()),
            self::colorKey($surface->plateColor()),
            sprintf('%.5f %d %.5f %.5f', $surface->padding, $surface->maxLines, $surface->lineHeight, $surface->maxFontSize),
        ]));
    }

    /** How the text sits on the surface in $locale; null without a rasterizer. */
    public function layout(SurfaceText $surface, string $locale): ?TextFit
    {
        $text = $this->resolveText($surface, $locale);
        $faces = $this->faces($surface, $locale, $text);
        if ($faces === null) {
            return null;
        }
        $scale = self::scale($surface);
        $padding = $surface->padding * 2.0 * $scale;
        [$width, $height] = $this->pixelSize($surface);

        return $this->rasterizer->fit(
            $faces,
            $text,
            max(1.0, $width - $padding),
            max(1.0, $height - $padding),
            $surface->lineHeight,
            $surface->maxLines,
            $surface->maxFontSize * $scale,
            $surface->minFontSize * $scale,
        );
    }

    /** Font size of the layout in metres, as the reader sees it; 0 without a rasterizer. */
    public function fontSizeMetres(SurfaceText $surface, string $locale): float
    {
        $layout = $this->layout($surface, $locale);

        return $layout === null ? 0.0 : $layout->fontPx / self::scale($surface);
    }

    /** RGBA8, top row first; null without a rasterizer. */
    public function rasterize(SurfaceText $surface, string $locale): ?string
    {
        $layout = $this->layout($surface, $locale);
        $faces = $this->faces($surface, $locale, $this->resolveText($surface, $locale));
        if ($layout === null || $faces === null) {
            return null;
        }
        [$width, $height] = $this->pixelSize($surface);

        return $this->rasterizer->render($faces, $layout, $width, $height, $surface->inkColor(), $surface->plateColor(), $surface->lineHeight);
    }

    /**
     * Make sure the texture of $surface in $locale is loaded; false when this
     * process cannot have it.
     */
    public function ensureTexture(SurfaceText $surface, string $locale): bool
    {
        $id = $this->textureId($surface, $locale);
        if ($this->textures->has($id)) {
            return true;
        }

        $path = $this->store?->path($id);
        if ($path !== null) {
            try {
                $this->textures->load($id, $path);
                $this->loaded[$id] = $path;
                return true;
            } catch (\RuntimeException) {
                // A format this backend cannot take: rasterize instead.
            }
        }

        $rgba = $this->rasterize($surface, $locale);
        if ($rgba === null) {
            return false;
        }
        [$width, $height] = $this->pixelSize($surface);
        if ($this->textures->loadRgba($id, $width, $height, $rgba) === null) {
            return false;
        }
        $this->loaded[$id] = 'rgba';

        return true;
    }

    /**
     * The material showing $surface in $locale, its texture loaded. Falls back to
     * {@see plainMaterial()} when there can be no texture.
     */
    public function materialFor(SurfaceText $surface, string $locale): string
    {
        if (!$this->ensureTexture($surface, $locale)) {
            return self::plainMaterial($surface);
        }
        $textureId = $this->textureId($surface, $locale);
        $id = self::materialPrefix($surface) . substr($textureId, strlen('surface_text_')) . self::materialSuffix($surface);
        if (!MaterialRegistry::has($id)) {
            MaterialRegistry::register($id, self::baseOf($surface)->withAlbedoTexture($textureId, Color::white()));
        }

        return $id;
    }

    /** The surface without text: its background colour on its base material. */
    public static function plainMaterial(SurfaceText $surface): string
    {
        $id = self::materialPrefix($surface) . 'plain_' . md5(self::colorKey($surface->plateColor()) . self::materialSuffix($surface));
        if (!MaterialRegistry::has($id)) {
            MaterialRegistry::register($id, self::baseOf($surface)->withAlbedoTexture(null, $surface->plateColor()));
        }

        return $id;
    }

    /**
     * Rasterize every surface in every language into $store (build time). Each
     * distinct texture is written once; what the store has is skipped.
     *
     * @param iterable<SurfaceText> $surfaces
     * @param list<string> $locales
     * @return int textures written
     */
    public function prebake(iterable $surfaces, array $locales, SurfaceTextStoreInterface $store): int
    {
        $written = 0;
        foreach ($surfaces as $surface) {
            foreach ($locales as $locale) {
                $id = $this->textureId($surface, $locale);
                if ($store->path($id) !== null) {
                    continue;
                }
                $rgba = $this->rasterize($surface, $locale);
                if ($rgba === null) {
                    continue;
                }
                [$width, $height] = $this->pixelSize($surface);
                $store->store($id, $rgba, $width, $height);
                $written++;
            }
        }

        return $written;
    }

    /**
     * Free every texture of this baker that is not in $keep.
     *
     * @param array<string, true> $keep texture ids still shown
     */
    public function unloadExcept(array $keep): void
    {
        foreach ($this->loaded as $id => $_) {
            if (!isset($keep[$id])) {
                $this->textures->unload($id);
                unset($this->loaded[$id]);
            }
        }
    }

    /** @return list<\VioFontFace>|null null without a rasterizer */
    private function faces(SurfaceText $surface, string $locale, string $text): ?array
    {
        if (!TextRasterizer::available()) {
            return null;
        }
        $faces = $this->fonts->faces($surface->fontId, $locale, $text);

        return $faces === [] ? null : $faces;
    }

    private static function baseOf(SurfaceText $surface): Material
    {
        $base = $surface->baseMaterial !== '' ? MaterialRegistry::get($surface->baseMaterial) : null;
        $base ??= new Material(roughness: 0.75, useEnvironmentMap: false);

        return $surface->unlit ? $base->withShader('unlit') : $base;
    }

    private static function materialPrefix(SurfaceText $surface): string
    {
        return $surface->unlit ? self::UNLIT_MATERIAL_PREFIX : 'surface_text_';
    }

    private static function materialSuffix(SurfaceText $surface): string
    {
        if ($surface->baseMaterial === '' && !$surface->unlit) {
            return '';
        }

        return '_' . substr(md5($surface->baseMaterial . ($surface->unlit ? '|unlit' : '')), 0, 8);
    }

    private static function scale(SurfaceText $surface): float
    {
        return self::edge($surface->width * self::pixelsPerMetre($surface)) / max(0.001, $surface->width);
    }

    /**
     * The asked resolution, lowered for both edges when one would pass MAX_EDGE:
     * clamping one alone would stretch the text on the quad.
     */
    private static function pixelsPerMetre(SurfaceText $surface): float
    {
        return min(
            $surface->pixelsPerMetre,
            self::MAX_EDGE / max(0.001, $surface->width),
            self::MAX_EDGE / max(0.001, $surface->height),
        );
    }

    private static function edge(float $pixels): int
    {
        $edge = (int) (ceil($pixels / self::EDGE_STEP) * self::EDGE_STEP);

        return max(self::EDGE_STEP, min(self::MAX_EDGE, $edge));
    }

    private static function colorKey(Color $color): string
    {
        return sprintf('%.4f,%.4f,%.4f', $color->r, $color->g, $color->b);
    }
}
