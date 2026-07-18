<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Transpiler;

use PHPolygon\ECS\ComponentInterface;
use PHPolygon\Scene\EntityDeclaration;

/**
 * Applies per-instance override components on top of the baseline components a
 * prefab's build() produced. "By class" means: an override replaces an existing
 * component of the same concrete class, or is appended when the baseline has
 * none — so an authored value wins without ever duplicating the component.
 *
 * This is the runtime half of the prefab reference+override model. The diffing
 * half (computing a minimal override set against a fresh build() baseline for
 * serialization) is added later alongside the transpiler round-trip.
 */
final class ComponentOverrides
{
    /**
     * @param list<ComponentInterface> $overrides
     */
    public static function applyByClass(EntityDeclaration $target, array $overrides): void
    {
        foreach ($overrides as $override) {
            $target->withOverride($override);
        }
    }
}
