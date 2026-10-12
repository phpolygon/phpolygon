<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

/**
 * How one speaker sounds: the settings {@see VoiceChain} applies to a base
 * recording.
 *
 *  - pitch    factor on the fundamental (1.1 = 10 % higher), formants stay
 *  - formant  factor on the vocal-tract resonances (>1 = smaller, brighter), pitch stays
 *  - tempo    speaking rate (1.1 = 10 % faster, shorter clip)
 *  - tiltLowDb / tiltHighDb  low shelf (250 Hz) / high shelf (3 kHz) gain
 *  - breath   0–1, breathy noise that follows the voice's envelope
 *  - vocoderHz / vocoderDry  optional robot timbre ({@see Vocoder}); null = none
 *  - crush    0–1, broken-machine damage: fewer bits, a lower sample rate and
 *             short dropouts ({@see Crusher})
 *
 * A plain value object: named voices (presets, per-speaker variation) belong
 * to the game that uses it. {@see fingerprint()} hashes the fields in their
 * declared order – adding or reordering fields changes every fingerprint.
 */
final readonly class VoiceProfile
{
    public function __construct(
        public float $pitch = 1.0,
        public float $formant = 1.0,
        public float $tempo = 1.0,
        public float $tiltLowDb = 0.0,
        public float $tiltHighDb = 0.0,
        public float $breath = 0.0,
        public ?float $vocoderHz = null,
        public float $vocoderDry = Vocoder::DEFAULT_DRY,
        public float $crush = 0.0,
    ) {
        self::range('pitch', $pitch, 0.5, 2.0);
        self::range('formant', $formant, 0.7, 1.4);
        self::range('tempo', $tempo, 0.5, 2.0);
        self::range('tiltLowDb', $tiltLowDb, -12.0, 12.0);
        self::range('tiltHighDb', $tiltHighDb, -12.0, 12.0);
        self::range('breath', $breath, 0.0, 1.0);
        if ($vocoderHz !== null) {
            self::range('vocoderHz', $vocoderHz, 40.0, 1000.0);
        }
        self::range('vocoderDry', $vocoderDry, 0.0, 1.0);
        self::range('crush', $crush, 0.0, 1.0);
    }

    /**
     * A copy with some fields replaced, e.g. a hand-tuned variant:
     * `$profile->withOverrides(['pitch' => 0.9])`.
     *
     * @param array<string, float|int|null> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        $fields = [
            'pitch' => $this->pitch,
            'formant' => $this->formant,
            'tempo' => $this->tempo,
            'tiltLowDb' => $this->tiltLowDb,
            'tiltHighDb' => $this->tiltHighDb,
            'breath' => $this->breath,
            'vocoderHz' => $this->vocoderHz,
            'vocoderDry' => $this->vocoderDry,
            'crush' => $this->crush,
        ];
        foreach ($overrides as $key => $value) {
            if (!array_key_exists($key, $fields)) {
                throw new \InvalidArgumentException("unknown voice profile field '$key'");
            }
            $fields[$key] = $value === null ? null : (float) $value;
        }
        $num = static function (string $key) use ($fields): float {
            return $fields[$key] ?? throw new \InvalidArgumentException("voice profile field '$key' cannot be null");
        };
        return new self(
            $num('pitch'),
            $num('formant'),
            $num('tempo'),
            $num('tiltLowDb'),
            $num('tiltHighDb'),
            $num('breath'),
            $fields['vocoderHz'] ?? null,
            $num('vocoderDry'),
            $num('crush'),
        );
    }

    /** True when the shaper would leave the recording untouched (pitch/formant/tempo 1, no tilt, no breath). */
    public function shapesNothing(): bool
    {
        return $this->pitch === 1.0 && $this->formant === 1.0 && $this->tempo === 1.0
            && $this->tiltLowDb === 0.0 && $this->tiltHighDb === 0.0 && $this->breath === 0.0;
    }

    /** Stable hash of every setting – part of a render cache key. */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode(get_object_vars($this), JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function range(string $name, float $value, float $min, float $max): void
    {
        if (!is_finite($value) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException(sprintf('voice profile %s = %s outside %s–%s', $name, $value, $min, $max));
        }
    }
}
