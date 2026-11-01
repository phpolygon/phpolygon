<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * A game effect's place in {@see AdaptiveTierStack}: under pressure the effect
 * `$name` is switched off when the stack reaches `$slot`, and on again in reverse.
 * It works on {@see \PHPolygon\Rendering\GraphicsSettings::effectEnabled()}.
 */
final class AdaptiveStep
{
    public function __construct(
        public readonly string $name,
        public readonly AdaptiveSlot $slot,
        public readonly bool $default = true,
    ) {}
}
