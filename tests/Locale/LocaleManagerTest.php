<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Locale;

use PHPUnit\Framework\TestCase;
use PHPolygon\Locale\LocaleManager;

class LocaleManagerTest extends TestCase
{
    private LocaleManager $locale;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->locale = new LocaleManager('en', 'en');
        $this->fixturesPath = sys_get_temp_dir() . '/phpolygon_locale_test_' . uniqid();
        mkdir($this->fixturesPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->fixturesPath . '/*.json');
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($this->fixturesPath)) {
            rmdir($this->fixturesPath);
        }
    }

    public function testDefaultLocale(): void
    {
        $this->assertEquals('en', $this->locale->getLocale());
        $this->assertEquals('en', $this->locale->getFallbackLocale());
    }

    public function testSetLocale(): void
    {
        $this->locale->setLocale('de');
        $this->assertEquals('de', $this->locale->getLocale());
    }

    public function testSetFallbackLocale(): void
    {
        $this->locale->setFallbackLocale('fr');
        $this->assertEquals('fr', $this->locale->getFallbackLocale());
    }

    public function testAddAndGet(): void
    {
        $this->locale->add('en', ['greeting' => 'Hello']);
        $this->assertEquals('Hello', $this->locale->get('greeting'));
    }

    public function testGetReturnsKeyWhenMissing(): void
    {
        $this->assertEquals('missing.key', $this->locale->get('missing.key'));
    }

    public function testFallbackLocale(): void
    {
        $this->locale->add('en', ['title' => 'Game Title']);
        $this->locale->setLocale('de');

        $this->assertEquals('Game Title', $this->locale->get('title'));
    }

    public function testCurrentLocaleOverridesFallback(): void
    {
        $this->locale->add('en', ['greeting' => 'Hello']);
        $this->locale->add('de', ['greeting' => 'Hallo']);
        $this->locale->setLocale('de');

        $this->assertEquals('Hallo', $this->locale->get('greeting'));
    }

    public function testPlaceholderReplacement(): void
    {
        $this->locale->add('en', ['welcome' => 'Welcome, :name! You have :count items.']);

        $result = $this->locale->get('welcome', ['name' => 'Max', 'count' => 5]);
        $this->assertEquals('Welcome, Max! You have 5 items.', $result);
    }

    public function testPlaceholderWithMissingParam(): void
    {
        $this->locale->add('en', ['msg' => 'Hello :name']);

        $result = $this->locale->get('msg', []);
        $this->assertEquals('Hello :name', $result);
    }

    public function testTIsAliasForGet(): void
    {
        $this->locale->add('en', ['key' => 'Value']);
        $this->assertEquals($this->locale->get('key'), $this->locale->t('key'));
    }

    public function testHas(): void
    {
        $this->locale->add('en', ['exists' => 'Yes']);

        $this->assertTrue($this->locale->has('exists'));
        $this->assertFalse($this->locale->has('nope'));
    }

    public function testHasChecksFallback(): void
    {
        $this->locale->add('en', ['fallback_key' => 'Value']);
        $this->locale->setLocale('de');

        $this->assertTrue($this->locale->has('fallback_key'));
    }

    public function testLoadFile(): void
    {
        $filePath = $this->fixturesPath . '/en.json';
        file_put_contents($filePath, json_encode([
            'menu' => [
                'start' => 'Start Game',
                'quit' => 'Quit',
            ],
            'title' => 'My Game',
        ]));

        $this->locale->loadFile('en', $filePath);

        $this->assertEquals('Start Game', $this->locale->get('menu.start'));
        $this->assertEquals('Quit', $this->locale->get('menu.quit'));
        $this->assertEquals('My Game', $this->locale->get('title'));
    }

    public function testLoadFileThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->locale->loadFile('en', '/nonexistent/path.json');
    }

    public function testLoadDirectory(): void
    {
        file_put_contents($this->fixturesPath . '/en.json', json_encode(['hi' => 'Hello']));
        file_put_contents($this->fixturesPath . '/de.json', json_encode(['hi' => 'Hallo']));

        $this->locale->loadDirectory($this->fixturesPath);

        $this->assertEquals('Hello', $this->locale->get('hi'));

        $this->locale->setLocale('de');
        $this->assertEquals('Hallo', $this->locale->get('hi'));
    }

    public function testLoadDirectoryThrowsOnMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->locale->loadDirectory('/nonexistent/dir');
    }

    public function testGetAvailableLocales(): void
    {
        $this->locale->add('en', ['a' => 'A']);
        $this->locale->add('de', ['a' => 'A']);
        $this->locale->add('fr', ['a' => 'A']);

        $locales = $this->locale->getAvailableLocales();
        $this->assertCount(3, $locales);
        $this->assertContains('en', $locales);
        $this->assertContains('de', $locales);
        $this->assertContains('fr', $locales);
    }

    public function testAddMergesTranslations(): void
    {
        $this->locale->add('en', ['a' => '1']);
        $this->locale->add('en', ['b' => '2']);

        $this->assertEquals('1', $this->locale->get('a'));
        $this->assertEquals('2', $this->locale->get('b'));
    }

    public function testNestedFlatten(): void
    {
        $this->locale->add('en', []);
        file_put_contents($this->fixturesPath . '/en.json', json_encode([
            'a' => [
                'b' => [
                    'c' => 'deep',
                ],
            ],
        ]));

        $this->locale->loadFile('en', $this->fixturesPath . '/en.json');
        $this->assertEquals('deep', $this->locale->get('a.b.c'));
    }

    // ── choice() / Pluralization ────────────────────────────────

    public function testChoiceTwoForms(): void
    {
        $this->locale->add('en', ['items' => 'one item|many items']);

        $this->assertEquals('one item', $this->locale->choice('items', 1));
        $this->assertEquals('many items', $this->locale->choice('items', 0));
        $this->assertEquals('many items', $this->locale->choice('items', 5));
    }

    public function testChoiceThreeForms(): void
    {
        // Three positional forms for Slavic locales (one|few|many).
        // For English (two CLDR categories) the 3rd form is never reached;
        // use a Russian locale to exercise all three indices.
        $manager = new LocaleManager('ru');
        $manager->add('ru', ['items' => 'предмет|предмета|предметов']);

        $this->assertEquals('предмет', $manager->choice('items', 1));   // one
        $this->assertEquals('предмета', $manager->choice('items', 3));  // few
        $this->assertEquals('предметов', $manager->choice('items', 5)); // many

        // For a zero/one/many pattern in English, use explicit {n} syntax instead.
        $this->locale->add('en', ['things' => '{0} no things|{1} one thing|[2,*] :count things']);

        $this->assertEquals('no things', $this->locale->choice('things', 0));
        $this->assertEquals('one thing', $this->locale->choice('things', 1));
        $this->assertEquals('42 things', $this->locale->choice('things', 42));
    }

    public function testChoiceExplicitCount(): void
    {
        $this->locale->add('en', ['apples' => '{0} No apples|{1} One apple|[2,*] :count apples']);

        $this->assertEquals('No apples', $this->locale->choice('apples', 0));
        $this->assertEquals('One apple', $this->locale->choice('apples', 1));
        $this->assertEquals('5 apples', $this->locale->choice('apples', 5));
    }

    public function testChoiceExplicitRange(): void
    {
        $this->locale->add('en', ['score' => '[0,0] Nothing|[1,3] A few|[4,*] Many']);

        $this->assertEquals('Nothing', $this->locale->choice('score', 0));
        $this->assertEquals('A few', $this->locale->choice('score', 2));
        $this->assertEquals('Many', $this->locale->choice('score', 100));
    }

    public function testChoiceAutoAddsCount(): void
    {
        $this->locale->add('en', ['msg' => 'one|:count things']);

        $this->assertEquals('7 things', $this->locale->choice('msg', 7));
    }

    public function testChoiceWithExtraParams(): void
    {
        $this->locale->add('en', ['items' => ':name has one item|:name has :count items']);

        $this->assertEquals('Max has one item', $this->locale->choice('items', 1, ['name' => 'Max']));
        $this->assertEquals('Max has 3 items', $this->locale->choice('items', 3, ['name' => 'Max']));
    }

    public function testChoiceSingleForm(): void
    {
        $this->locale->add('en', ['always' => 'always this']);

        $this->assertEquals('always this', $this->locale->choice('always', 0));
        $this->assertEquals('always this', $this->locale->choice('always', 1));
        $this->assertEquals('always this', $this->locale->choice('always', 99));
    }

    public function testChoiceFallbackToKey(): void
    {
        $this->assertEquals('missing.plural', $this->locale->choice('missing.plural', 5));
    }
}
