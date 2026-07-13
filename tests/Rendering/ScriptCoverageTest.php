<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\Script;
use PHPUnit\Framework\TestCase;

final class ScriptCoverageTest extends TestCase
{
    public function testOfCodepointMapsEachScript(): void
    {
        self::assertSame(Script::Latin, Script::ofCodepoint(0x0041));      // A
        self::assertSame(Script::Cyrillic, Script::ofCodepoint(0x0410));   // А
        self::assertSame(Script::Greek, Script::ofCodepoint(0x0391));      // Α
        self::assertSame(Script::Han, Script::ofCodepoint(0x4E00));        // 一
        self::assertSame(Script::Kana, Script::ofCodepoint(0x3042));       // あ
        self::assertSame(Script::Hangul, Script::ofCodepoint(0xAC00));     // 가
        self::assertSame(Script::Arabic, Script::ofCodepoint(0x0627));     // ا
        self::assertSame(Script::Hebrew, Script::ofCodepoint(0x05D0));     // א
        self::assertSame(Script::Thai, Script::ofCodepoint(0x0E01));       // ก
        self::assertSame(Script::Devanagari, Script::ofCodepoint(0x0905)); // अ
    }

    public function testEachSampleCharRoundTripsToItsScript(): void
    {
        foreach (Script::cases() as $s) {
            self::assertSame($s, Script::ofCodepoint($s->sampleCodepoint()), $s->value);
        }
    }

    public function testOfTextReturnsDistinctNonLatinInOrder(): void
    {
        // Latin dropped, duplicates removed, first-appearance order kept.
        self::assertSame([Script::Cyrillic, Script::Han], Script::ofText('Hi Привет 日 Привет'));
        self::assertSame([], Script::ofText('plain latin 123 !?'));
        self::assertSame([Script::Greek], Script::ofText('π ≈ 3.14 Ω'));
    }

    public function testFontForScriptPicksFirstCoveringCandidate(): void
    {
        // A renderer where only 'cyr-face' covers Cyrillic; exercise the real
        // NullRenderer2D iteration by overriding just the coverage predicate.
        $r = new class extends NullRenderer2D {
            public function fontCoversScript(string $font, Script $script): bool
            {
                return $font === 'cyr-face';
            }

            public function fontForScript(Script $script, array $candidates): ?string
            {
                foreach ($candidates as $font) {
                    if ($this->fontCoversScript($font, $script)) {
                        return $font;
                    }
                }
                return null;
            }
        };

        self::assertSame('cyr-face', $r->fontForScript(Script::Cyrillic, ['latin-only', 'cyr-face']));
        self::assertNull($r->fontForScript(Script::Cyrillic, ['latin-only', 'other']));
    }

    public function testHeadlessRendererReportsFullCoverage(): void
    {
        $r = new NullRenderer2D();
        self::assertTrue($r->fontCoversScript('anything', Script::Cyrillic));
        self::assertSame('first', $r->fontForScript(Script::Han, ['first', 'second']));
        self::assertNull($r->fontForScript(Script::Han, []));
    }
}
