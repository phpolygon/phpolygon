<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Voice\VoiceProfile;
use PHPolygon\Audio\Dsp\Voice\Vocoder;
use PHPUnit\Framework\TestCase;

final class VoiceProfileTest extends TestCase
{
    public function testTheDefaultIsNeutral(): void
    {
        $p = new VoiceProfile();

        self::assertSame(Vocoder::DEFAULT_DRY, $p->vocoderDry);

        self::assertSame(1.0, $p->pitch);
        self::assertSame(1.0, $p->formant);
        self::assertSame(1.0, $p->tempo);
        self::assertSame(0.0, $p->tiltLowDb);
        self::assertSame(0.0, $p->tiltHighDb);
        self::assertSame(0.0, $p->breath);
        self::assertNull($p->vocoderHz);
        self::assertSame(0.0, $p->crush);
        self::assertTrue($p->shapesNothing());
    }

    public function testWithOverridesReplacesOnlyTheNamedFields(): void
    {
        $base = new VoiceProfile(pitch: 1.07, formant: 0.96, breath: 0.1);
        $p = $base->withOverrides(['pitch' => 0.9, 'vocoderHz' => 150.0]);

        self::assertSame(0.9, $p->pitch);
        self::assertSame(150.0, $p->vocoderHz);
        self::assertSame($base->formant, $p->formant);
        self::assertSame($base->breath, $p->breath);
        self::assertNotSame($base, $p);
    }

    public function testWithOverridesRejectsNullForANumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VoiceProfile())->withOverrides(['pitch' => null]);
    }

    public function testWithOverridesRejectsUnknownFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VoiceProfile())->withOverrides(['pich' => 1.1]);
    }

    public function testRejectsValuesOutsideTheSafeRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VoiceProfile(crush: 1.5);
    }

    public function testRejectsANonPositivePitch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VoiceProfile(pitch: 0.0);
    }

    public function testTheFingerprintTellsProfilesApart(): void
    {
        self::assertSame((new VoiceProfile())->fingerprint(), (new VoiceProfile())->fingerprint());
        self::assertNotSame((new VoiceProfile(crush: 0.7))->fingerprint(), (new VoiceProfile(crush: 0.4))->fingerprint());
        // The fingerprint is a cache key: it must not drift between releases.
        self::assertSame(hash('sha256', '{"pitch":1.0,"formant":1.0,"tempo":1.0,"tiltLowDb":0.0,"tiltHighDb":0.0,"breath":0.0,"vocoderHz":null,"vocoderDry":0.75,"crush":0.0}'), (new VoiceProfile())->fingerprint());
    }
}
