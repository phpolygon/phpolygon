<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * One hair guide curve: a polyline from the root on the scalp to the tip, plus the scalp
 * normal at the root. The input of {@see HairCardMesh}; produced by {@see HairGuides}.
 */
final readonly class HairGuide
{
    /**
     * @param list<array{0: float, 1: float, 2: float}> $points   Root first, at least two points.
     * @param array{0: float, 1: float, 2: float}       $rootNormal Unit scalp normal at the root; the cards lie tangent to it.
     */
    public function __construct(
        public array $points,
        public array $rootNormal = [0.0, 1.0, 0.0],
    ) {}
}
