<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * A 2D renderer that can draw text from signed-distance-field glyph atlases
 * (shader plan 8.1): edges stay sharp when a transform magnifies the text - zoom
 * animations, titles scaled up, UI drawn through a design-grid scale.
 *
 * Optional capability next to {@see Renderer2DInterface}: check with instanceof.
 */
interface SdfFontRenderer2DInterface
{
    /**
     * Register a font by name like {@see Renderer2DInterface::loadFont()}, with
     * distance-field atlases where the backend supports them (else coverage, as
     * loadFont gives).
     */
    public function loadSdfFont(string $name, string $path): void;
}
