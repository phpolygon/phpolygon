<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Text;

use PHPolygon\Rendering\Text\EmNormalizedFont;
use PHPUnit\Framework\TestCase;

/**
 * The normalised font copy: ascender - descender = units per EM, everything else
 * byte for byte the source, so php-vio's stb_truetype scale (pixel height) equals
 * its HarfBuzz scale (pixels per EM) and glyphs fill their advances.
 */
final class EmNormalizedFontTest extends TestCase
{
    private const string FONTS = __DIR__ . '/../../../resources/fonts';

    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpolygon-em-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    /** @return iterable<string, array{string, float}> */
    public static function bundledFonts(): iterable
    {
        yield 'Arabic' => ['noto-sans-arabic/NotoSansArabic-Regular.ttf', 0.473];
        yield 'Thai' => ['noto-sans-thai/NotoSansThai-Regular.ttf', 0.662];
        yield 'CJK (CFF)' => ['noto-sans-cjk/NotoSansSC-Regular.otf', 0.691];
        yield 'Inter' => ['Inter-Regular.ttf', 0.826];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bundledFonts')]
    public function testThePatchSetsTheHeightToOneEmAndTouchesNothingElse(string $file, float $ratio): void
    {
        $source = (string) file_get_contents(self::FONTS . '/' . $file);
        self::assertEqualsWithDelta($ratio, EmNormalizedFont::ratio($source), 0.001, 'the share php-vio drew the EM at');

        $patched = EmNormalizedFont::patch($source);
        self::assertIsString($patched);
        self::assertSame(strlen($source), strlen($patched));
        self::assertEqualsWithDelta(1.0, EmNormalizedFont::ratio($patched), 1e-9);
        $diff = 0;
        for ($i = 0, $n = strlen($source); $i < $n; $i++) {
            $diff += $source[$i] !== $patched[$i] ? 1 : 0;
        }
        self::assertLessThanOrEqual(4, $diff, 'only the hhea ascender and descender change');
        self::assertNull(EmNormalizedFont::patch($patched), 'a normalised font needs no second patch');
    }

    public function testAnythingButASingleFontIsLeftAlone(): void
    {
        self::assertNull(EmNormalizedFont::patch('not a font'));
        self::assertNull(EmNormalizedFont::patch('ttcf' . str_repeat("\0", 40)));
        self::assertNull(EmNormalizedFont::ratio(''));
    }

    public function testThePathIsWrittenOnceAndReused(): void
    {
        $source = self::FONTS . '/noto-sans-arabic/NotoSansArabic-Regular.ttf';
        $path = EmNormalizedFont::path($source, $this->cacheDir);
        self::assertNotSame($source, $path);
        self::assertFileExists($path);
        self::assertEqualsWithDelta(1.0, EmNormalizedFont::ratio((string) file_get_contents($path)), 1e-9);
        $mtime = filemtime($path);
        self::assertSame($path, EmNormalizedFont::path($source, $this->cacheDir));
        clearstatcache();
        self::assertSame($mtime, filemtime($path), 'the second call reads the cached copy');

        self::assertSame('missing.ttf', EmNormalizedFont::path('missing.ttf', $this->cacheDir));
    }

    public function testAdvancesStayThoseOfTheSource(): void
    {
        if (!function_exists('vio_font_face') || !function_exists('vio_text_bitmap')) {
            self::markTestSkipped('php-vio without vio_text_bitmap');
        }
        $source = self::FONTS . '/noto-sans-arabic/NotoSansArabic-Regular.ttf';
        $copy = EmNormalizedFont::path($source, $this->cacheDir);
        $a = vio_font_face($source);
        $b = vio_font_face($copy);
        self::assertInstanceOf(\VioFontFace::class, $a);
        self::assertInstanceOf(\VioFontFace::class, $b);
        $text = 'ليس تمامًا – حاول مجددًا!';
        $ma = vio_text_bitmap([$a], $text, 32.0, ['measure' => true]);
        $mb = vio_text_bitmap([$b], $text, 32.0, ['measure' => true]);
        self::assertIsArray($ma);
        self::assertIsArray($mb);
        self::assertSame($ma['advance'], $mb['advance']);
        self::assertSame($ma['width'], $mb['width'], 'the per-EM raster draws copy and source alike');
    }
}
