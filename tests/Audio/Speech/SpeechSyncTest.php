<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Speech;

use PHPolygon\Audio\Speech\SpeechConfig;
use PHPolygon\Audio\Speech\SpeechFiles;
use PHPolygon\Audio\Speech\SpeechLine;
use PHPolygon\Audio\Speech\SpeechStatus;
use PHPolygon\Audio\Speech\SpeechSync;
use PHPUnit\Framework\TestCase;

final class SpeechSyncTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-speech-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir . '/lang', 0755, true);
        mkdir($this->dir . '/ref', 0755, true);
        file_put_contents($this->dir . '/ref/narrator.wav', 'narrator reference');
        file_put_contents($this->dir . '/ref/robot.wav', 'robot reference');
        $this->translations('de', [
            'menu' => ['start' => 'Start'],
            'dialog' => ['intro' => 'Hallo, ich bin dein Begleiter.', 'robot' => ['beep' => 'Piep.'], 'greet' => 'Hallo :name!'],
        ]);
        $this->translations('en', ['dialog' => ['intro' => 'Hello, I am your companion.', 'robot' => ['beep' => 'Beep.'], 'greet' => 'Hello :name!']]);
        $this->translations('th', ['dialog' => ['intro' => 'สวัสดี']]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testPlanFindsSpokenKeysWithTheirVoiceAndStatus(): void
    {
        $lines = $this->sync(new FakeSynthesizer())->plan();

        self::assertSame([
            'de dialog.intro missing narrator',
            'de dialog.robot.beep missing robot',
            'de dialog.greet unspeakable narrator',
            'en dialog.intro missing narrator',
            'en dialog.robot.beep missing robot',
            'en dialog.greet unspeakable narrator',
            'th dialog.intro unsupported narrator',
        ], array_map(
            static fn (SpeechLine $l): string => "{$l->locale} {$l->key} {$l->status->value} {$l->voice?->name}",
            $lines,
        ));
    }

    public function testSyncWritesFlacAndManifestThenLinesAreCurrent(): void
    {
        $synthesizer = new FakeSynthesizer();
        $sync = $this->sync($synthesizer);
        $progress = [];

        $result = $sync->sync($sync->plan(), function (SpeechLine $line, ?string $error, int $done, int $total) use (&$progress): void {
            $progress[] = "{$done}/{$total} {$line->locale} {$line->key}";
        });

        self::assertCount(4, $result->generated);
        self::assertSame([], $result->failed);
        self::assertSame(['1/4 de dialog.intro', '2/4 de dialog.robot.beep', '3/4 en dialog.intro', '4/4 en dialog.robot.beep'], $progress);
        $flac = (string) file_get_contents(SpeechFiles::path($this->dir . '/voice', 'de', 'dialog.intro'));
        self::assertStringStartsWith('fLaC', $flac);
        self::assertSame(['de', 'en'], array_keys($this->manifest()['lines']));
        self::assertDirectoryDoesNotExist($this->dir . '/voice/.work');

        self::assertSame(['de|robot.wav|7|0.6', 'de|narrator.wav|0|0.5'], [
            "{$synthesizer->jobs[1]->language}|" . basename((string) $synthesizer->jobs[1]->reference) . "|{$synthesizer->jobs[1]->seed}|{$synthesizer->jobs[1]->exaggeration}",
            "{$synthesizer->jobs[0]->language}|" . basename((string) $synthesizer->jobs[0]->reference) . "|{$synthesizer->jobs[0]->seed}|{$synthesizer->jobs[0]->exaggeration}",
        ]);

        $again = $this->sync(new FakeSynthesizer())->plan();
        self::assertSame(4, count(array_filter($again, static fn (SpeechLine $l): bool => $l->status === SpeechStatus::Current)));
    }

    public function testChangedTextReferenceOrSynthesizerMakesLinesStale(): void
    {
        $sync = $this->sync(new FakeSynthesizer());
        $sync->sync($sync->plan());

        $this->translations('de', ['dialog' => ['intro' => 'Hallo, ich bin neu.', 'robot' => ['beep' => 'Piep.']]]);
        file_put_contents($this->dir . '/ref/robot.wav', 'another robot');

        $statuses = [];
        foreach ($this->sync(new FakeSynthesizer())->plan() as $line) {
            $statuses["{$line->locale} {$line->key}"] = $line->status;
        }
        self::assertSame(SpeechStatus::Stale, $statuses['de dialog.intro']);
        self::assertSame(SpeechStatus::Stale, $statuses['de dialog.robot.beep']);
        self::assertSame(SpeechStatus::Stale, $statuses['en dialog.robot.beep']);
        self::assertSame(SpeechStatus::Current, $statuses['en dialog.intro']);

        foreach ($this->sync(new FakeSynthesizer('fake/2'))->plan() as $line) {
            if ($line->status !== SpeechStatus::Unsupported && $line->status !== SpeechStatus::Unspeakable) {
                self::assertSame(SpeechStatus::Stale, $line->status, "{$line->locale} {$line->key}");
            }
        }
    }

    public function testFailedLineIsReportedAndStaysMissing(): void
    {
        $sync = $this->sync(new FakeSynthesizer(failTexts: ['Piep.']));

        $result = $sync->sync($sync->plan(['de']));

        self::assertCount(1, $result->generated);
        self::assertSame(['de dialog.robot.beep: synthesis failed'], array_map(
            static fn (array $f): string => "{$f['line']->locale} {$f['line']->key}: {$f['error']}",
            $result->failed,
        ));
        $statuses = array_map(static fn (SpeechLine $l): string => "{$l->key} {$l->status->value}", $this->sync(new FakeSynthesizer())->plan(['de']));
        self::assertContains('dialog.robot.beep missing', $statuses);
        self::assertContains('dialog.intro current', $statuses);
    }

    public function testPlanFiltersLocalesAndKeys(): void
    {
        $lines = $this->sync(new FakeSynthesizer())->plan(['en'], ['dialog.robot.*']);

        self::assertSame(['en dialog.robot.beep'], array_map(static fn (SpeechLine $l): string => "{$l->locale} {$l->key}", $lines));
    }

    public function testMissingReferenceFailsThePlan(): void
    {
        unlink($this->dir . '/ref/robot.wav');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("speech voice 'robot': reference ref/robot.wav not found");
        $this->sync(new FakeSynthesizer())->plan();
    }

    public function testBuiltinVoiceNeedsNoReferenceFile(): void
    {
        $config = SpeechConfig::fromBuildJson([
            'locales' => 'lang',
            'output' => 'voice',
            'keys' => ['dialog.intro'],
            'voices' => ['guide' => ['reference' => null, 'seed' => 3]],
        ]);
        self::assertNotNull($config);
        $synthesizer = new FakeSynthesizer();
        $sync = new SpeechSync($config, $this->dir, $synthesizer);

        $result = $sync->sync($sync->plan(['de']));

        self::assertCount(1, $result->generated);
        self::assertNull($synthesizer->jobs[0]->reference);
        self::assertSame(3, $synthesizer->jobs[0]->seed);
        $again = (new SpeechSync($config, $this->dir, new FakeSynthesizer()))->plan(['de']);
        self::assertSame(SpeechStatus::Current, $again[0]->status);
    }

    public function testSpeakableText(): void
    {
        self::assertTrue(SpeechSync::isSpeakable('Hinweis:Text um 10:30.'));
        self::assertFalse(SpeechSync::isSpeakable('Hallo :name!'));
        self::assertFalse(SpeechSync::isSpeakable('Ein Apfel|Viele Äpfel'));
        self::assertFalse(SpeechSync::isSpeakable('  '));
    }

    public function testFilePathsAreSafeAndFindFallsBack(): void
    {
        self::assertSame('/v/de/dialog.a_b.flac', SpeechFiles::path('/v/', 'de', 'dialog.a/b'));

        mkdir($this->dir . '/voice/en', 0755, true);
        touch($this->dir . '/voice/en/dialog.intro.flac');
        self::assertSame($this->dir . '/voice/en/dialog.intro.flac', SpeechFiles::find($this->dir . '/voice', 'de', 'dialog.intro', 'en'));
        self::assertNull(SpeechFiles::find($this->dir . '/voice', 'de', 'dialog.intro'));
    }

    private function sync(FakeSynthesizer $synthesizer): SpeechSync
    {
        $config = SpeechConfig::fromBuildJson([
            'locales' => 'lang',
            'output' => 'voice',
            'keys' => ['dialog.*'],
            'voices' => [
                'narrator' => ['reference' => 'ref/narrator.wav'],
                'robot' => ['reference' => 'ref/robot.wav', 'keys' => ['dialog.robot.*'], 'seed' => 7, 'exaggeration' => 0.6, 'profile' => ['crush' => 0.2]],
            ],
        ]);
        self::assertNotNull($config);
        return new SpeechSync($config, $this->dir, $synthesizer);
    }

    /** @param array<string, mixed> $data */
    private function translations(string $locale, array $data): void
    {
        file_put_contents($this->dir . "/lang/{$locale}.json", json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** @return array{lines: array<string, array<string, string>>} */
    private function manifest(): array
    {
        /** @var array{lines: array<string, array<string, string>>} */
        return json_decode((string) file_get_contents($this->dir . '/voice/' . SpeechFiles::MANIFEST), true);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}
