<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * One spoken line in one locale, as {@see SpeechSync::plan()} found it.
 */
final readonly class SpeechLine
{
    /**
     * @param string|null $language the synthesizer's language id, null = not spoken
     * @param string $cacheKey empty unless the line can be generated
     */
    public function __construct(
        public string $key,
        public string $locale,
        public string $text,
        public ?SpeechVoice $voice,
        public ?string $language,
        public string $cacheKey,
        public string $path,
        public SpeechStatus $status,
    ) {}
}
