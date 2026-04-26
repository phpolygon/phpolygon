<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\TextAlign;

/**
 * Renders text at a 3D world position.
 *
 * The WorldTextSystem projects the entity's Transform3D position
 * to screen space and draws the text via the 2D renderer.
 * Optionally billboard-faces the camera.
 */
#[Serializable]
#[Category('Rendering')]
class WorldText extends AbstractComponent
{
    #[Property]
    public string $text;

    #[Property(editorHint: 'slider')]
    #[Range(min: 4, max: 128)]
    public float $fontSize;

    #[Property(editorHint: 'color')]
    public Color $color;

    #[Property]
    public string $fontId;

    #[Property]
    public int $textAlign;

    /** Maximum render distance from camera. 0 = unlimited. */
    #[Property]
    public float $maxDistance;

    /** Whether to scale text based on distance (perspective feel). */
    #[Property]
    public bool $scaleWithDistance;

    /** Y offset in screen pixels above the projected position. */
    #[Property]
    public float $screenOffsetY;

    public function __construct(
        string $text = '',
        float $fontSize = 16.0,
        ?Color $color = null,
        string $fontId = '',
        int $textAlign = TextAlign::CENTER | TextAlign::BOTTOM,
        float $maxDistance = 0.0,
        bool $scaleWithDistance = true,
        float $screenOffsetY = 0.0,
    ) {
        $this->text = $text;
        $this->fontSize = $fontSize;
        $this->color = $color ?? Color::white();
        $this->fontId = $fontId;
        $this->textAlign = $textAlign;
        $this->maxDistance = $maxDistance;
        $this->scaleWithDistance = $scaleWithDistance;
        $this->screenOffsetY = $screenOffsetY;
    }
}
