<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * One synthesis request: speak $text in $language with the voice cloned from
 * $reference (null = the model's built-in voice) and write a WAV to $wavPath.
 * Paths are absolute. $params = the voice's fine controls; null = model defaults.
 */
final readonly class SpeechJob
{
    public function __construct(
        public string $id,
        public string $text,
        public string $language,
        public ?string $reference,
        public string $wavPath,
        public int $seed,
        public float $exaggeration,
        public float $cfgWeight,
        public ?SpeechParams $params = null,
    ) {}
}
