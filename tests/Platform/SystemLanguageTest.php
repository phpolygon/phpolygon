<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Platform;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPolygon\Platform\SystemLanguage;

class SystemLanguageTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function windowsNames(): iterable
    {
        yield 'german' => ['German_Germany.1252', 'de-DE'];
        yield 'us english' => ['English_United States.1252', 'en-US'];
        yield 'simplified chinese' => ["Chinese (Simplified)_People's Republic of China.936", 'zh-Hans-CN'];
        yield 'traditional chinese' => ['Chinese (Traditional)_Taiwan.950', 'zh-Hant-TW'];
        yield 'brazil' => ['Portuguese_Brazil.1252', 'pt-BR'];
        yield 'mexico' => ['Spanish_Mexico.1252', 'es-MX'];
        yield 'sort suffix' => ['Spanish - Modern Sort_Spain.1252', 'es-ES'];
        yield 'bokmal' => ['Norwegian Bokmål_Norway.1252', 'nb-NO'];
        yield 'utf-8 code page' => ['Japanese_Japan.utf8', 'ja-JP'];
        yield 'unknown country' => ['German_Liechtenstein.1252', 'de'];
        yield 'already a tag' => ['de-DE', 'de-DE'];
        yield 'C' => ['C', null];
        yield 'unknown language' => ['Klingon_Qonos.1252', null];
    }

    #[DataProvider('windowsNames')]
    public function testWindowsLocaleNames(string $name, ?string $expected): void
    {
        $this->assertSame($expected, SystemLanguage::fromWindowsLocale($name));
    }

    public function testEnvironmentOrder(): void
    {
        $this->assertSame(
            ['fr-CA', 'de', 'it-IT', 'es-ES', 'en-US'],
            SystemLanguage::fromEnvironment([
                'LANGUAGE' => 'fr_CA:de',
                'LC_ALL' => 'it_IT.UTF-8',
                'LC_MESSAGES' => 'es_ES.UTF-8',
                'LANG' => 'en_US.UTF-8',
            ]),
        );
    }

    public function testEnvironmentSkipsCAndBlanksAndDuplicates(): void
    {
        $this->assertSame(['de-DE'], SystemLanguage::fromEnvironment([
            'LANGUAGE' => '',
            'LC_ALL' => '',
            'LC_MESSAGES' => 'de_DE.UTF-8',
            'LANG' => 'de_DE.UTF-8',
            'HOME' => '/home/player',
        ]));
        // LC_ALL decides over everything else - C means "untranslated".
        $this->assertSame([], SystemLanguage::fromEnvironment(['LC_ALL' => 'C.UTF-8', 'LANG' => 'de_DE.UTF-8']));
        $this->assertSame([], SystemLanguage::fromEnvironment([]));
    }

    public function testGnuLanguageListIsIgnoredUnderTheCLocale(): void
    {
        // gettext ignores LANGUAGE when the locale itself is C - so do we.
        $this->assertSame([], SystemLanguage::fromEnvironment(['LANGUAGE' => 'fr', 'LANG' => 'C']));
    }

    public function testAppleLanguagesOutput(): void
    {
        $out = "(\n    \"de-DE\",\n    en,\n    \"zh-Hans-CN\"\n)\n";
        $this->assertSame(['de-DE', 'en', 'zh-Hans-CN'], SystemLanguage::fromAppleLanguages($out));
        $this->assertSame([], SystemLanguage::fromAppleLanguages(''));
        $this->assertSame([], SystemLanguage::fromAppleLanguages('The domain/default pair does not exist'));
    }

    public function testWindowsAsksTheUserLocaleBeforeTheEnvironment(): void
    {
        $asked = [];
        $tags = SystemLanguage::detect(
            ['LANG' => 'en_US.UTF-8'],
            'Windows',
            static function () use (&$asked): ?string {
                $asked[] = 'windows';
                return 'German_Germany.1252';
            },
            static function () use (&$asked): ?string {
                $asked[] = 'apple';
                return null;
            },
        );

        $this->assertSame(['de-DE', 'en-US'], $tags);
        $this->assertSame(['windows'], $asked);
    }

    public function testLinuxUsesOnlyTheEnvironment(): void
    {
        $never = static function (): ?string {
            throw new \LogicException('must not be asked on Linux');
        };
        $this->assertSame(['fr-FR'], SystemLanguage::detect(['LANG' => 'fr_FR.UTF-8'], 'Linux', $never, $never));
        $this->assertSame([], SystemLanguage::detect([], 'Linux', $never, $never));
    }

    public function testMacAsksTheLanguageListAfterTheEnvironment(): void
    {
        $tags = SystemLanguage::detect(
            ['LANG' => 'de_DE.UTF-8'],
            'Darwin',
            static fn (): ?string => null,
            static fn (): ?string => "(\n    ja,\n    \"de-DE\"\n)",
        );
        $this->assertSame(['de-DE', 'ja'], $tags);
    }

    public function testAFailingProbeIsNoLanguage(): void
    {
        $tags = SystemLanguage::detect(
            ['LANG' => 'it_IT'],
            'Windows',
            static function (): ?string {
                throw new \RuntimeException('no locale');
            },
            static fn (): ?string => null,
        );
        $this->assertSame(['it-IT'], $tags);
    }

    public function testWindowsProbeLeavesTheProcessLocaleAlone(): void
    {
        $before = setlocale(LC_ALL, '0');
        SystemLanguage::windowsUserLocale();
        $this->assertSame($before, setlocale(LC_ALL, '0'));
    }

    public function testRealDetectionNeverThrows(): void
    {
        foreach (SystemLanguage::detect() as $tag) {
            $this->assertMatchesRegularExpression('/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|\d{3}))?$/', $tag);
        }
        $this->addToAssertionCount(1);
    }
}
