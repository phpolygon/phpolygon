<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Voice\PitchDetector;
use PHPolygon\Audio\Dsp\Voice\VoiceChain;
use PHPolygon\Audio\Dsp\Voice\VoiceMatcher;
use PHPolygon\Audio\Dsp\Voice\VoiceProfile;
use PHPolygon\Audio\Dsp\Voice\VoiceReference;
use PHPolygon\Audio\Dsp\Voice\VoiceShaper;
use PHPUnit\Framework\TestCase;

final class VoiceMatcherTest extends TestCase
{
    /** @var array<string, VoiceReference> */
    private static array $refs = [];

    private static function ref(float $f0, float $formantScale = 1.0, float $swing = 0.12): VoiceReference
    {
        return self::$refs["$f0/$formantScale/$swing"] ??= VoiceReference::analyse(VoiceReferenceTest::speech($f0, $formantScale, $swing));
    }

    public function testMatchingAVoiceToItselfChangesNothing(): void
    {
        $x = VoiceReferenceTest::speech(150.0);

        self::assertSame($x, (new VoiceMatcher())->match($x, self::ref(150.0)));
    }

    public function testALowLargeVoiceTakesOnTheTargetsPitchAndFormants(): void
    {
        $x = VoiceReferenceTest::speech(120.0);
        $target = self::ref(210.0, 1.15);
        $before = VoiceReference::analyse($x);

        $y = (new VoiceMatcher())->match($x, $target);
        $after = VoiceReference::analyse($y);

        self::assertCount(count($x), $y, 'matching keeps the timing');
        self::assertEqualsWithDelta($target->medianF0, $after->medianF0, $target->medianF0 * 0.03);
        self::assertEqualsWithDelta(1.0, $after->formantRatioTo($target), 0.06);
        self::assertLessThan(0.5 * $before->ltasDeviation($target), $after->ltasDeviation($target));
    }

    public function testTheRangeMovesHalfwayTowardTheTarget(): void
    {
        $x = VoiceReferenceTest::speech(160.0, 1.0, 0.06);
        $target = self::ref(160.0, 1.0, 0.18);
        $in = VoiceReference::analyse($x);

        $out = VoiceReference::analyse((new VoiceMatcher())->match($x, $target));

        $span = static fn (VoiceReference $r): float => log($r->f0P90 / $r->f0P10);
        $halfway = $span($in) + 0.5 * ($span($target) - $span($in));
        self::assertEqualsWithDelta($halfway, $span($out), 0.25 * ($span($target) - $span($in)));
    }

    public function testTheEqualiserPullsTheSpectrumTowardTheTarget(): void
    {
        $x = VoiceReferenceTest::speech(150.0);
        $dull = (new VoiceShaper())->shape($x, new VoiceProfile(tiltHighDb: -6.0, tiltLowDb: 3.0));
        $target = self::ref(150.0);
        $before = VoiceReference::analyse($dull)->ltasDeviation($target);

        $y = (new VoiceMatcher())->match($dull, $target);

        self::assertLessThan(0.4 * $before, VoiceReference::analyse($y)->ltasDeviation($target));
        self::assertEqualsWithDelta(150.0, (new PitchDetector())->track($y)->medianF0(), 150.0 * 0.03);
    }

    public function testIsDeterministic(): void
    {
        $x = VoiceReferenceTest::speech(120.0);

        self::assertSame((new VoiceMatcher())->match($x, self::ref(210.0, 1.15)), (new VoiceMatcher())->match($x, self::ref(210.0, 1.15)));
    }

    public function testTheChainMatchesBeforeTheSpeakerProfile(): void
    {
        $x = VoiceReferenceTest::speech(120.0);
        $target = self::ref(210.0, 1.15);
        $p = new VoiceProfile(pitch: 1.1);

        $y = (new VoiceChain())->render($x, $p, $target);

        self::assertEqualsWithDelta(210.0 * 1.1, (new PitchDetector())->track($y)->medianF0(), 210.0 * 1.1 * 0.03);
    }
}
