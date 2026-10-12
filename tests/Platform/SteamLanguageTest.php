<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Platform;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPolygon\Locale\LocaleMatcher;
use PHPolygon\Platform\SteamLanguage;

class SteamLanguageTest extends TestCase
{
    /** Every API language code Steam documents for games, with the tag it stands for. */
    private const TABLE = [
        'arabic' => 'ar', 'bulgarian' => 'bg', 'schinese' => 'zh-CN', 'tchinese' => 'zh-TW',
        'czech' => 'cs', 'danish' => 'da', 'dutch' => 'nl', 'english' => 'en', 'finnish' => 'fi',
        'french' => 'fr', 'german' => 'de', 'greek' => 'el', 'hungarian' => 'hu', 'indonesian' => 'id',
        'italian' => 'it', 'japanese' => 'ja', 'koreana' => 'ko', 'malay' => 'ms', 'norwegian' => 'no',
        'polish' => 'pl', 'portuguese' => 'pt-PT', 'brazilian' => 'pt-BR', 'romanian' => 'ro',
        'russian' => 'ru', 'spanish' => 'es-ES', 'latam' => 'es-419', 'swedish' => 'sv', 'thai' => 'th',
        'turkish' => 'tr', 'ukrainian' => 'uk', 'vietnamese' => 'vi',
    ];

    /** @return iterable<string, array{string, string}> */
    public static function table(): iterable
    {
        foreach (self::TABLE as $code => $tag) {
            yield $code => [$code, $tag];
        }
    }

    #[DataProvider('table')]
    public function testApiCodeMapsToTag(string $code, string $tag): void
    {
        $this->assertSame($tag, SteamLanguage::toLocaleTag($code));
        // Every tag is already in normal form, so a game can compare it directly.
        $this->assertSame($tag, LocaleMatcher::normalize($tag));
    }

    public function testTableIsComplete(): void
    {
        $this->assertSame(self::TABLE, SteamLanguage::API_CODES);
    }

    public function testCodesAreCaseInsensitiveAndTrimmed(): void
    {
        $this->assertSame('de', SteamLanguage::toLocaleTag(' German '));
        $this->assertSame('ko', SteamLanguage::toLocaleTag('korean'));
    }

    public function testUnknownCodeIsNull(): void
    {
        $this->assertNull(SteamLanguage::toLocaleTag('klingon'));
        $this->assertNull(SteamLanguage::toLocaleTag(''));
    }

    public function testDetectReadsGameLanguageThenClientLanguage(): void
    {
        $calls = [];
        $tags = SteamLanguage::detect(static function (string $fn) use (&$calls): mixed {
            $calls[] = $fn;
            return $fn === 'steam_apps_get_language' ? 'latam' : 'english';
        });

        $this->assertSame(['es-419', 'en'], $tags);
        $this->assertSame(['steam_apps_get_language', 'steam_utils_get_steam_ui_language'], $calls);
    }

    public function testDetectDropsDuplicatesAndUnknowns(): void
    {
        $this->assertSame(['de'], SteamLanguage::detect(static fn (string $fn): mixed => 'german'));
        $this->assertSame(['fr'], SteamLanguage::detect(
            static fn (string $fn): mixed => $fn === 'steam_apps_get_language' ? 'klingon' : 'french',
        ));
    }

    public function testDetectIsEmptyWhenSteamAnswersNothing(): void
    {
        // false = not initialised, null = function missing - both mean "no Steam".
        $this->assertSame([], SteamLanguage::detect(static fn (string $fn): mixed => false));
        $this->assertSame([], SteamLanguage::detect(static fn (string $fn): mixed => null));
        $this->assertSame([], SteamLanguage::detect(static fn (string $fn): mixed => ''));
    }

    public function testDetectSurvivesAThrowingBinding(): void
    {
        $this->assertSame([], SteamLanguage::detect(static function (string $fn): mixed {
            throw new \RuntimeException('Steam went away');
        }));
    }

    public function testDetectWithoutTheExtensionIsEmpty(): void
    {
        if (function_exists('steam_apps_get_language')) {
            $this->markTestSkipped('php-steamworks is loaded here');
        }
        $this->assertSame([], SteamLanguage::detect());
    }
}
