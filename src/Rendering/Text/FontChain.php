<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Text;

use VioFontFace;

/**
 * The font files a line of text needs: the primary font of the id, then the
 * engine's Noto faces for the scripts the text actually holds.
 *
 * Only what the text needs is opened: a Latin sign never parses a 13 MB CJK
 * face. Han codepoints differ between the regions, so the language picks the
 * face (a sign in Traditional Chinese must not get Simplified glyph forms); a
 * language without its own face falls back on the script of the text.
 *
 * Faces are parsed once per process and work without a GPU, so build scripts
 * and worker threads use the same chain as the game.
 */
final class FontChain
{
    private const string ARABIC = 'noto-sans-arabic/NotoSansArabic-Regular.ttf';
    private const string THAI = 'noto-sans-thai/NotoSansThai-Regular.ttf';
    private const string CJK_SC = 'noto-sans-cjk/NotoSansSC-Regular.otf';
    private const string CJK_TC = 'noto-sans-cjk/NotoSansTC-Regular.otf';
    private const string CJK_JP = 'noto-sans-cjk/NotoSansJP-Regular.otf';
    private const string CJK_KR = 'noto-sans-cjk/NotoSansKR-Regular.otf';

    /** @var array<string, string> font id => path */
    private array $fonts;

    /** @var array<string, VioFontFace|false> parsed faces by path; false = unreadable */
    private array $faces = [];

    /** @var array<string, string> file name and content hash by path */
    private array $fileKeys = [];

    public function __construct(private readonly string $fontDir)
    {
        $this->fonts = [
            'regular' => $fontDir . '/Inter-Regular.ttf',
            'semibold' => $fontDir . '/Inter-SemiBold.ttf',
        ];
    }

    /** The chain over the fonts the engine ships (phar:// inside a build). */
    public static function engine(): self
    {
        return new self(dirname(__DIR__, 3) . '/resources/fonts');
    }

    /** Add or replace a primary font. */
    public function register(string $fontId, string $path): self
    {
        $this->fonts[$fontId] = $path;
        $this->faces = [];
        $this->fileKeys = [];

        return $this;
    }

    /**
     * Files for $text in order of precedence. An unknown font id takes 'regular'.
     *
     * @return list<string>
     */
    public function paths(string $fontId, string $locale, string $text): array
    {
        $paths = [$this->fonts[$fontId] ?? $this->fonts['regular']];
        $cjk = self::cjkFace($locale, $text);
        if ($cjk !== null) {
            $paths[] = $this->fontDir . '/' . $cjk;
        }
        if (preg_match('/\p{Arabic}/u', $text) === 1) {
            $paths[] = $this->fontDir . '/' . self::ARABIC;
        }
        if (preg_match('/\p{Thai}/u', $text) === 1) {
            $paths[] = $this->fontDir . '/' . self::THAI;
        }

        return $paths;
    }

    /**
     * Names the chain for a texture id: by content, so that another font under
     * the same file name gets new textures, and without the directory, so that a
     * prebake made on another machine still matches.
     */
    public function signature(string $fontId, string $locale, string $text): string
    {
        $keys = [];
        foreach ($this->paths($fontId, $locale, $text) as $path) {
            $keys[] = $this->fileKeys[$path] ??= basename($path) . ':' . @hash_file('xxh3', $path);
        }

        return implode(',', $keys);
    }

    /**
     * Parsed faces for $text; a file that cannot be read is left out. Empty when
     * the primary font is missing or php-vio has no vio_font_face().
     *
     * @return list<VioFontFace>
     */
    public function faces(string $fontId, string $locale, string $text): array
    {
        if (!function_exists('vio_font_face')) {
            return [];
        }
        $faces = [];
        foreach ($this->paths($fontId, $locale, $text) as $i => $path) {
            $face = $this->faces[$path] ??= @vio_font_face($path);
            if ($face !== false) {
                $faces[] = $face;
            } elseif ($i === 0) {
                return [];
            }
        }

        return $faces;
    }

    /** The CJK face for $text in $locale, null when the text holds no CJK. */
    private static function cjkFace(string $locale, string $text): ?string
    {
        if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text) !== 1) {
            return null;
        }
        $language = strtolower(strtr($locale, '_', '-'));
        if (str_starts_with($language, 'zh')) {
            return preg_match('/^zh-(tw|hk|mo|hant)/', $language) === 1 ? self::CJK_TC : self::CJK_SC;
        }
        if (str_starts_with($language, 'ja')) {
            return self::CJK_JP;
        }
        if (str_starts_with($language, 'ko')) {
            return self::CJK_KR;
        }
        if (preg_match('/[\p{Hiragana}\p{Katakana}]/u', $text) === 1) {
            return self::CJK_JP;
        }

        return preg_match('/\p{Hangul}/u', $text) === 1 ? self::CJK_KR : self::CJK_SC;
    }
}
