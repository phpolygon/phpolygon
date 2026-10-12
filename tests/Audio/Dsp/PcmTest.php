<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp;

use PHPolygon\Audio\Codec\WavWriter;
use PHPolygon\Audio\Dsp\Pcm;
use PHPUnit\Framework\TestCase;

final class PcmTest extends TestCase
{
    public function testToInt16RoundsAndClampsSymmetrically(): void
    {
        self::assertSame(0, Pcm::toInt16(0.0));
        self::assertSame(16384, Pcm::toInt16(0.5));
        self::assertSame(32767, Pcm::toInt16(1.5));
        self::assertSame(-32767, Pcm::toInt16(-1.0));
    }

    public function testPackSampleTruncatesToLittleEndian(): void
    {
        self::assertSame(pack('v', 16383), Pcm::packSample(0.5));
        self::assertSame(pack('v', -32767 & 0xFFFF), Pcm::packSample(-2.0));
    }

    public function testUnitHelpers(): void
    {
        self::assertEqualsWithDelta(440.0, Pcm::hz(69.0), 1e-9);
        self::assertEqualsWithDelta(261.6256, Pcm::hz(60.0), 1e-4);
        self::assertEqualsWithDelta(0.5011872, Pcm::db(-6.0), 1e-6);
        $a = Pcm::lpCoeff(1000.0);
        self::assertGreaterThan(0.0, $a);
        self::assertLessThan(1.0, $a);
        self::assertGreaterThan($a, Pcm::lpCoeff(1000.0, 22050), 'a lower rate needs a larger step');
    }

    public function testWavWriterHeaderDescribesTheData(): void
    {
        $wav = WavWriter::wrap(str_repeat("\x00\x00", 10), 1, 48000);
        $fmt = unpack('vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbits', substr($wav, 20, 16));

        self::assertSame('RIFF', substr($wav, 0, 4));
        self::assertSame(44 + 20, strlen($wav));
        self::assertIsArray($fmt);
        self::assertSame(['format' => 1, 'channels' => 1, 'sampleRate' => 48000, 'byteRate' => 96000, 'blockAlign' => 2, 'bits' => 16], $fmt);
    }
}
