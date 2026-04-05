<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

/**
 * Override the active shader for all subsequent draw commands in this frame.
 * Pass null to reset to material-driven shader selection.
 *
 * Useful for performance testing (e.g. switching to a flat/unlit shader)
 * or global effects (e.g. wireframe, depth-only).
 */
readonly class SetShader
{
    public function __construct(
        public ?string $shaderId,
    ) {}
}
