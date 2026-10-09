<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/** What {@see NativeUpscalerResolver} found for one build target. */
final class NativeUpscalerBundle
{
    /**
     * @param list<string> $files runtime libraries to put next to the executable
     * @param array<string, string> $ini settings for the embedded php.ini
     * @param list<string> $components shipped upscalers (`fsr`, `dlss`)
     */
    public function __construct(
        public readonly array $files = [],
        public readonly array $ini = [],
        public readonly array $components = [],
    ) {}

    /** THIRD-PARTY-NOTICES.txt for the shipped upscalers, '' when none. */
    public function notices(string $eol = "\n"): string
    {
        return ThirdPartyNotices::render($this->components, $eol);
    }
}
