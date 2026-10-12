<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Voice\VoiceProfile;
use PHPolygon\Audio\Dsp\Voice\VoiceReference;
use PHPolygon\Audio\Dsp\Voice\VoiceShaper;
use PHPolygon\Tests\Audio\Dsp\Signals;
use PHPUnit\Framework\TestCase;

final class VoiceReferenceTest extends TestCase
{
    /** @var array<string, list<float>> */
    private static array $speech = [];

    /** @return list<float> */
    public static function speech(float $f0, float $formantScale = 1.0, float $swing = 0.12): array
    {
        return self::$speech["$f0/$formantScale/$swing"] ??= Signals::speech($f0, $formantScale, $swing);
    }

    public function testMeasuresThePitchAndItsRange(): void
    {
        $ref = VoiceReference::analyse(self::speech(150.0));

        self::assertEqualsWithDelta(150.0, $ref->medianF0, 150.0 * 0.03);
        self::assertLessThan(150.0 * 0.95, $ref->f0P10);
        self::assertGreaterThan(150.0 * 1.05, $ref->f0P90);
        self::assertGreaterThan(130.0, $ref->f0P10);
        self::assertLessThan(172.0, $ref->f0P90);
    }

    public function testEstimatesTheFormantsOfASingleVowel(): void
    {
        [$f1, $f2, $f3] = Signals::VOWELS['a'];
        $ref = VoiceReference::analyse(Signals::vowel(110.0, 0.8, Signals::VOWELS['a']));

        self::assertCount(3, $ref->formants);
        self::assertEqualsWithDelta($f1[0], $ref->formants[0], $f1[0] * 0.12);
        self::assertEqualsWithDelta($f2[0], $ref->formants[1], $f2[0] * 0.10);
        self::assertEqualsWithDelta($f3[0], $ref->formants[2], $f3[0] * 0.08);
    }

    public function testAScaledVocalTractScalesTheFormantEstimate(): void
    {
        $a = VoiceReference::analyse(self::speech(150.0));
        $b = VoiceReference::analyse(self::speech(150.0, 1.15));

        self::assertEqualsWithDelta(1.15, $a->formantRatioTo($b), 0.05);
        self::assertEqualsWithDelta(1.0, $a->formantRatioTo($a), 1e-12);
    }

    public function testTheLtasCoversEightyHertzToTenKilohertzInSixthOctaves(): void
    {
        $ref = VoiceReference::analyse(self::speech(150.0));

        self::assertCount(count(VoiceReference::bandCentres()), $ref->ltasDb);
        self::assertEqualsWithDelta(80.0, VoiceReference::bandCentres()[0], 1e-9);
        self::assertLessThanOrEqual(10000.0, max(VoiceReference::bandCentres()));
        self::assertGreaterThan(40, count($ref->ltasDb));
        self::assertEqualsWithDelta(2 ** (1 / 6), VoiceReference::bandCentres()[1] / VoiceReference::bandCentres()[0], 1e-9);
    }

    public function testTheLtasSeesASpectralTilt(): void
    {
        $x = self::speech(150.0);
        $dull = (new VoiceShaper())->shape($x, new VoiceProfile(tiltHighDb: -6.0));
        $a = VoiceReference::analyse($x);
        $b = VoiceReference::analyse($dull);

        self::assertGreaterThan(2.0, $a->ltasDeviation($b));
        self::assertSame(0.0, $a->ltasDeviation($a));
        $centres = VoiceReference::bandCentres();
        $hi = array_search(true, array_map(static fn (float $c): bool => $c > 5000.0, $centres), true);
        $lo = array_search(true, array_map(static fn (float $c): bool => $c > 150.0, $centres), true);
        self::assertIsInt($hi);
        self::assertIsInt($lo);
        // Levels are relative (mean removed), so compare the high/low balance.
        self::assertGreaterThan(4.0, ($a->ltasDb[$hi] - $a->ltasDb[$lo]) - ($b->ltasDb[$hi] - $b->ltasDb[$lo]));
    }

    public function testRoundTripsThroughJson(): void
    {
        $ref = VoiceReference::analyse(self::speech(150.0));
        $back = VoiceReference::fromJson($ref->toJson());

        self::assertEquals($ref, $back);
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VoiceReference::fromJson('{"version":1,"medianF0":"high"}');
    }

    public function testIsDeterministicAndSilenceHasNoVoice(): void
    {
        $x = self::speech(150.0);
        self::assertEquals(VoiceReference::analyse($x), VoiceReference::analyse($x));

        $this->expectException(\InvalidArgumentException::class);
        VoiceReference::analyse(array_fill(0, 22050, 0.0));
    }

}
