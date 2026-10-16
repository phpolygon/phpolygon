<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

use PHPolygon\Audio\Dsp\Voice\VoiceProfile;

/**
 * One voice of build.json `speech.voices`: a reference recording the synthesizer
 * clones, the line keys it speaks and how it is rendered.
 */
final readonly class SpeechVoice
{
    /**
     * @param string|null $reference project-relative WAV the voice is cloned from; null = the synthesizer's built-in voice
     * @param list<string> $keys fnmatch patterns of the line keys this voice speaks; [] = every line no other voice claims
     * @param VoiceProfile|null $profile post-processing through {@see \PHPolygon\Audio\Dsp\Voice\VoiceChain}; null = loudness only
     */
    public function __construct(
        public string $name,
        public ?string $reference,
        public array $keys = [],
        public int $seed = 0,
        public float $exaggeration = 0.5,
        public float $cfgWeight = 0.5,
        public ?VoiceProfile $profile = null,
    ) {}

    public function claims(string $key): bool
    {
        foreach ($this->keys as $pattern) {
            if (fnmatch($pattern, $key)) {
                return true;
            }
        }
        return false;
    }

    /** The voice speaks with the synthesizer's built-in voice instead of a clone. */
    public function isBuiltin(): bool
    {
        return $this->reference === null;
    }

    /** Everything about the voice that changes its sound, as part of a line's cache key. */
    public function fingerprint(string $referenceHash): string
    {
        return hash('sha256', (string) json_encode([
            $referenceHash,
            $this->seed,
            $this->exaggeration,
            $this->cfgWeight,
            $this->profile?->fingerprint(),
        ], JSON_PRESERVE_ZERO_FRACTION));
    }
}
