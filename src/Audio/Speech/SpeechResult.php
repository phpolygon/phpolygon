<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

final readonly class SpeechResult
{
    /**
     * @param list<SpeechLine> $generated
     * @param list<array{line: SpeechLine, error: string}> $failed
     */
    public function __construct(
        public array $generated = [],
        public array $failed = [],
    ) {}
}
