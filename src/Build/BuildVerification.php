<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/** Outcome of {@see BuildVerifier}: what held and what did not. */
final readonly class BuildVerification
{
    /**
     * @param list<string> $passed
     * @param list<string> $problems
     */
    public function __construct(
        public string $buildType,
        public array $passed,
        public array $problems,
    ) {}

    public function ok(): bool
    {
        return $this->problems === [];
    }
}
