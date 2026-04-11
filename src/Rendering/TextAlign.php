<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Backend-agnostic text alignment constants.
 *
 * Horizontal and vertical flags can be combined with bitwise OR:
 *   TextAlign::CENTER | TextAlign::MIDDLE
 *
 * Values are intentionally identical to GL\VectorGraphics\VGAlign
 * so that the GL backend can pass them through directly.
 */
final class TextAlign
{
    // Horizontal alignment
    public const LEFT   = 1;
    public const CENTER = 2;
    public const RIGHT  = 4;

    // Vertical alignment
    public const TOP      = 8;
    public const MIDDLE   = 16;
    public const BOTTOM   = 32;
    public const BASELINE = 64;

    /** Default alignment: left + top */
    public const DEFAULT = self::LEFT | self::TOP;

    private function __construct() {}
}
