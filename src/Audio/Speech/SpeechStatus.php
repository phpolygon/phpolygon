<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

enum SpeechStatus: string
{
    /** The file exists and matches text, voice and synthesizer. */
    case Current = 'current';
    /** Not generated yet. */
    case Missing = 'missing';
    /** Generated, but text, voice or synthesizer changed since. */
    case Stale = 'stale';
    /** The synthesizer does not speak this locale. */
    case Unsupported = 'unsupported';
    /** Placeholders (`:name`) or plural forms (`a|b`) – the text is only known at runtime. */
    case Unspeakable = 'unspeakable';
    /** No voice claims the key and there is no fallback voice. */
    case NoVoice = 'no-voice';

    /** True when a sync would (re)generate the line. */
    public function needsSynthesis(): bool
    {
        return $this === self::Missing || $this === self::Stale;
    }
}
