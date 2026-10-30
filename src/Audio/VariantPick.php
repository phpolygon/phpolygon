<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

/**
 * One draw from a {@see VariantBag}: which variant to play, and the factors to
 * multiply into its playback pitch and volume.
 */
final class VariantPick
{
    public function __construct(
        public readonly string $id,
        public readonly float $pitch,
        public readonly float $gain,
    ) {}
}
