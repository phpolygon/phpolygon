<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\PathGlob;
use PHPUnit\Framework\TestCase;

class PathGlobTest extends TestCase
{
    public function testPlainPathCoversTheFolderAndEverythingBelow(): void
    {
        $glob = new PathGlob(['assets/audio/raw']);

        $this->assertTrue($glob->matches('assets/audio/raw'));
        $this->assertTrue($glob->matches('assets/audio/raw/de/line1.wav'));
        $this->assertFalse($glob->matches('assets/audio/rawness/a.wav'));
        $this->assertFalse($glob->matches('assets/audio'));
    }

    public function testStarStaysInsideOneSegment(): void
    {
        $glob = new PathGlob(['assets/audio/voice-*']);

        $this->assertTrue($glob->matches('assets/audio/voice-src/de/a.wav'));
        $this->assertTrue($glob->matches('assets/audio/voice-ref'));
        $this->assertFalse($glob->matches('assets/audio/voice'));
        $this->assertFalse($glob->matches('assets/x/audio/voice-src'));
    }

    public function testDoubleStarSpansSegmentsAndMatchesNone(): void
    {
        $glob = new PathGlob(['assets/**/*.wav']);

        $this->assertTrue($glob->matches('assets/a.wav'));
        $this->assertTrue($glob->matches('assets/audio/voice/de/a.wav'));
        $this->assertFalse($glob->matches('assets/audio/a.flac'));
        $this->assertFalse($glob->matches('resources/a.wav'));
    }

    public function testCharacterClassesAndBackslashes(): void
    {
        $glob = new PathGlob(['assets/take[0-9].wav', 'assets/[!a]*.tmp']);

        $this->assertTrue($glob->matches('assets\\take3.wav'));
        $this->assertFalse($glob->matches('assets/takeX.wav'));
        $this->assertTrue($glob->matches('assets/b.tmp'));
        $this->assertFalse($glob->matches('assets/a.tmp'));
    }

    public function testNoPatternsMatchNothing(): void
    {
        $this->assertFalse((new PathGlob([]))->matches('assets/a'));
    }

    public function testValidateNormalisesSeparatorsAndTrailingSlashes(): void
    {
        $this->assertSame(
            ['assets/audio/raw', 'resources/x'],
            PathGlob::validate(['assets\\audio\\raw\\', './resources/x/', 'resources/x'], 'resources.exclude'),
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidPatterns(): array
    {
        return [
            'not a list' => [['a' => 'assets']],
            'not an array' => ['assets/raw'],
            'empty string' => [['']],
            'number' => [[42]],
            'absolute' => [['/assets/raw']],
            'drive letter' => [['C:/assets/raw']],
            'parent directory' => [['assets/../secrets']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPatterns')]
    public function testValidateRejectsAnythingButProjectRelativePatterns(mixed $patterns): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('resources.exclude');
        PathGlob::validate($patterns, 'resources.exclude');
    }
}
