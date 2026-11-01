<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

/**
 * Value of a uniform declared with ProcModeShaderRegistry::declareUniform(). Sticky:
 * it stays until the next command names it, so a system updating at a lower rate
 * than the frame rate leaves no gap. A name no program declares is ignored.
 */
readonly class SetShaderParameter
{
    /**
     * @param int|float|list<float> $value int for an int uniform, a flat list for vectors and mat4
     */
    public function __construct(
        public string $name,
        public int|float|array $value,
    ) {}
}
