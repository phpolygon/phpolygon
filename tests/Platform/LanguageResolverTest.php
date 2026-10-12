<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Platform;

use PHPUnit\Framework\TestCase;
use PHPolygon\Platform\LanguageResolver;
use PHPolygon\Platform\ResolvedLanguage;

class LanguageResolverTest extends TestCase
{
    private const AVAILABLE = ['de', 'en', 'fr', 'pt-BR', 'pt-PT', 'es-ES', 'es-419', 'zh-CN', 'zh-TW'];

    /**
     * @param list<string> $steam
     * @param list<string> $system
     * @param list<string> $asked
     */
    private function resolver(array $steam, array $system, array &$asked = []): LanguageResolver
    {
        return new LanguageResolver(
            self::AVAILABLE,
            'en',
            static function () use ($steam, &$asked): array {
                $asked[] = 'steam';
                return $steam;
            },
            static function () use ($system, &$asked): array {
                $asked[] = 'system';
                return $system;
            },
        );
    }

    public function testSavedChoiceWinsAndAsksNobody(): void
    {
        $asked = [];
        $result = $this->resolver(['fr'], ['de-DE'], $asked)->resolve('pt-BR');

        $this->assertEquals(new ResolvedLanguage('pt-BR', ResolvedLanguage::SAVED), $result);
        $this->assertSame([], $asked);
        $this->assertTrue($result->wasChosen());
    }

    public function testSteamBeatsTheSystem(): void
    {
        $asked = [];
        $result = $this->resolver(['es-419'], ['de-DE'], $asked)->resolve(null);

        $this->assertSame('es-419', $result->locale);
        $this->assertSame(ResolvedLanguage::STEAM, $result->source);
        $this->assertFalse($result->wasChosen());
        $this->assertSame(['steam'], $asked);
    }

    public function testSystemWhenSteamIsSilent(): void
    {
        $result = $this->resolver([], ['zh-Hant-TW'])->resolve(null);
        $this->assertEquals(new ResolvedLanguage('zh-TW', ResolvedLanguage::SYSTEM), $result);
    }

    public function testSystemWhenSteamSpeaksAnUnshippedLanguage(): void
    {
        $result = $this->resolver(['ja'], ['fr-BE'])->resolve(null);
        $this->assertEquals(new ResolvedLanguage('fr', ResolvedLanguage::SYSTEM), $result);
    }

    public function testSecondSteamLanguageCountsBeforeTheSystem(): void
    {
        // Per-game language unshipped, Steam client language shipped.
        $result = $this->resolver(['ja', 'de'], ['fr-FR'])->resolve(null);
        $this->assertEquals(new ResolvedLanguage('de', ResolvedLanguage::STEAM), $result);
    }

    public function testFallbackWhenNothingMatches(): void
    {
        $result = $this->resolver(['ja'], ['he-IL'])->resolve(null);
        $this->assertEquals(new ResolvedLanguage('en', ResolvedLanguage::FALLBACK), $result);
    }

    public function testASavedLanguageTheGameNoLongerShipsIsIgnored(): void
    {
        $result = $this->resolver([], ['fr-FR'])->resolve('ko');
        $this->assertEquals(new ResolvedLanguage('fr', ResolvedLanguage::SYSTEM), $result);
    }

    public function testEmptySavedValueCountsAsNoChoice(): void
    {
        $result = $this->resolver(['de'], [])->resolve('');
        $this->assertSame(ResolvedLanguage::STEAM, $result->source);
    }

    public function testAliasedCodesResolveFromSteamAndFromTheSave(): void
    {
        $available = ['en', 'es', 'latam'];
        $aliases = ['latam' => 'es-419'];
        $none = static fn (): array => [];

        $steam = new LanguageResolver($available, 'en', static fn (): array => ['es-419'], $none, $aliases);
        $this->assertEquals(new ResolvedLanguage('latam', ResolvedLanguage::STEAM), $steam->resolve(null));
        $this->assertEquals(new ResolvedLanguage('latam', ResolvedLanguage::SAVED), $steam->resolve('latam'));
        $this->assertEquals(new ResolvedLanguage('es', ResolvedLanguage::SAVED), $steam->resolve('es'));
    }

    public function testThrowingSourcesAreSkipped(): void
    {
        $boom = static function (): array {
            throw new \RuntimeException('source failed');
        };
        $result = (new LanguageResolver(self::AVAILABLE, 'en', $boom, $boom))->resolve(null);
        $this->assertEquals(new ResolvedLanguage('en', ResolvedLanguage::FALLBACK), $result);
    }

    public function testDefaultSourcesNeverThrow(): void
    {
        $result = (new LanguageResolver(self::AVAILABLE, 'en'))->resolve(null);
        $this->assertContains($result->locale, self::AVAILABLE);
        $this->assertContains($result->source, [ResolvedLanguage::STEAM, ResolvedLanguage::SYSTEM, ResolvedLanguage::FALLBACK]);
    }
}
