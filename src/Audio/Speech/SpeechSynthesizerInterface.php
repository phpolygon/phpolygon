<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * Text-to-speech backend for {@see SpeechSync}. Runs offline, never in a game.
 */
interface SpeechSynthesizerInterface
{
    /**
     * Model and version – part of every line's cache key, so changing it
     * regenerates every line.
     */
    public function id(): string;

    /** The synthesizer's language id for a locale tag, null when it cannot speak it. */
    public function language(string $locale): ?string;

    /**
     * Generate every job. Reports each job once, as soon as it is done, so a
     * long run keeps what it finished when it is interrupted.
     *
     * @param list<SpeechJob> $jobs
     * @param string $workDir scratch directory inside the project for job files
     * @param callable(SpeechJob, ?string): void $onResult the job and null, or the error
     * @throws \RuntimeException when the synthesizer cannot run at all
     */
    public function synthesize(array $jobs, string $workDir, callable $onResult): void;
}
