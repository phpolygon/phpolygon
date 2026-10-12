<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Locale;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPolygon\Locale\LocaleMatcher;

class LocaleMatcherTest extends TestCase
{
    /** A catalogue with regional splits for es / pt / zh, as many shipped games use. */
    private const REGIONAL = [
        'de', 'en', 'ar', 'bg', 'zh-CN', 'zh-TW', 'cs', 'da', 'nl', 'fi', 'fr', 'el', 'hu', 'id', 'it',
        'ja', 'ko', 'ms', 'no', 'pl', 'pt-BR', 'pt-PT', 'ro', 'ru', 'es-ES', 'es-419', 'sv', 'th', 'tr', 'uk', 'vi',
    ];

    /** @return iterable<string, array{string, ?string}> */
    public static function normalizeCases(): iterable
    {
        yield 'posix with encoding' => ['de_DE.UTF-8', 'de-DE'];
        yield 'posix with modifier' => ['de_DE@euro', 'de-DE'];
        yield 'lower-case region' => ['en-us', 'en-US'];
        yield 'script and region' => ['zh_Hans_CN', 'zh-Hans-CN'];
        yield 'script only' => ['ZH-hant', 'zh-Hant'];
        yield 'numeric region' => ['es-419', 'es-419'];
        yield 'bare language' => ['FR', 'fr'];
        yield 'legacy indonesian' => ['in_ID', 'id-ID'];
        yield 'legacy hebrew' => ['iw', 'he'];
        yield 'C locale' => ['C', null];
        yield 'C.UTF-8' => ['C.UTF-8', null];
        yield 'POSIX' => ['POSIX', null];
        yield 'empty' => ['', null];
        yield 'garbage' => ['1252', null];
    }

    #[DataProvider('normalizeCases')]
    public function testNormalize(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, LocaleMatcher::normalize($raw));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function regionalCases(): iterable
    {
        yield 'exact' => ['de', 'de'];
        yield 'region dropped' => ['de-AT', 'de'];
        yield 'case-insensitive exact' => ['ZH-tw', 'zh-TW'];
        yield 'simplified script' => ['zh-Hans', 'zh-CN'];
        yield 'traditional script' => ['zh-Hant', 'zh-TW'];
        yield 'hong kong is traditional' => ['zh-HK', 'zh-TW'];
        yield 'singapore is simplified' => ['zh-SG', 'zh-CN'];
        yield 'bare chinese' => ['zh', 'zh-CN'];
        yield 'spanish spain' => ['es-ES', 'es-ES'];
        yield 'bare spanish' => ['es', 'es-ES'];
        yield 'mexican spanish' => ['es-MX', 'es-419'];
        yield 'us spanish' => ['es-US', 'es-419'];
        yield 'brazilian' => ['pt-BR', 'pt-BR'];
        yield 'bare portuguese' => ['pt', 'pt-PT'];
        yield 'angolan portuguese' => ['pt-AO', 'pt-PT'];
        yield 'bokmal' => ['nb-NO', 'no'];
        yield 'nynorsk' => ['nn', 'no'];
        yield 'english uk' => ['en-GB', 'en'];
        yield 'unshipped' => ['he-IL', null];
        yield 'not a locale' => ['C', null];
    }

    #[DataProvider('regionalCases')]
    public function testMatchAgainstRegionalCatalogue(string $tag, ?string $expected): void
    {
        $this->assertSame($expected, LocaleMatcher::match($tag, self::REGIONAL));
    }

    public function testBareCatalogueTakesRegionalTags(): void
    {
        $available = ['en', 'es', 'pt', 'zh', 'nb'];
        $this->assertSame('es', LocaleMatcher::match('es-MX', $available));
        $this->assertSame('pt', LocaleMatcher::match('pt-BR', $available));
        $this->assertSame('zh', LocaleMatcher::match('zh-TW', $available));
        $this->assertSame('nb', LocaleMatcher::match('no', $available));
    }

    public function testLanguageOnlyFallsToAnyRegionOfThatLanguage(): void
    {
        $this->assertSame('pt-BR', LocaleMatcher::match('pt', ['en', 'pt-BR']));
        $this->assertSame('es-419', LocaleMatcher::match('es-ES', ['en', 'es-419']));
        $this->assertSame('en-US', LocaleMatcher::match('en', ['de', 'en-US', 'en-GB']));
        $this->assertSame('en-GB', LocaleMatcher::match('en-GB', ['de', 'en-US', 'en-GB']));
    }

    public function testChineseCrossesScriptsOnlyAsLastResort(): void
    {
        $this->assertSame('zh-CN', LocaleMatcher::match('zh-TW', ['en', 'zh-CN']));
        $this->assertSame('zh-Hant', LocaleMatcher::match('zh-HK', ['zh-Hans', 'zh-Hant']));
    }

    public function testReturnsTheCataloguesOwnSpelling(): void
    {
        $this->assertSame('pt_br', LocaleMatcher::match('pt-BR', ['en', 'pt_br']));
    }

    public function testAliasesLetCodesThatAreNotTagsTakePart(): void
    {
        $available = ['en', 'es', 'latam', 'pt', 'pt-BR'];
        $aliases = ['latam' => 'es-419'];
        $this->assertSame('latam', LocaleMatcher::match('es-419', $available, $aliases));
        $this->assertSame('latam', LocaleMatcher::match('es-MX', $available, $aliases));
        $this->assertSame('es', LocaleMatcher::match('es-ES', $available, $aliases));
        $this->assertSame('pt', LocaleMatcher::match('pt-PT', $available, $aliases));
        // Without the alias the odd code is invisible, never a false match.
        $this->assertSame('es', LocaleMatcher::match('es-MX', $available));
        $this->assertSame('latam', LocaleMatcher::matchFirst(['he', 'es-AR'], $available, $aliases));
    }

    public function testFirstMatchingPreferenceWins(): void
    {
        $this->assertSame('fr', LocaleMatcher::matchFirst(['he', 'C', 'fr-CA', 'de'], self::REGIONAL));
        $this->assertNull(LocaleMatcher::matchFirst(['he', 'C'], self::REGIONAL));
        $this->assertNull(LocaleMatcher::matchFirst([], self::REGIONAL));
    }
}
