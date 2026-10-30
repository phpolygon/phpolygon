<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Text;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Text\FontChain;

final class FontChainTest extends TestCase
{
    public function testLatinTextOpensOnlyThePrimaryFont(): void
    {
        self::assertSame(['Inter-SemiBold.ttf'], self::files('semibold', 'de', 'Übergang Nord'));
        self::assertSame(['Inter-Regular.ttf'], self::files('regular', 'ru', 'Площадка'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function regions(): iterable
    {
        yield 'simplified' => ['zh-CN', '训练场', 'NotoSansSC-Regular.otf'];
        yield 'traditional' => ['zh-TW', '訓練場', 'NotoSansTC-Regular.otf'];
        yield 'underscore locale' => ['zh_hk', '訓練場', 'NotoSansTC-Regular.otf'];
        yield 'japanese' => ['ja', '訓練場', 'NotoSansJP-Regular.otf'];
        yield 'korean' => ['ko', '훈련장', 'NotoSansKR-Regular.otf'];
        yield 'kana in a latin locale' => ['de', 'カタカナ', 'NotoSansJP-Regular.otf'];
        yield 'hangul in a latin locale' => ['de', '훈련장', 'NotoSansKR-Regular.otf'];
        yield 'han in a latin locale' => ['de', '漢字', 'NotoSansSC-Regular.otf'];
    }

    #[DataProvider('regions')]
    public function testLanguagePicksTheRegionalFace(string $locale, string $text, string $face): void
    {
        self::assertSame(['Inter-Regular.ttf', $face], self::files('regular', $locale, $text));
    }

    public function testScriptsAddTheirFaces(): void
    {
        self::assertSame(['Inter-Regular.ttf', 'NotoSansArabic-Regular.ttf'], self::files('regular', 'ar', 'ساحة'));
        self::assertSame(['Inter-Regular.ttf', 'NotoSansThai-Regular.ttf'], self::files('regular', 'th', 'สนาม'));
    }

    public function testRegisteredFontLeadsAndUnknownIdTakesRegular(): void
    {
        $chain = FontChain::engine()->register('display', '/fonts/Display.ttf');
        self::assertSame(['/fonts/Display.ttf'], $chain->paths('display', 'en', 'Sign'));
        self::assertSame($chain->signature('regular', 'en', 'Sign'), $chain->signature('missing', 'en', 'Sign'));
    }

    public function testSignatureFollowsTheFontFileNotItsName(): void
    {
        $dir = sys_get_temp_dir() . '/phpolygon_font_chain_' . uniqid();
        mkdir("{$dir}/a", 0777, true);
        mkdir("{$dir}/b");
        file_put_contents("{$dir}/a/Display.ttf", 'first');
        file_put_contents("{$dir}/b/Display.ttf", 'other');
        $chain = static fn(string $path): string => FontChain::engine()->register('display', $path)->signature('display', 'en', 'Sign');

        $first = $chain("{$dir}/a/Display.ttf");
        $other = $chain("{$dir}/b/Display.ttf");
        file_put_contents("{$dir}/b/Display.ttf", 'first');
        $sameBytes = $chain("{$dir}/b/Display.ttf");

        array_map('unlink', ["{$dir}/a/Display.ttf", "{$dir}/b/Display.ttf"]);
        rmdir("{$dir}/a");
        rmdir("{$dir}/b");
        rmdir($dir);

        self::assertNotSame($first, $other, 'same name, other font');
        self::assertSame($first, $sameBytes, 'the path must not matter: a prebake is made on another machine');
    }

    /** @return list<string> */
    private static function files(string $fontId, string $locale, string $text): array
    {
        return array_map(basename(...), FontChain::engine()->paths($fontId, $locale, $text));
    }
}
